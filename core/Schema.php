<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Structure de la base. Chaque migration est appliquée une seule fois
 * (PRAGMA user_version), dans une transaction.
 */
final class Schema
{
    public static function migrations(): array
    {
        return [
            1 => [
                "CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE,
                    role TEXT NOT NULL DEFAULT 'author',
                    password TEXT NOT NULL DEFAULT '',
                    bio TEXT NOT NULL DEFAULT '',
                    avatar_id INTEGER,
                    links TEXT NOT NULL DEFAULT '[]',
                    lang TEXT NOT NULL DEFAULT '',
                    totp_secret TEXT NOT NULL DEFAULT '',
                    totp_enabled INTEGER NOT NULL DEFAULT 0,
                    recovery_codes TEXT NOT NULL DEFAULT '[]',
                    status TEXT NOT NULL DEFAULT 'active',
                    wp_login TEXT,
                    wp_id INTEGER,
                    created_at INTEGER NOT NULL,
                    last_login_at INTEGER
                )",
                "CREATE TABLE sessions (
                    id TEXT PRIMARY KEY,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    csrf TEXT NOT NULL,
                    pending_2fa INTEGER NOT NULL DEFAULT 0,
                    ip TEXT NOT NULL DEFAULT '',
                    ua TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL,
                    seen_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                )",
                "CREATE TABLE content (
                    id INTEGER PRIMARY KEY,
                    type TEXT NOT NULL DEFAULT 'post',
                    status TEXT NOT NULL DEFAULT 'draft',
                    title TEXT NOT NULL DEFAULT '',
                    slug TEXT NOT NULL DEFAULT '',
                    path TEXT,
                    parent_id INTEGER,
                    author_id INTEGER,
                    excerpt TEXT NOT NULL DEFAULT '',
                    blocks TEXT NOT NULL DEFAULT '[]',
                    template TEXT NOT NULL DEFAULT '',
                    featured_id INTEGER,
                    seo TEXT NOT NULL DEFAULT '{}',
                    lang TEXT NOT NULL DEFAULT '',
                    tgroup TEXT,
                    menu_order INTEGER NOT NULL DEFAULT 0,
                    comments INTEGER NOT NULL DEFAULT 0,
                    sticky INTEGER NOT NULL DEFAULT 0,
                    word_count INTEGER NOT NULL DEFAULT 0,
                    seo_score INTEGER,
                    geo_score INTEGER,
                    published_at INTEGER,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    wp_id INTEGER
                )",
                "CREATE UNIQUE INDEX content_path ON content(path) WHERE path IS NOT NULL AND status <> 'trash'",
                "CREATE INDEX content_list ON content(type, status, published_at DESC)",
                "CREATE INDEX content_wp ON content(wp_id)",
                "CREATE TABLE revisions (
                    id INTEGER PRIMARY KEY,
                    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
                    user_id INTEGER,
                    data TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )",
                "CREATE INDEX revisions_content ON revisions(content_id, id DESC)",
                "CREATE TABLE terms (
                    id INTEGER PRIMARY KEY,
                    taxonomy TEXT NOT NULL,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL,
                    parent_id INTEGER,
                    description TEXT NOT NULL DEFAULT '',
                    seo TEXT NOT NULL DEFAULT '{}',
                    wp_id INTEGER,
                    UNIQUE(taxonomy, slug)
                )",
                "CREATE TABLE content_terms (
                    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
                    term_id INTEGER NOT NULL REFERENCES terms(id) ON DELETE CASCADE,
                    PRIMARY KEY (content_id, term_id)
                )",
                "CREATE INDEX content_terms_term ON content_terms(term_id)",
                "CREATE TABLE media (
                    id INTEGER PRIMARY KEY,
                    path TEXT NOT NULL UNIQUE,
                    mime TEXT NOT NULL,
                    size INTEGER NOT NULL DEFAULT 0,
                    width INTEGER,
                    height INTEGER,
                    alt TEXT NOT NULL DEFAULT '',
                    title TEXT NOT NULL DEFAULT '',
                    caption TEXT NOT NULL DEFAULT '',
                    variants TEXT NOT NULL DEFAULT '{}',
                    hash TEXT,
                    user_id INTEGER,
                    wp_id INTEGER,
                    source_url TEXT,
                    created_at INTEGER NOT NULL
                )",
                "CREATE INDEX media_hash ON media(hash)",
                "CREATE TABLE comments (
                    id INTEGER PRIMARY KEY,
                    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
                    parent_id INTEGER,
                    user_id INTEGER,
                    author TEXT NOT NULL,
                    email TEXT NOT NULL DEFAULT '',
                    url TEXT NOT NULL DEFAULT '',
                    body TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    ip_hash TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL,
                    wp_id INTEGER
                )",
                "CREATE INDEX comments_content ON comments(content_id, status)",
                "CREATE TABLE menus (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    location TEXT,
                    items TEXT NOT NULL DEFAULT '[]'
                )",
                "CREATE TABLE redirects (
                    id INTEGER PRIMARY KEY,
                    source TEXT NOT NULL UNIQUE,
                    target TEXT NOT NULL,
                    code INTEGER NOT NULL DEFAULT 301,
                    hits INTEGER NOT NULL DEFAULT 0,
                    last_hit_at INTEGER,
                    auto INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )",
                "CREATE TABLE not_found (
                    path TEXT PRIMARY KEY,
                    hits INTEGER NOT NULL DEFAULT 1,
                    referrer TEXT NOT NULL DEFAULT '',
                    first_at INTEGER NOT NULL,
                    last_at INTEGER NOT NULL
                )",
                "CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)",
                "CREATE TABLE activity (
                    id INTEGER PRIMARY KEY,
                    user_id INTEGER,
                    action TEXT NOT NULL,
                    object TEXT NOT NULL DEFAULT '',
                    object_id INTEGER,
                    label TEXT NOT NULL DEFAULT '',
                    ip TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL
                )",
                "CREATE INDEX activity_time ON activity(created_at DESC)",
                "CREATE TABLE throttle (
                    key TEXT PRIMARY KEY,
                    hits INTEGER NOT NULL,
                    window_start INTEGER NOT NULL,
                    locked_until INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE tokens (
                    id TEXT PRIMARY KEY,
                    kind TEXT NOT NULL,
                    user_id INTEGER,
                    client_id TEXT,
                    label TEXT NOT NULL DEFAULT '',
                    data TEXT NOT NULL DEFAULT '{}',
                    expires_at INTEGER NOT NULL,
                    created_at INTEGER NOT NULL,
                    last_used_at INTEGER
                )",
                "CREATE INDEX tokens_kind ON tokens(kind, user_id)",
                "CREATE TABLE oauth_clients (
                    id TEXT PRIMARY KEY,
                    secret TEXT,
                    name TEXT NOT NULL,
                    redirect_uris TEXT NOT NULL,
                    data TEXT NOT NULL DEFAULT '{}',
                    created_at INTEGER NOT NULL
                )",
                "CREATE TABLE stats_pages (
                    day TEXT NOT NULL,
                    path TEXT NOT NULL,
                    views INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (day, path)
                )",
                "CREATE TABLE stats_sources (
                    day TEXT NOT NULL,
                    kind TEXT NOT NULL,
                    source TEXT NOT NULL,
                    views INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (day, kind, source)
                )",
                "CREATE TABLE stats_days (
                    day TEXT PRIMARY KEY,
                    views INTEGER NOT NULL DEFAULT 0,
                    visitors INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE stats_seen (day TEXT NOT NULL, visitor TEXT NOT NULL, PRIMARY KEY (day, visitor))",
                "CREATE TABLE form_entries (
                    id INTEGER PRIMARY KEY,
                    content_id INTEGER,
                    form TEXT NOT NULL DEFAULT '',
                    data TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'new',
                    ip_hash TEXT NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL
                )",
                "CREATE TABLE import_map (
                    source TEXT NOT NULL,
                    kind TEXT NOT NULL,
                    old_id TEXT NOT NULL,
                    new_id INTEGER NOT NULL,
                    PRIMARY KEY (source, kind, old_id)
                )",
                "CREATE TABLE ai_log (
                    id INTEGER PRIMARY KEY,
                    user_id INTEGER,
                    provider TEXT NOT NULL,
                    model TEXT NOT NULL,
                    task TEXT NOT NULL,
                    input_tokens INTEGER NOT NULL DEFAULT 0,
                    output_tokens INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL
                )",
            ],
        ];
    }

    public static function version(): int
    {
        return (int) Db::val('PRAGMA user_version');
    }

    public static function latest(): int
    {
        return max(array_keys(self::migrations()));
    }

    public static function migrate(): void
    {
        $current = self::version();
        foreach (self::migrations() as $v => $statements) {
            if ($v <= $current) {
                continue;
            }
            Db::tx(static function () use ($statements, $v): void {
                foreach ($statements as $sql) {
                    Db::pdo()->exec($sql);
                }
                Db::pdo()->exec('PRAGMA user_version = ' . (int) $v);
            });
        }
        self::ensureSearchIndex();
    }

    /** Index plein texte (FTS5) si SQLite le permet ; sinon recherche LIKE. */
    public static function ensureSearchIndex(): void
    {
        try {
            Db::pdo()->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search USING fts5(title, body, content_id UNINDEXED, tokenize = 'unicode61 remove_diacritics 2')");
        } catch (\Throwable) {
            // FTS5 indisponible : Search::query() utilisera LIKE.
        }
    }
}
