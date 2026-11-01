#!/usr/bin/env bash
# Smoke tests for the running fixture on http://127.0.0.1:8081 (start it with start.sh first).
# Checks pages, permalinks, REST (anonymous + application password), media, redirects, and that
# crawling the site does not modify the content tables (content fingerprint).
# Exit code 0 = everything passed.
set -uo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
URL="http://127.0.0.1:8081"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FAILED=0

json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $e = $argv[2]; echo eval("return $e;");' "$1" "$2"; }
APP_PW="$(json "$BASE/fixture.json" '$d["credentials"]["application_password"]["password"]')"
EXPECTED_FP="$(json "$BASE/fixture.json" '$d["content_fingerprint"]["sha256"]')"
EXPECTED_PUBLIC_POSTS="$(json "$BASE/fixture.json" '$d["rest"]["expected"]["anonymous"]["posts"]["total"]')"
EXPECTED_PAGES="$(json "$BASE/fixture.json" '$d["rest"]["expected"]["anonymous"]["pages"]["total"]')"
EXPECTED_ALL_POSTS="$(json "$BASE/fixture.json" '$d["rest"]["expected"]["admin"]["posts_any"]["total"]')"

pass() { printf 'PASS  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILED=1; }

# fetch NAME URL [curl args...] -> sets CODE, writes $TMP/NAME.body and $TMP/NAME.headers
fetch() {
	local name="$1" url="$2"
	shift 2
	CODE="$(curl -s --noproxy '*' -o "$TMP/$name.body" -D "$TMP/$name.headers" -w '%{http_code}' "$@" "$url")"
}
header() { grep -i "^$2:" "$TMP/$1.headers" | head -n1 | cut -d' ' -f2- | tr -d '\r'; }

# 0. Database matches the documented state.
FP_BEFORE="$(php "$BASE/inventory.php" --fingerprint)"
[ "$FP_BEFORE" = "$EXPECTED_FP" ] && pass "content fingerprint matches fixture.json (${FP_BEFORE:0:16}...)" || fail "content fingerprint $FP_BEFORE differs from fixture.json $EXPECTED_FP"

# 1. Front end.
fetch home "$URL/"
[ "$CODE" = 200 ] && grep -q 'Céramique artisanale à Lyon' "$TMP/home.body" && grep -q 'Atelier Lumière' "$TMP/home.body" \
	&& pass "GET / -> 200, static front page 'Accueil'" || fail "GET / -> $CODE"
fetch post "$URL/secrets-emaillage-guide-complet/"
[ "$CODE" = 200 ] && grep -q 'Températures de cuisson' "$TMP/post.body" && grep -q 'wp-block-table' "$TMP/post.body" \
	&& pass "GET /secrets-emaillage-guide-complet/ -> 200 (Gutenberg post)" || fail "post permalink -> $CODE"
fetch classic "$URL/cuisson-raku-feu-et-fumee/"
[ "$CODE" = 200 ] && grep -q 'wp-caption' "$TMP/classic.body" && grep -q '\[contact-form-7 id=' "$TMP/classic.body" \
	&& pass "GET /cuisson-raku-feu-et-fumee/ -> 200 (classic post: [caption] rendered, unknown shortcode printed)" || fail "classic post -> $CODE"
fetch child "$URL/a-propos/tarifs/"
[ "$CODE" = 200 ] && pass "GET /a-propos/tarifs/ -> 200 (child page)" || fail "child page -> $CODE"
fetch blog "$URL/blog/"
[ "$CODE" = 200 ] && grep -q 'notre-nouvel-atelier-croix-rousse' "$TMP/blog.body" && pass "GET /blog/ -> 200 (posts page)" || fail "posts page -> $CODE"
fetch oldslug "$URL/guide-emaillage/"
[ "$CODE" = 301 ] && [[ "$(header oldslug location)" == */secrets-emaillage-guide-complet/ ]] && pass "GET /guide-emaillage/ -> 301 (old slug redirect)" || fail "old slug -> $CODE"
fetch img "$URL/wp-content/uploads/2026/02/panorama-atelier-croix-rousse-300x200.jpg"
[ "$CODE" = 200 ] && [[ "$(header img content-type)" == image/jpeg* ]] && pass "GET uploads/...-300x200.jpg -> 200 image/jpeg" || fail "media file -> $CODE"
fetch db "$URL/wp-content/database/.ht.sqlite"
[ "$CODE" = 403 ] && pass "GET database file -> 403" || fail "database file is reachable ($CODE)"

# 2. REST API, anonymous.
fetch posts "$URL/wp-json/wp/v2/posts?_embed&per_page=100"
if [ "$CODE" = 200 ] && [[ "$(header posts content-type)" == application/json* ]]; then
	n="$(json "$TMP/posts.body" 'count($d)')"
	emb="$(json "$TMP/posts.body" 'count(array_filter($d, fn($p) => isset($p["_embedded"]["author"][0]["name"])))')"
	[ "$n" = "$EXPECTED_PUBLIC_POSTS" ] && [ "$(header posts x-wp-total)" = "$EXPECTED_PUBLIC_POSTS" ] && [ "$emb" = "$n" ] \
		&& pass "GET /wp-json/wp/v2/posts?_embed -> 200 JSON, $n posts, _embedded authors present" || fail "posts?_embed returned $n posts (expected $EXPECTED_PUBLIC_POSTS), embedded authors $emb"
else
	fail "GET /wp-json/wp/v2/posts?_embed -> $CODE"
fi
fetch pages "$URL/wp-json/wp/v2/pages?per_page=100"
[ "$CODE" = 200 ] && [ "$(json "$TMP/pages.body" 'count($d)')" = "$EXPECTED_PAGES" ] && pass "GET /wp-json/wp/v2/pages -> 200 JSON, $EXPECTED_PAGES pages" || fail "pages -> $CODE"
fetch editanon "$URL/wp-json/wp/v2/posts?context=edit"
[ "$CODE" = 401 ] && pass "GET posts?context=edit anonymously -> 401" || fail "anonymous context=edit -> $CODE"

# 3. REST API with the application password.
fetch me "$URL/wp-json/wp/v2/users/me?context=edit" -u "admin:$APP_PW"
[ "$CODE" = 200 ] && [ "$(json "$TMP/me.body" '$d["slug"]')" = admin ] && pass "GET users/me with application password -> 200 (admin)" || fail "application password auth -> $CODE"
fetch all "$URL/wp-json/wp/v2/posts?context=edit&status=any&per_page=100" -u "admin:$APP_PW"
if [ "$CODE" = 200 ]; then
	n="$(json "$TMP/all.body" 'count($d)')"
	raw="$(json "$TMP/all.body" 'count(array_filter($d, fn($p) => isset($p["content"]["raw"])))')"
	statuses="$(json "$TMP/all.body" 'implode(",", array_unique(array_column($d, "status")))')"
	[ "$n" = "$EXPECTED_ALL_POSTS" ] && [ "$raw" = "$n" ] && pass "GET posts?context=edit&status=any (app password) -> $n posts with content.raw, statuses: $statuses" || fail "authenticated posts: $n (expected $EXPECTED_ALL_POSTS), raw $raw"
else
	fail "authenticated posts?context=edit -> $CODE"
fi
fetch bad "$URL/wp-json/wp/v2/users/me" -u "admin:wrong wrong wrong wrong wrong wrong"
[ "$CODE" = 401 ] && pass "wrong application password -> 401" || fail "wrong application password -> $CODE"

# 4. Crawling must not have changed the content.
FP_AFTER="$(php "$BASE/inventory.php" --fingerprint)"
[ "$FP_AFTER" = "$FP_BEFORE" ] && pass "content unchanged after crawl" || fail "content tables changed during the crawl"

if [ -s "$BASE/debug.log" ] && grep -q 'PHP Fatal' "$BASE/debug.log"; then
	fail "PHP fatal errors in $BASE/debug.log"
fi

[ "$FAILED" = 0 ] && echo "All checks passed." || echo "Some checks FAILED."
exit "$FAILED"
