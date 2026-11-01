<?php
/**
 * Test de bout en bout de la migration WordPress -> Wordless.
 *
 *   php tests/migrate_test.php
 *
 * Prérequis : le site WordPress de test (WordPress 7.1, SQLite) décrit dans
 * tests/wp-fixture/FIXTURE.md, servi sur http://127.0.0.1:8081 (relancé au besoin
 * avec tests/wp-fixture/start.sh ; site à construire une fois avec tests/wp-fixture/build.sh). Variables facultatives : WL_WP_FIXTURE (dossier
 * du site de test), WL_KEEP=1 (garder les copies temporaires), WL_SKIP_ANON=1.
 *
 * Le script installe des copies neuves de Wordless dans des dossiers
 * temporaires, les sert avec « php -S » (ports 8095 et suivants), importe le
 * site par le fichier d'export (WXR) puis par l'API REST (mot de passe
 * d'application), et vérifie le résultat par rapport à l'inventaire du site de
 * test (fixture.json). Chaque appel de step() s'exécute dans un processus PHP
 * distinct (comme une requête AJAX), avec un gestionnaire d'erreurs strict.
 * Code de sortie 1 si une vérification échoue.
 */
declare(strict_types=1);

const WP_URL = 'http://127.0.0.1:8081';
$ROOT = dirname(__DIR__);
$FIXTURE_DIR = rtrim((string) (getenv('WL_WP_FIXTURE') ?: (is_file('/tmp/wp-test/start.sh') ? '/tmp/wp-test' : __DIR__ . '/wp-fixture')), '/');
$KEEP = getenv('WL_KEEP') === '1';

$failures = 0;
$checks = 0;
$tmpRoots = [];
$servers = [];

function out(string $s): void
{
    echo $s . PHP_EOL;
}

function check(bool $ok, string $label, mixed $detail = null): bool
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   $label" . PHP_EOL;
        return true;
    }
    $failures++;
    echo "  FAIL $label" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '') . PHP_EOL;
    return false;
}

function section(string $t): void
{
    echo PHP_EOL . '== ' . $t . ' ==' . PHP_EOL;
}

/** Supprime un dossier temporaire créé par ce script (jamais en dehors du dossier temporaire système). */
function rrmdir(string $dir): void
{
    $tmp = realpath(sys_get_temp_dir()) ?: '/tmp';
    $real = realpath($dir);
    if ($real === false || !str_starts_with($real, $tmp . '/wlmigtest-')) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

register_shutdown_function(static function () use (&$servers, &$tmpRoots, $KEEP): void {
    foreach ($servers as $s) {
        @proc_terminate($s, 15);
    }
    if (!$KEEP) {
        foreach ($tmpRoots as $d) {
            rrmdir($d);
        }
    } elseif ($tmpRoots) {
        echo 'Copies conservées : ' . implode(', ', $tmpRoots) . PHP_EOL;
    }
});

/** Requête HTTP sans suivre les redirections ni passer par un proxy. */
function http(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => $respHeaders, 'body' => is_string($body) ? $body : ''];
}

function portFree(int $port): bool
{
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($s) {
        fclose($s);
        return false;
    }
    return true;
}

/** Copie neuve de Wordless, installée et servie par php -S. */
function install(string $name, string $email, int $port): array
{
    global $ROOT, $tmpRoots, $servers;
    while (!portFree($port)) {
        $port++;
    }
    $base = sys_get_temp_dir() . '/wlmigtest-' . $name . '-' . bin2hex(random_bytes(4));
    mkdir($base, 0775, true);
    $tmpRoots[] = $base;
    $dir = $base . '/site';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($dir);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($ROOT) + 1);
        if (preg_match('#^(\.git|data/|media/|tests/)#', $rel . ($f->isDir() ? '/' : ''))) {
            continue;
        }
        $target = $dir . '/' . $rel;
        $f->isDir() ? @mkdir($target, 0775, true) : copy($f->getPathname(), $target);
    }
    @mkdir($dir . '/data', 0775, true);
    @mkdir($dir . '/media', 0775, true);
    $url = 'http://127.0.0.1:' . $port;
    $res = run(['php', 'bin/wordless', 'install', '--url=' . $url, '--email=' . $email, '--password=Kz7#pLm2-vRt9!q', '--site=Mon site'], $dir);
    if ($res['code'] !== 0) {
        throw new RuntimeException('Installation impossible : ' . $res['out'] . $res['err']);
    }
    $log = fopen($base . '/server.log', 'ab');
    $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, 'router.php'], [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $dir,
        ['PHP_CLI_SERVER_WORKERS' => '2', 'PATH' => (string) getenv('PATH')]);
    $servers[] = $proc;
    for ($i = 0; $i < 50 && portFree($port); $i++) {
        usleep(100000);
    }
    return ['name' => $name, 'dir' => $dir, 'base' => $base, 'url' => $url, 'port' => $port];
}

/** Lance une commande (sans shell), renvoie code et sorties. */
function run(array $cmd, string $cwd, array $env = []): array
{
    $env += ['PATH' => (string) getenv('PATH'), 'WL_ALLOW_PRIVATE_FETCH' => '1', 'HOME' => (string) getenv('HOME')];
    foreach (['https_proxy', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY', 'SSL_CERT_FILE', 'CURL_CA_BUNDLE'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = (string) getenv($k);
        }
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => (string) $out, 'err' => (string) $err];
}

/**
 * Exécute du code PHP dans le contexte d'une copie Wordless (processus distinct,
 * erreurs PHP transformées en exceptions comme dans l'application web).
 * Le code doit afficher du JSON.
 */
function wl(array $site, string $code): mixed
{
    $script = $site['base'] . '/probe-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($script, "<?php\ndeclare(strict_types=1);\ndefine('WL_ROOT', " . var_export($site['dir'], true) . ");\nrequire WL_ROOT . '/core/bootstrap.php';\n"
        . "set_error_handler(static function (int \$no, string \$msg, string \$file, int \$line): bool { if (!(error_reporting() & \$no)) { return false; } throw new ErrorException(\$msg, 0, \$no, \$file, \$line); });\n"
        . "Wordless\\App::boot();\n" . $code . "\n");
    $res = run(['php', $script], $site['dir']);
    @unlink($script);
    $data = json_decode(trim($res['out']), true);
    if ($res['code'] !== 0 || ($data === null && trim($res['out']) !== 'null')) {
        throw new RuntimeException("Erreur PHP dans {$site['name']} : " . trim($res['err'] . "\n" . $res['out']));
    }
    return $data;
}

function q(array $site, string $sql, array $params = []): array
{
    return wl($site, 'echo json_encode(Wordless\Db::all(' . var_export($sql, true) . ', ' . var_export($params, true) . '), JSON_UNESCAPED_UNICODE);');
}

function val(array $site, string $sql, array $params = []): mixed
{
    $rows = q($site, $sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}

/** Lance une migration complète : create() puis step() dans un nouveau processus à chaque fois. */
function migrate(array $site, array $source, array $options = [], int $budget = 1): array
{
    $state = wl($site, 'echo json_encode(Wordless\Migrate\Importer::create(' . var_export($source, true) . ', ' . var_export($options, true) . '), JSON_UNESCAPED_UNICODE);');
    $steps = 0;
    while (empty($state['finished']) && $steps < 400) {
        $state = wl($site, 'echo json_encode(Wordless\Migrate\Importer::step(' . var_export($state['id'], true) . ', ' . $budget . '), JSON_UNESCAPED_UNICODE);');
        $steps++;
    }
    $state['_steps'] = $steps;
    return $state;
}

function rowCounts(array $site): array
{
    $tables = ['content', 'media', 'terms', 'users', 'comments', 'menus', 'redirects', 'import_map'];
    $sql = 'SELECT ' . implode(', ', array_map(static fn ($t) => "(SELECT COUNT(*) FROM $t) AS $t", $tables));
    return array_map('intval', q($site, $sql)[0]);
}

function contentByWp(array $site, int $wpId): ?array
{
    return wl($site, '$r = Wordless\Db::one("SELECT * FROM content WHERE wp_id = ? AND status <> \'trash\'", [' . $wpId . ']); echo json_encode($r ? Wordless\Content::decode($r) : null, JSON_UNESCAPED_UNICODE);');
}

function blocksOf(?array $c, string $type): array
{
    return array_values(array_filter((array) ($c['blocks'] ?? []), static fn ($b) => $b['type'] === $type));
}

/** WXR synthétique : cas limites (WordPress dans /blog, slugs accentués, Rank Math, Divi, type personnalisé…). */
function edgeWxr(string $home, string $site): string
{
    $cdata = static fn (string $s): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';
    $item = static function (array $i) use ($cdata): string {
        $meta = '';
        foreach ($i['meta'] ?? [] as $k => $v) {
            $meta .= "<wp:postmeta><wp:meta_key>{$cdata($k)}</wp:meta_key><wp:meta_value>{$cdata($v)}</wp:meta_value></wp:postmeta>\n";
        }
        $cats = '';
        foreach ($i['cats'] ?? [] as [$dom, $slug, $name]) {
            $cats .= "<category domain=\"$dom\" nicename=\"$slug\">{$cdata($name)}</category>\n";
        }
        $comments = '';
        foreach ($i['comments'] ?? [] as $c) {
            $comments .= "<wp:comment><wp:comment_id>{$c[0]}</wp:comment_id><wp:comment_author>{$cdata($c[1])}</wp:comment_author><wp:comment_author_email>x@example.org</wp:comment_author_email><wp:comment_author_IP>192.0.2.1</wp:comment_author_IP><wp:comment_date>2026-01-02 10:00:00</wp:comment_date><wp:comment_date_gmt>2026-01-02 09:00:00</wp:comment_date_gmt><wp:comment_content>{$cdata($c[2])}</wp:comment_content><wp:comment_approved>{$c[3]}</wp:comment_approved><wp:comment_type>comment</wp:comment_type><wp:comment_parent>0</wp:comment_parent><wp:comment_user_id>0</wp:comment_user_id></wp:comment>\n";
        }
        return "<item><title>{$cdata($i['title'])}</title><link>{$i['link']}</link><dc:creator>{$cdata('admin')}</dc:creator><guid isPermaLink=\"false\">{$i['link']}</guid>"
            . "<content:encoded>{$cdata($i['content'] ?? '')}</content:encoded><excerpt:encoded>{$cdata($i['excerpt'] ?? '')}</excerpt:encoded>"
            . "<wp:post_id>{$i['id']}</wp:post_id><wp:post_date>{$cdata($i['date'] ?? '2026-01-10 10:00:00')}</wp:post_date><wp:post_date_gmt>{$cdata($i['gmt'] ?? '2026-01-10 09:00:00')}</wp:post_date_gmt>"
            . "<wp:post_modified_gmt>{$cdata('2026-01-10 09:00:00')}</wp:post_modified_gmt><wp:comment_status>{$cdata('open')}</wp:comment_status><wp:post_name>{$cdata($i['slug'])}</wp:post_name>"
            . "<wp:status>{$cdata($i['status'] ?? 'publish')}</wp:status><wp:post_parent>" . ($i['parent'] ?? 0) . "</wp:post_parent><wp:menu_order>0</wp:menu_order><wp:post_type>{$cdata($i['type'])}</wp:post_type>"
            . "<wp:post_password>{$cdata('')}</wp:post_password><wp:is_sticky>0</wp:is_sticky>"
            . (isset($i['att']) ? "<wp:attachment_url>{$cdata($i['att'])}</wp:attachment_url>" : '') . $cats . $meta . $comments . "</item>\n";
    };
    $items = [
        $item(['id' => 6, 'type' => 'attachment', 'status' => 'inherit', 'title' => 'Bols', 'slug' => 'bols', 'link' => "$home/bols/", 'att' => "$site/wp-content/uploads/2026/03/bols-gres-emailles.jpg",
            'meta' => ['_wp_attached_file' => '2026/03/bols-gres-emailles.jpg', '_wp_attachment_image_alt' => 'Trois bols']]),
        $item(['id' => 100, 'type' => 'post', 'title' => 'Café crème', 'slug' => 'caf%c3%a9-cr%c3%a8me', 'link' => "$home/caf%c3%a9-cr%c3%a8me/",
            'cats' => [['category', 'caf%c3%a9s', 'Cafés']],
            'content' => "<!-- wp:paragraph --><p>Intro\x0B avec un caractère interdit.</p><!-- /wp:paragraph -->\n<!-- wp:rank-math/toc-block {\"title\":\"Sommaire\"} --><div class=\"wp-block-rank-math-toc-block\"><h2>Table des matières</h2><nav><ul><li><a href=\"#q\">Q</a></li></ul></nav></div><!-- /wp:rank-math/toc-block -->\n"
                . "<!-- wp:heading --><h2>FAQ café</h2><!-- /wp:heading -->\n<!-- wp:rank-math/faq-block {\"questions\":[{\"id\":\"a\",\"title\":\"Pourquoi ?\",\"content\":\"Parce que.\",\"visible\":true}]} --><div class=\"wp-block-rank-math-faq-block\"><div class=\"rank-math-faq-item rank-math-list-item\"><h3 class=\"rank-math-question\">Pourquoi ?</h3><div class=\"rank-math-answer\">Parce que <strong>bon</strong>.</div></div><div class=\"rank-math-list-item\"><h3 class=\"rank-math-question\">Comment ?</h3><div class=\"rank-math-answer\">Doucement.</div></div></div><!-- /wp:rank-math/faq-block -->\n"
                . "<!-- wp:paragraph --><p><img src=\"https://i0.wp.com/127.0.0.1:8081/wp-content/uploads/2026/03/bols-gres-emailles.jpg?resize=300%2C300&amp;ssl=1\" alt=\"x\" width=\"300\"> Voir <a href=\"$home/portfolio/projet-x/\">le projet</a>.</p><!-- /wp:paragraph -->\n<!-- wp:kadence/rowlayout --><div class=\"kt-row\"><!-- wp:paragraph --><p>Dans un bloc d'extension.</p><!-- /wp:paragraph --></div><!-- /wp:kadence/rowlayout -->\n<!-- wp:acme/widget {\"x\":1} /-->",
            'meta' => ['_seopress_titles_title' => '%%post_title%% %%sep%% %%sitetitle%%', '_seopress_titles_desc' => 'Desc SEOPress', '_seopress_robots_index' => 'yes', '_seopress_analysis_target_kw' => 'café,crème'],
            'comments' => [[201, 'Spammeur', 'Achetez !', 'spam'], [202, 'Corbeille', 'Supprimé', 'trash'], [203, 'Ok', "Bien\n\nVraiment", '1']]]),
        $item(['id' => 101, 'type' => 'post', 'title' => 'Divi', 'slug' => 'divi', 'link' => "$home/divi/", 'date' => '2026-07-10 10:00:00', 'gmt' => '2026-07-10 08:00:00',
            'content' => "[et_pb_section][et_pb_row][et_pb_column type=\"4_4\"][et_pb_text]<p>Texte Divi</p>[/et_pb_text][et_pb_image src=\"$site/wp-content/uploads/2026/03/bols-gres-emailles.jpg\" alt=\"bols\"][/et_pb_image][/et_pb_column][/et_pb_row][/et_pb_section]\n\n[mon_code id=\"3\"]\n\nhttps://vimeo.com/123456\n\n[gallery ids=\"6\"]",
            'meta' => ['_aioseop_title' => 'Titre AIOSEO', '_aioseop_description' => 'Desc AIOSEO']]),
        $item(['id' => 50, 'type' => 'page', 'title' => 'Parent', 'slug' => 'parent', 'link' => "$home/?page_id=50", 'content' => 'Page parente.', 'meta' => ['_genesis_title' => 'TSF', '_genesis_noindex' => '1', '_genesis_description' => 'Desc TSF']]),
        $item(['id' => 51, 'type' => 'page', 'title' => 'Enfant', 'slug' => 'enfant', 'link' => "$home/?page_id=51", 'parent' => 50, 'content' => 'Page enfant.']),
        $item(['id' => 60, 'type' => 'portfolio', 'title' => 'Projet X', 'slug' => 'projet-x', 'link' => "$home/portfolio/projet-x/", 'content' => '<p>Un projet.</p>']),
        $item(['id' => 61, 'type' => 'product', 'title' => 'Bol à vendre', 'slug' => 'bol', 'link' => "$home/produit/bol/", 'content' => 'Produit.']),
        $item(['id' => 62, 'type' => 'post', 'status' => 'trash', 'title' => 'Corbeille', 'slug' => 'corbeille__trashed', 'link' => "$home/?p=62", 'content' => 'x']),
    ];
    // Menu « Liens du pied » : lien court ?p=, 4 niveaux, lien javascript:, page sans libellé.
    $menuItem = static function (int $id, string $title, int $parent, int $order, array $meta) use ($item, $home): string {
        $xml = $item(['id' => $id, 'type' => 'nav_menu_item', 'title' => $title, 'slug' => (string) $id, 'link' => "$home/$id/",
            'cats' => [['nav_menu', 'liens-du-pied', 'Liens du pied']],
            'meta' => $meta + ['_menu_item_menu_item_parent' => (string) $parent, '_menu_item_object_id' => '0', '_menu_item_object' => 'custom', '_menu_item_type' => 'custom']]);
        return str_replace('<wp:menu_order>0</wp:menu_order>', '<wp:menu_order>' . $order . '</wp:menu_order>', $xml);
    };
    $items[] = $menuItem(300, 'Divi', 0, 1, ['_menu_item_url' => "$home/?p=101"]);
    $items[] = $menuItem(301, 'Niveau 1', 0, 2, ['_menu_item_url' => 'https://exemple.org/a']);
    $items[] = $menuItem(302, 'Niveau 2', 301, 3, ['_menu_item_url' => 'https://exemple.org/b']);
    $items[] = $menuItem(303, 'Niveau 3', 302, 4, ['_menu_item_url' => 'https://exemple.org/c']);
    $items[] = $menuItem(304, 'Niveau 4', 303, 5, ['_menu_item_url' => 'https://exemple.org/d']);
    $items[] = $menuItem(305, 'Piège', 0, 6, ['_menu_item_url' => 'javascript:alert(1)']);
    $items[] = $menuItem(306, '', 0, 7, ['_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => '51', '_menu_item_url' => '']);
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        . "<channel><title>Site test</title><link>$home</link><description>Slogan</description><language>fr-FR</language><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>$site</wp:base_site_url><wp:base_blog_url>$home</wp:base_blog_url>\n"
        . "<wp:author><wp:author_id>1</wp:author_id><wp:author_login><![CDATA[admin]]></wp:author_login><wp:author_email><![CDATA[]]></wp:author_email><wp:author_display_name><![CDATA[Jean <b>Admin</b>]]></wp:author_display_name><wp:author_first_name><![CDATA[]]></wp:author_first_name><wp:author_last_name><![CDATA[]]></wp:author_last_name></wp:author>\n"
        . "<wp:category><wp:term_id>3</wp:term_id><wp:category_nicename><![CDATA[caf%c3%a9s]]></wp:category_nicename><wp:category_parent><![CDATA[]]></wp:category_parent><wp:cat_name><![CDATA[Cafés]]></wp:cat_name></wp:category>\n"
        . "<wp:term><wp:term_id>9</wp:term_id><wp:term_taxonomy><![CDATA[product_cat]]></wp:term_taxonomy><wp:term_slug><![CDATA[bols]]></wp:term_slug><wp:term_name><![CDATA[Bols]]></wp:term_name></wp:term>\n"
        . implode('', $items) . "</channel></rss>\n";
}

// ----------------------------------------------------------------------
// Préparation : inventaire du site de test et WordPress en ligne
// ----------------------------------------------------------------------

section('Site WordPress de test');
$fixture = null;
for ($i = 0; $i < 120; $i++) {
    if (is_file($FIXTURE_DIR . '/fixture.json') && is_file($FIXTURE_DIR . '/FIXTURE.md') && is_file($FIXTURE_DIR . '/export.xml')) {
        $fixture = json_decode((string) file_get_contents($FIXTURE_DIR . '/fixture.json'), true);
        if (is_array($fixture) && !empty($fixture['posts'])) {
            break;
        }
    }
    if ($i === 0) {
        out('  … en attente de fixture.json / FIXTURE.md (finalisation en cours)');
    }
    sleep(5);
}
if (!is_array($fixture)) {
    out('  FAIL inventaire introuvable dans ' . $FIXTURE_DIR);
    exit(1);
}
if (http(WP_URL . '/wp-json/')['status'] !== 200 && is_file($FIXTURE_DIR . '/start.sh')) {
    out('  WordPress ne répond pas : redémarrage avec start.sh');
    run(['bash', $FIXTURE_DIR . '/start.sh'], $FIXTURE_DIR);
}
check(http(WP_URL . '/wp-json/')['status'] === 200, 'WordPress de test en ligne (' . WP_URL . ')');
$appPassword = (string) ($fixture['credentials']['application_password']['password'] ?? '');
if ($appPassword === '' && preg_match('/Application password.*?`([^`]+)`/s', (string) file_get_contents($FIXTURE_DIR . '/FIXTURE.md'), $m)) {
    $appPassword = $m[1];
}
$appUser = (string) ($fixture['credentials']['application_password']['user'] ?? 'admin');
check($appPassword !== '', 'mot de passe d’application trouvé dans l’inventaire');

$items = array_merge($fixture['pages'], $fixture['posts']);
$publicItems = array_values(array_filter($items, static fn ($p) => $p['status'] === 'publish' && (string) $p['password'] === ''));
$byKey = [];
foreach ($items as $p) {
    $byKey[$p['id']] = $p;
}
$metaOf = static fn (int $id): array => (array) ($byKey[$id]['seo']['meta'] ?? []);

// ----------------------------------------------------------------------
// Vérifications communes aux deux imports
// ----------------------------------------------------------------------

function commonChecks(array $site, array $state, array $fixture, array $publicItems, string $mode): void
{
    $r = $state['report'];
    check(!$state['error'] && $state['finished'], "[$mode] migration terminée sans erreur", $state['error']);
    check($state['_steps'] >= 2, "[$mode] import en plusieurs étapes ({$state['_steps']} requêtes)");
    foreach (['terms', 'users', 'media', 'content', 'comments', 'menus', 'finalize'] as $p) {
        check(($state['phases'][$p]['status'] ?? '') === 'done', "[$mode] phase « $p » terminée", $state['phases'][$p] ?? null);
    }
    $last = end($state['log']);
    check(is_string($last) && $last !== '' && count($state['log']) <= 50, "[$mode] journal lisible (messages texte)", $last);

    // Compteurs
    check(($r['counts']['pages']['imported'] + $r['counts']['pages']['updated']) === count($fixture['pages']), "[$mode] pages importées : " . count($fixture['pages']), $r['counts']['pages']);
    check((int) val($site, "SELECT COUNT(*) FROM content WHERE type = 'page'") === count($fixture['pages']), "[$mode] aucune page en double");
    check((int) val($site, 'SELECT COUNT(*) FROM media WHERE wp_id IS NOT NULL') >= count($fixture['media']), "[$mode] médias importés : " . count($fixture['media']));
    check((int) val($site, "SELECT COUNT(*) FROM terms WHERE taxonomy = 'category'") === count($fixture['categories']), "[$mode] catégories : " . count($fixture['categories']));
    check((int) val($site, "SELECT COUNT(*) FROM terms WHERE taxonomy = 'tag'") === count($fixture['tags']), "[$mode] étiquettes : " . count($fixture['tags']));
    check((int) val($site, "SELECT COUNT(*) FROM terms WHERE wp_id IS NOT NULL") === count($fixture['categories']) + count($fixture['tags']), "[$mode] terms.wp_id renseigné");
    check((int) val($site, "SELECT COUNT(*) FROM content WHERE path = '/bienvenue-sur-votre-nouveau-site'") === 0, "[$mode] contenu de démonstration remplacé");
    $cat = q($site, "SELECT t.slug, p.slug AS parent FROM terms t LEFT JOIN terms p ON p.id = t.parent_id WHERE t.slug = 'ateliers-enfants'")[0] ?? [];
    check(($cat['parent'] ?? '') === 'ateliers', "[$mode] hiérarchie des catégories conservée", $cat);

    // Adresses identiques
    $bad = [];
    foreach ($publicItems as $p) {
        $res = http($site['url'] . $p['path']);
        if ($res['status'] !== 200) {
            $bad[] = $p['path'] . ' => ' . $res['status'] . ' ' . ($res['headers']['location'] ?? '');
        }
    }
    check(!$bad, "[$mode] les " . count($publicItems) . ' adresses WordPress publiques répondent 200', $bad);
    foreach (['/category/ateliers/ateliers-enfants/', '/category/actualites/', '/tag/lyon/', '/blog/', '/sitemap.xml', '/feed/'] as $path) {
        check(http($site['url'] . $path)['status'] === 200, "[$mode] $path répond 200");
    }
    $front = http($site['url'] . '/');
    check($front['status'] === 200 && str_contains($front['body'], 'Céramique artisanale à Lyon'), "[$mode] page d’accueil statique servie sur /");
    $acc = http($site['url'] . '/accueil/');
    check($acc['status'] === 301 && rtrim(parse_url($acc['headers']['location'] ?? '', PHP_URL_PATH) ?: '', '/') === '', "[$mode] /accueil/ redirige vers /", $acc['headers']['location'] ?? $acc['status']);
    check(str_contains(http($site['url'] . '/blog/')['body'], 'Notre nouvel atelier'), "[$mode] la page Blog liste les articles");
    $u = $r['urls'];
    check($u['total'] === $u['identical'] && $u['total'] >= 14 && $u['redirected'] === 0, "[$mode] rapport : adresses identiques {$u['identical']}/{$u['total']}", $u);

    // Statuts et dates
    $future = contentByWp($site, 26);
    check(($future['status'] ?? '') === 'scheduled' && (int) $future['published_at'] === strtotime('2030-04-06 08:00:00 UTC'), "[$mode] article programmé (2030-04-06 08:00 UTC)", [$future['status'] ?? null, $future['published_at'] ?? null]);
    check((contentByWp($site, 25)['status'] ?? '') === 'draft', "[$mode] brouillon importé en brouillon");
    check((contentByWp($site, 24)['status'] ?? '') === 'private', "[$mode] article privé importé en privé");
    check((contentByWp($site, 23)['status'] ?? '') === 'private', "[$mode] article protégé par mot de passe importé en privé");
    $sticky = contentByWp($site, 16);
    check((int) ($sticky['sticky'] ?? 0) === 1, "[$mode] article épinglé");
    check((int) ($sticky['published_at'] ?? 0) === strtotime('2026-02-10 08:30:00 UTC'), "[$mode] date de publication conservée (GMT)");
    $tarifs = contentByWp($site, 13);
    $apropos = contentByWp($site, 12);
    check($tarifs && $apropos && (int) $tarifs['parent_id'] === (int) $apropos['id'] && $tarifs['path'] === '/a-propos/tarifs', "[$mode] page enfant rattachée à son parent");
    check((int) ($tarifs['menu_order'] ?? -1) === 1, "[$mode] ordre des pages conservé");

    // Images
    foreach ($fixture['media'] as $m) {
        $orig = $m['original_image_url'] !== null && $m['original_image_url'] !== '' ? substr((string) parse_url($m['original_image_url'], PHP_URL_PATH), strlen('/wp-content/uploads/')) : $m['attached_file'];
        $row = q($site, 'SELECT id, path, alt FROM media WHERE wp_id = ?', [(int) $m['id']])[0] ?? null;
        check($row !== null && $row['path'] === $orig && is_file($site['dir'] . '/media/' . $row['path']), "[$mode] média #{$m['id']} conservé sous media/$orig", $row);
        if (!$row) {
            continue;
        }
        check(http($site['url'] . '/media/' . $row['path'])['status'] === 200, "[$mode] /media/$orig répond 200");
        $old = http($site['url'] . '/wp-content/uploads/' . $m['attached_file']);
        check($old['status'] === 301 && str_ends_with($old['headers']['location'] ?? '', '/media/' . $row['path']), "[$mode] /wp-content/uploads/{$m['attached_file']} -> 301 /media/{$row['path']}", [$old['status'], $old['headers']['location'] ?? '']);
        $size = (array) ($m['sizes']['medium'] ?? []);
        if (!empty($size['file'])) {
            $v = http($site['url'] . '/wp-content/uploads/' . $size['file']);
            check($v['status'] === 301 && str_ends_with($v['headers']['location'] ?? '', '/media/' . $row['path']), "[$mode] variante {$size['file']} -> 301 vers l’original");
        }
        check($row['alt'] === $m['alt'], "[$mode] texte alternatif du média #{$m['id']}", $row['alt']);
    }

    // Images à la une
    foreach ($fixture['posts'] as $p) {
        if (empty($p['featured_media'])) {
            continue;
        }
        $c = contentByWp($site, (int) $p['id']);
        $wp = $c && $c['featured_id'] ? (int) val($site, 'SELECT wp_id FROM media WHERE id = ?', [(int) $c['featured_id']]) : 0;
        check($wp === (int) $p['featured_media'], "[$mode] image à la une de #{$p['id']}", $wp);
    }

    // Liens réécrits (aucun lien ni image ne pointe encore vers l'ancien site)
    $leaks = q($site, "SELECT wp_id FROM content WHERE blocks LIKE ? OR blocks LIKE ?", ['%href=\\"http://127.0.0.1:8081%', '%src=\\"http://127.0.0.1:8081%']);
    check(!$leaks, "[$mode] liens et images réécrits vers le nouveau site", $leaks);
    $bols = contentByWp($site, 19);
    $bolsHtml = json_encode($bols['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check(str_contains($bolsHtml, 'href=\"/cuisson-raku-feu-et-fumee/\"') && !str_contains($bolsHtml, '?p=18'), "[$mode] lien ?p=18 remplacé par l’adresse définitive");
    check(count(blocksOf($bols, 'gallery')) === 1 && count(blocksOf($bols, 'gallery')[0]['data']['medias']) === 3, "[$mode] [gallery ids=…] converti en bloc Galerie");

    // Contenu Gutenberg
    $atelier = contentByWp($site, 16);
    $hero = blocksOf($atelier, 'hero')[0]['data'] ?? [];
    check(($hero['layout'] ?? '') === 'cover' && ($hero['title'] ?? '') === 'Bienvenue dans notre nouvel atelier' && !empty($hero['media']), "[$mode] core/cover -> Bannière (image en fond)", $hero);
    check(count(blocksOf($atelier, 'columns')) === 1 && count(blocksOf($atelier, 'columns')[0]['data']['items']) === 2, "[$mode] core/columns -> Colonnes");
    $video = blocksOf($atelier, 'video')[0]['data'] ?? [];
    check(($video['url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', "[$mode] core/embed YouTube -> Vidéo", $video);
    $buttons = blocksOf($atelier, 'button');
    check(count($buttons) === 2 && $buttons[1]['data']['style'] === 'secondary' && $buttons[0]['data']['url'] === '/contact/', "[$mode] core/buttons -> Boutons (style contour conservé)");
    check(count(blocksOf($atelier, 'gallery')) === 1, "[$mode] core/gallery -> Galerie");
    $emaillage = contentByWp($site, 17);
    $img = blocksOf($emaillage, 'image')[0]['data'] ?? [];
    check(!empty($img['media']) && str_starts_with((string) $img['caption'], 'Bols en grès') && $img['link'] === '/media/2026/03/bols-gres-emailles.jpg', "[$mode] core/image -> Image (légende, lien vers le fichier)", $img);
    check(count(blocksOf($emaillage, 'text')) >= 1 && str_contains(json_encode($emaillage['blocks'], JSON_UNESCAPED_UNICODE), 'Recevez notre lettre'), "[$mode] composition synchronisée (core/block) intégrée");

    // FAQ Yoast
    $enfants = contentByWp($site, 20);
    $faq = blocksOf($enfants, 'faq')[0]['data'] ?? [];
    check(count($faq['items'] ?? []) === 4 && ($faq['items'][0]['q'] ?? '') === 'À partir de quel âge mon enfant peut-il participer ?', "[$mode] FAQ Yoast -> bloc FAQ (4 questions)", $faq);
    check(str_contains((string) ($faq['items'][2]['a'] ?? ''), '<strong>trois à quatre semaines</strong>') && str_contains((string) ($faq['items'][3]['a'] ?? ''), 'href="/contact/"'), "[$mode] réponses FAQ : mise en forme et liens conservés");
    check(($faq['title'] ?? '') === 'Questions fréquentes' && !str_contains(json_encode(blocksOf($enfants, 'text'), JSON_UNESCAPED_UNICODE), 'Questions fréquentes'), "[$mode] titre de la FAQ repris sans doublon");
    $page = http($site['url'] . '/atelier-enfants-modeler-argile/');
    check(str_contains($page['body'], '"FAQPage"'), "[$mode] données structurées FAQPage publiées");

    // Classique : [caption], URL YouTube seule, contact-form-7
    $raku = contentByWp($site, 18);
    $rakuJson = json_encode($raku['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check(str_contains($rakuJson, '<figure><img src=\"/media/2026/04/cuisson-raku.jpg\"') && str_contains($rakuJson, '<figcaption>Sortie du four'), "[$mode] [caption] -> figure + légende");
    check(count(blocksOf($raku, 'video')) === 1, "[$mode] URL YouTube seule sur sa ligne -> Vidéo");
    check(count(blocksOf($raku, 'contact')) === 1, "[$mode] [contact-form-7] -> formulaire de contact Wordless");
    $reviewIds = array_column($r['review'], 'id');
    check(in_array((int) $raku['id'], $reviewIds, true), "[$mode] contenu signalé « à revoir » dans le rapport");
    check(str_contains((string) ($raku['excerpt'] ?? ''), 'Sortir une pièce incandescente'), "[$mode] extrait conservé");

    // Commentaires en fil de discussion
    $c2 = q($site, 'SELECT id, parent_id, status, ip_hash, email FROM comments WHERE wp_id = 2')[0] ?? null;
    $c3 = q($site, 'SELECT id, parent_id FROM comments WHERE wp_id = 3')[0] ?? null;
    $c4 = q($site, 'SELECT id, parent_id FROM comments WHERE wp_id = 4')[0] ?? null;
    check($c2 && $c3 && $c4 && (int) $c3['parent_id'] === (int) $c2['id'] && (int) $c4['parent_id'] === (int) $c3['id'], "[$mode] commentaires en fil (3 niveaux)");
    check($c2 && $c2['status'] === 'approved' && $c2['ip_hash'] !== '' && $c2['ip_hash'] !== '203.0.113.21' && $c2['email'] === 'sophie.bernard@example.org', "[$mode] commentaire approuvé, IP hachée");
    check((string) val($site, 'SELECT status FROM comments WHERE wp_id = 10') === 'pending', "[$mode] commentaire en attente importé en attente");
    check((int) val($site, 'SELECT COUNT(*) FROM comments WHERE wp_id IN (6, 11)') === 0, "[$mode] rétrolien et indésirable non importés");
    check((int) val($site, 'SELECT COUNT(*) FROM comments') === 8, "[$mode] 8 commentaires importés", val($site, 'SELECT COUNT(*) FROM comments'));
    check(str_contains(http($site['url'] . '/secrets-emaillage-guide-complet/')['body'], 'Super, je viens m'), "[$mode] commentaires affichés sur l’article");

    // Menus
    $menus = q($site, 'SELECT id, name, location, items FROM menus ORDER BY id');
    $main = null;
    $foot = null;
    foreach ($menus as $m) {
        if ($m['name'] === 'Menu principal') {
            $main = $m;
        } elseif ($m['name'] === 'Pied de page') {
            $foot = $m;
        }
    }
    $locs = wl($site, 'echo json_encode(Wordless\Settings::get("menus.locations"));');
    check($main !== null && (int) ($locs['primary'] ?? 0) === (int) $main['id'], "[$mode] « Menu principal » en emplacement principal", $locs);
    $mainItems = json_decode((string) ($main['items'] ?? '[]'), true);
    check(count($mainItems) === 6 && array_column($mainItems, 'label') === ['Accueil', 'Nos ateliers', 'À propos', 'Le blog', 'Contact', 'Boutique en ligne'], "[$mode] menu principal : 6 liens de premier niveau", array_column($mainItems, 'label'));
    check(($mainItems[1]['children'][0]['type'] ?? '') === 'term' && ($mainItems[1]['children'][0]['label'] ?? '') === 'Ateliers enfants', "[$mode] sous-menu vers une catégorie");
    check(($mainItems[2]['children'][0]['label'] ?? '') === 'Tarifs' && ($mainItems[2]['children'][0]['type'] ?? '') === 'content', "[$mode] sous-menu vers une page");
    check(($mainItems[5]['type'] ?? '') === 'url' && ($mainItems[5]['url'] ?? '') === 'https://boutique.atelier-lumiere.example/', "[$mode] lien externe conservé");
    if ($mode !== 'API publique') {
        check($foot !== null && (int) ($locs['footer'] ?? 0) === (int) $foot['id'] && count(json_decode($foot['items'], true)) === 4, "[$mode] « Pied de page » en emplacement pied de page (4 liens)");
    }
    $home = http($site['url'] . '/');
    check(str_contains($home['body'], 'Boutique en ligne') && str_contains($home['body'], 'href="/a-propos/tarifs/"'), "[$mode] menu affiché sur le site");

    // Réglages
    $s = wl($site, 'echo json_encode(["name" => Wordless\Settings::get("site.name"), "tagline" => Wordless\Settings::get("site.tagline"), "lang" => Wordless\Settings::get("language"), "slash" => Wordless\Settings::get("trailing_slash"), "permalink" => Wordless\Settings::get("permalink"), "home" => Wordless\Settings::get("home.type"), "home_id" => Wordless\Settings::get("home.page_id"), "blog_id" => Wordless\Settings::get("blog.page_id")], JSON_UNESCAPED_UNICODE);');
    check($s['name'] === 'Atelier Lumière' && $s['tagline'] === 'Céramique artisanale à Lyon', "[$mode] nom et slogan du site", $s);
    check($s['lang'] === 'en' && $s['slash'] === true && $s['permalink'] === '/{slug}', "[$mode] langue, barre finale et structure des permaliens", $s);
    check($s['home'] === 'page' && (int) $s['home_id'] === (int) (contentByWp($site, 10)['id'] ?? -1) && (int) $s['blog_id'] === (int) (contentByWp($site, 11)['id'] ?? -1), "[$mode] page d’accueil et page des articles", $s);

    // Rapport
    check(is_array($r['next_steps']) && count($r['next_steps']) >= 4, "[$mode] prochaines étapes dans le rapport");
    check(array_filter($r['warnings'], static fn ($w) => $w['code'] === 'form') !== [], "[$mode] avertissement : formulaires remplacés");
}

// ----------------------------------------------------------------------
// 1. Import par le fichier d'export (WXR)
// ----------------------------------------------------------------------

section('Import WXR (fichier d’export)');
$a = install('wxr', 'owner@example.net', 8095);
out("  copie : {$a['dir']} ({$a['url']})");
$t0 = microtime(true);
$state = migrate($a, ['type' => 'wxr', 'file' => $FIXTURE_DIR . '/export.xml']);
out(sprintf('  migration en %.1f s, %d étapes', microtime(true) - $t0, $state['_steps']));
commonChecks($a, $state, $fixture, $publicItems, 'WXR');
$r = $state['report'];
check($r['counts']['posts']['imported'] === 11, '[WXR] 11 articles importés (brouillon, programmé et privé compris)', $r['counts']['posts']);
check((int) val($a, 'SELECT COUNT(*) FROM users') === 4 && (int) val($a, "SELECT COUNT(*) FROM users WHERE role = 'author' AND wp_id IS NOT NULL") === 3, '[WXR] 3 auteurs créés (rôle Auteur)');
$author = q($a, "SELECT u.slug FROM content c JOIN users u ON u.id = c.author_id WHERE c.wp_id = 17")[0]['slug'] ?? '';
check($author === 'claire', '[WXR] auteur des articles conservé', $author);
check(http($a['url'] . '/author/claire/')['status'] === 200, '[WXR] archive d’auteur /author/claire/');
check(str_contains((string) val($a, "SELECT password FROM users WHERE slug = 'claire'"), '$argon2'), '[WXR] mot de passe aléatoire haché pour les auteurs importés');

// SEO (Yoast, Rank Math) : seulement dans le fichier d'export
$seo = static fn (int $id): array => (array) (contentByWp($a, $id)['seo'] ?? []);
$s16 = $seo(16);
check($s16['title'] === '' && $s16['description'] === $metaOf(16)['_yoast_wpseo_metadesc'] && $s16['keyword'] === 'atelier céramique Lyon', '[WXR] Yoast : titre par défaut laissé vide, description et mot-clé', $s16);
$s17 = $seo(17);
check($s17['canonical'] === 'https://www.atelier-lumiere.example/blog/secrets-emaillage/', '[WXR] Yoast : canonique externe', $s17['canonical']);
check($s17['og_image'] && (int) val($a, 'SELECT wp_id FROM media WHERE id = ?', [(int) $s17['og_image']]) === 6, '[WXR] Yoast : image de partage', $s17['og_image']);
check($seo(18)['noindex'] === true, '[WXR] Yoast : noindex');
$s19 = $seo(19);
check($s19['title'] === '' && $s19['description'] === $metaOf(19)['rank_math_description'] && $s19['keyword'] === 'bols en grès' && $s19['noindex'] === false, '[WXR] Rank Math : description, premier mot-clé, index', $s19);
$s20 = $seo(20);
check($s20['title'] === 'Atelier poterie enfants à Lyon – Atelier Lumière', '[WXR] Yoast : titre personnalisé (variables remplacées)', $s20['title']);
$s21 = $seo(21);
check($s21['title'] === 'Stage de tournage à Lyon – Atelier Lumière' && $s21['noindex'] === true, '[WXR] Rank Math : titre et noindex', $s21);
check($seo(10)['title'] === 'Atelier Lumière – Céramique artisanale à Lyon', '[WXR] titre SEO de l’accueil (%%sitename%% %%sep%% %%sitedesc%%)', $seo(10)['title']);
$p20 = http($a['url'] . '/atelier-enfants-modeler-argile/')['body'];
check(str_contains($p20, '<title>Atelier poterie enfants à Lyon – Atelier Lumière</title>'), '[WXR] balise <title> publiée');
check((bool) preg_match('#<meta name="robots" content="noindex#', http($a['url'] . '/cuisson-raku-feu-et-fumee/')['body']), '[WXR] meta robots noindex publiée');
check(str_contains(http($a['url'] . '/secrets-emaillage-guide-complet/')['body'], '<link rel="canonical" href="https://www.atelier-lumiere.example/blog/secrets-emaillage/">'), '[WXR] canonique publiée');

// Elementor (données _elementor_data du fichier d'export)
$el = contentByWp($a, 15);
$types = array_column($el['blocks'] ?? [], 'type');
$elHero = blocksOf($el, 'hero')[0]['data'] ?? [];
check(($types[0] ?? '') === 'hero' && $elHero['title'] === 'Nos ateliers de céramique' && $elHero['button'] === 'Réserver un créneau' && (int) val($a, 'SELECT wp_id FROM media WHERE id = ?', [(int) $elHero['media']]) === 5, '[WXR] Elementor : conteneur avec image de fond -> Bannière', $elHero);
$elCols = blocksOf($el, 'columns')[0]['data']['items'] ?? [];
check(count($elCols) === 2 && (int) val($a, 'SELECT wp_id FROM media WHERE id = ?', [(int) $elCols[0]['media']]) === 8 && $elCols[1]['title'] === 'Ateliers enfants' && str_contains($elCols[1]['text'], '<li>'), '[WXR] Elementor : section à 2 colonnes -> Colonnes', $elCols);
$elFaq = blocksOf($el, 'faq');
check(count($elFaq) === 2 && count($elFaq[0]['data']['items']) === 3 && $elFaq[0]['data']['title'] === 'Questions fréquentes' && $elFaq[1]['data']['title'] === 'Bon à savoir', '[WXR] Elementor : accordéon et bascule -> FAQ');
check(count(blocksOf($el, 'video')) === 1 && count(blocksOf($el, 'divider')) === 1, '[WXR] Elementor : vidéo et séparateur');
$test = blocksOf($el, 'testimonials')[0]['data']['items'][0] ?? [];
check(($test['name'] ?? '') === 'Sophie Bernard' && ($test['role'] ?? '') === 'Élève du cours du jeudi', '[WXR] Elementor : témoignage', $test);
check(!str_contains(json_encode($el['blocks'], JSON_UNESCAPED_UNICODE), 'tabindex'), '[WXR] Elementor : le texte de secours n’est pas dupliqué');
$elPage = http($a['url'] . '/nos-ateliers/');
check($elPage['status'] === 200 && str_contains($elPage['body'], 'Nos ateliers de céramique'), '[WXR] page Elementor publiée');

// Redirections
$old = http($a['url'] . '/guide-emaillage/');
check($old['status'] === 301 && str_ends_with($old['headers']['location'] ?? '', '/secrets-emaillage-guide-complet/'), '[WXR] ancien slug (_wp_old_slug) -> 301', $old['headers']['location'] ?? $old['status']);
$att = http($a['url'] . '/notre-nouvel-atelier-croix-rousse/panorama-de-latelier/');
check($att['status'] === 301 && str_contains($att['headers']['location'] ?? '', '/media/2026/02/panorama-atelier-croix-rousse.jpg'), '[WXR] page de pièce jointe -> 301 vers le fichier');
check(http($a['url'] . '/?p=18')['status'] === 301, '[WXR] ancien lien ?p=18 redirigé');

// Sécurité du code HTML importé
$html = blocksOf(contentByWp($a, 16), 'html')[0]['data']['code'] ?? '';
check($html !== '' && !str_contains($html, '<iframe') && !str_contains($html, 'style='), '[WXR] bloc HTML nettoyé (iframe et styles retirés)', $html);

// Idempotence : seconde migration (ligne de commande) sans doublon
section('Idempotence (seconde migration WXR, en ligne de commande)');
// Le site WordPress de test est en anglais : l'import a passé Wordless en anglais.
// Les messages vérifiés ci-dessous sont en français, on remet donc l'interface en français.
wl($a, 'Wordless\Settings::set("language", "fr"); echo "{}";');
$before = rowCounts($a);
$cli = run(['php', 'bin/wordless', 'import:wxr', $FIXTURE_DIR . '/export.xml', '--quiet'], $a['dir']);
check($cli['code'] === 0 && str_contains($cli['out'], 'Rapport de migration'), '[WXR] commande import:wxr', $cli['err']);
$after = rowCounts($a);
unset($before['import_map'], $after['import_map']);
check($before === $after, '[WXR] aucune ligne en double après une seconde migration', ['avant' => $before, 'après' => $after]);
check((bool) preg_match('/Articles\s+0 importés, 11 mis à jour/u', $cli['out']), '[WXR] la seconde migration met à jour au lieu de créer');
foreach (['/', '/a-propos/tarifs/', '/notre-nouvel-atelier-croix-rousse/'] as $path) {
    check(http($a['url'] . $path)['status'] === 200, "[WXR] $path toujours 200 après la seconde migration");
}

// ----------------------------------------------------------------------
// 2. Import par l'API REST (mot de passe d'application)
// ----------------------------------------------------------------------

section('Import par l’API REST (mot de passe d’application)');
$b = install('rest', 'admin@example.com', 8096);
out("  copie : {$b['dir']} ({$b['url']})");
$t0 = microtime(true);
$state = migrate($b, ['type' => 'rest', 'url' => WP_URL, 'user' => $appUser, 'password' => $appPassword], ['seo_fallback' => true]);
out(sprintf('  migration en %.1f s, %d étapes', microtime(true) - $t0, $state['_steps']));
commonChecks($b, $state, $fixture, $publicItems, 'API');
$r = $state['report'];
check($r['counts']['posts']['imported'] === 11, '[API] 11 articles (brouillon, programmé et privé grâce au mot de passe d’application)', $r['counts']['posts']);
$users = q($b, 'SELECT email, role, wp_id FROM users ORDER BY id');
check(count($users) === 3 && $users[0]['email'] === 'admin@example.com' && (int) $users[0]['wp_id'] === 1, '[API] l’administrateur WordPress est associé au compte existant', $users);
$roles = array_column($users, 'role', 'email');
check(($roles['claire@example.com'] ?? '') === 'editor' && ($roles['lucas@example.com'] ?? '') === 'author', '[API] rôles WordPress repris (éditeur, auteur)', $roles);
check((string) wl($b, 'echo json_encode(Wordless\Settings::get("timezone"));') === 'Europe/Paris', '[API] fuseau horaire repris');
check(($r['counts']['comments']['reasons']['indésirables ou à la corbeille'] ?? 0) === 1, '[API] commentaire indésirable compté comme ignoré', $r['counts']['comments']);
check(in_array((int) (contentByWp($b, 15)['id'] ?? 0), array_column($r['review'], 'id'), true), '[API] page Elementor signalée « à revoir » (mise en page simplifiée)');
$jobFile = (string) @file_get_contents($b['dir'] . '/data/import/' . $state['id'] . '.json');
check($jobFile !== '' && !str_contains($jobFile, $appPassword) && !str_contains($jobFile, str_replace(' ', '', $appPassword)) && (bool) preg_match('/"secret":"(s1|g1):/', $jobFile), '[API] mot de passe d’application chiffré dans la tâche');
check(!isset($state['source']['password']), '[API] mot de passe absent de l’état renvoyé');
check(!is_dir($b['dir'] . '/data/import/' . $state['id']), '[API] fichiers intermédiaires supprimés en fin de migration');

section('Idempotence (seconde migration par l’API)');
$before = rowCounts($b);
$state2 = migrate($b, ['type' => 'rest', 'url' => WP_URL, 'user' => $appUser, 'password' => $appPassword], [], 8);
$after = rowCounts($b);
unset($before['import_map'], $after['import_map']);
check(!$state2['error'] && $before === $after, '[API] aucune ligne en double après une seconde migration', ['avant' => $before, 'après' => $after, 'erreur' => $state2['error']]);
check($state2['report']['counts']['pages']['updated'] === 7 && $state2['report']['counts']['pages']['imported'] === 0, '[API] pages mises à jour, pas recréées');

// ----------------------------------------------------------------------
// 3. API publique (sans mot de passe) et cas d'erreur
// ----------------------------------------------------------------------

if (getenv('WL_SKIP_ANON') !== '1') {
    section('Import par l’API publique (sans mot de passe)');
    $c = install('anon', 'owner@example.net', 8097);
    $state = migrate($c, ['type' => 'rest', 'url' => WP_URL]);
    check(!$state['error'] && $state['finished'], '[API publique] migration terminée', $state['error']);
    check($state['report']['counts']['posts']['imported'] === 8, '[API publique] 8 articles publiés importés', $state['report']['counts']['posts']);
    $bad = [];
    foreach ($publicItems as $p) {
        if (http($c['url'] . $p['path'])['status'] !== 200) {
            $bad[] = $p['path'];
        }
    }
    check(!$bad, '[API publique] adresses publiques identiques', $bad);
    $faq = blocksOf(contentByWp($c, 20), 'faq')[0]['data'] ?? [];
    check(count($faq['items'] ?? []) === 4, '[API publique] FAQ convertie depuis le HTML rendu');
    check(count(blocksOf(contentByWp($c, 16), 'video')) === 1 && (blocksOf(contentByWp($c, 16), 'video')[0]['data']['url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', '[API publique] vidéo intégrée');
    check((int) val($c, 'SELECT COUNT(*) FROM menus') === 1, '[API publique] menu de navigation (bloc) importé');
}

// ----------------------------------------------------------------------
// 3 bis. Cas limites (WXR synthétique)
// ----------------------------------------------------------------------

section('Cas limites : WordPress dans /blog, slugs accentués, Rank Math, Divi, type personnalisé');
$e = install('edge', 'owner@example.net', 8098);
$edgeFile = $e['base'] . '/edge.xml';
file_put_contents($edgeFile, edgeWxr(WP_URL . '/blog', WP_URL));
$state = migrate($e, ['type' => 'wxr', 'file' => $edgeFile]);
check(!$state['error'] && $state['finished'], '[cas limites] migration terminée (caractère de contrôle interdit en XML toléré)', $state['error']);
$cafe = contentByWp($e, 100);
check(($cafe['path'] ?? '') === '/café-crème' && http($e['url'] . '/caf%C3%A9-cr%C3%A8me/')['status'] === 200, '[cas limites] slug accentué conservé (/café-crème/)', $cafe['path'] ?? null);
check(str_contains(json_encode($cafe['blocks'] ?? [], JSON_UNESCAPED_UNICODE), 'Intro avec un caractère interdit'), '[cas limites] texte avec caractère de contrôle importé');
$moved = http($e['url'] . '/blog/caf%C3%A9-cr%C3%A8me/');
check($moved['status'] === 301 && str_ends_with(rawurldecode((string) ($moved['headers']['location'] ?? '')), '/café-crème/'), '[cas limites] ancienne adresse /blog/… redirigée (WordPress installé dans un sous-dossier)', $moved['headers']['location'] ?? $moved['status']);
$catMoved = http($e['url'] . '/category/caf%C3%A9s/');
check($catMoved['status'] === 301 && str_ends_with((string) ($catMoved['headers']['location'] ?? ''), '/category/cafes/'), '[cas limites] archive de catégorie au slug accentué redirigée');
$rmFaq = blocksOf($cafe, 'faq')[0]['data'] ?? [];
check(count($rmFaq['items'] ?? []) === 2 && $rmFaq['title'] === 'FAQ café' && str_contains($rmFaq['items'][0]['a'], '<strong>bon</strong>'), '[cas limites] FAQ Rank Math -> bloc FAQ', $rmFaq);
check(count(blocksOf($cafe, 'toc')) === 1, '[cas limites] sommaire Rank Math -> bloc Sommaire');
$cafeJson = json_encode($cafe['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check(str_contains($cafeJson, 'src=\"/media/2026/03/bols-gres-emailles') && !str_contains($cafeJson, 'i0.wp.com'), '[cas limites] image Jetpack Photon (i0.wp.com) reprise dans la médiathèque');
check(str_contains($cafeJson, 'href=\"/portfolio/projet-x/\"') && str_contains($cafeJson, 'Dans un bloc d'), '[cas limites] lien interne réécrit, bloc d’extension conteneur aplati');
check(($cafe['seo']['noindex'] ?? null) === true && ($cafe['seo']['keyword'] ?? '') === 'café' && ($cafe['seo']['description'] ?? '') === 'Desc SEOPress' && ($cafe['seo']['title'] ?? 'x') === '', '[cas limites] SEOPress : noindex, mot-clé, description, titre par défaut', $cafe['seo'] ?? null);
$divi = contentByWp($e, 101);
$diviJson = json_encode($divi['blocks'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check(str_contains($diviJson, 'Texte Divi') && !str_contains($diviJson, 'et_pb_') && count(blocksOf($divi, 'video')) === 1 && count(blocksOf($divi, 'gallery')) === 1, '[cas limites] Divi : balises retirées, vidéo Vimeo et galerie converties');
check(($divi['seo']['title'] ?? '') === 'Titre AIOSEO' && ($divi['seo']['description'] ?? '') === 'Desc AIOSEO', '[cas limites] All in One SEO (ancienne version)');
check(in_array((int) ($divi['id'] ?? 0), array_column($state['report']['review'], 'id'), true) && array_filter($state['report']['warnings'], static fn ($w) => $w['code'] === 'shortcode') !== [], '[cas limites] code court inconnu [mon_code] signalé « à revoir »');
$parent = contentByWp($e, 50);
$child = contentByWp($e, 51);
check(($child['path'] ?? '') === '/parent/enfant' && (int) ($child['parent_id'] ?? 0) === (int) ($parent['id'] ?? -1) && http($e['url'] . '/parent/enfant/')['status'] === 200, '[cas limites] permaliens simples (?page_id=) : adresse hiérarchique calculée');
check(($parent['seo']['title'] ?? '') === 'TSF' && ($parent['seo']['noindex'] ?? false) === true, '[cas limites] The SEO Framework');
check((contentByWp($e, 60)['path'] ?? '') === '/portfolio/projet-x' && http($e['url'] . '/portfolio/projet-x/')['status'] === 200, '[cas limites] type personnalisé importé comme page à la même adresse');
check(contentByWp($e, 61) === null && array_filter($state['report']['warnings'], static fn ($w) => $w['code'] === 'cpt') !== [], '[cas limites] produit WooCommerce non importé et signalé');
check(contentByWp($e, 62) === null && ($state['report']['counts']['posts']['reasons']['dans la corbeille'] ?? 0) === 1, '[cas limites] contenu à la corbeille ignoré');
check((int) val($e, 'SELECT COUNT(*) FROM comments') === 1 && ($state['report']['counts']['comments']['reasons']['indésirables ou à la corbeille'] ?? 0) === 2, '[cas limites] commentaires indésirables et à la corbeille ignorés (comptés)');
check((string) val($e, "SELECT name FROM users WHERE wp_login = 'admin'") === 'Jean Admin' && str_ends_with((string) val($e, "SELECT email FROM users WHERE wp_login = 'admin'"), '@import.invalid'), '[cas limites] auteur sans e-mail : adresse provisoire, nom sans balises');
$footMenu = q($e, "SELECT id, items FROM menus WHERE name = 'Liens du pied'")[0] ?? null;
$fm = json_decode((string) ($footMenu['items'] ?? '[]'), true);
check(array_column($fm, 'label') === ['Divi', 'Niveau 1', 'Enfant'], '[cas limites] menu : lien javascript: retiré, libellé repris du contenu', array_column($fm, 'label'));
check(($fm[0]['type'] ?? '') === 'content' && (int) ($fm[0]['id'] ?? 0) === (int) (contentByWp($e, 101)['id'] ?? -1), '[cas limites] menu : lien court ?p=101 rattaché au contenu');
check(array_column($fm[1]['children'][0]['children'] ?? [], 'label') === ['Niveau 3', 'Niveau 4'] && ($fm[1]['children'][0]['children'][1]['children'] ?? null) === [], '[cas limites] menu : 4e niveau remonté au 3e (3 niveaux au plus)', $fm[1] ?? null);
check((int) (wl($e, 'echo json_encode(Wordless\Settings::get("menus.locations"));')['footer'] ?? 0) === (int) ($footMenu['id'] ?? -1), '[cas limites] menu « Liens du pied » placé en pied de page');
check(wl($e, 'echo json_encode(Wordless\Settings::get("language"));') === 'fr' && wl($e, 'echo json_encode(Wordless\Settings::get("timezone"));') === 'Europe/Paris', '[cas limites] langue et fuseau horaire déduits du fichier');

section('Cas d’erreur');
wl($a, 'Wordless\Settings::set("language", "fr"); echo "{}";'); // messages vérifiés en français
$err = wl($a, 'echo json_encode(Wordless\Migrate\Importer::create(["type" => "wxr", "file" => "/nexiste/pas.xml"]), JSON_UNESCAPED_UNICODE);');
check($err['finished'] === true && is_string($err['error']) && $err['error'] !== '', 'fichier introuvable : erreur lisible', $err['error']);
$bogus = $a['base'] . '/pas-un-wxr.xml';
file_put_contents($bogus, '<?xml version="1.0"?><html><body>Bonjour</body></html>');
$err = wl($a, 'echo json_encode(Wordless\Migrate\Importer::create(["type" => "wxr", "file" => ' . var_export($bogus, true) . ']), JSON_UNESCAPED_UNICODE);');
check($err['finished'] === true && str_contains((string) $err['error'], 'WXR'), 'fichier qui n’est pas un export WordPress refusé', $err['error']);
$err = wl($a, 'echo json_encode(Wordless\Migrate\Importer::create(["type" => "rest", "url" => "' . WP_URL . '", "user" => "admin", "password" => "mauvais mot de passe"]), JSON_UNESCAPED_UNICODE);');
check($err['finished'] === true && str_contains((string) $err['error'], 'mot de passe'), 'mot de passe d’application refusé : erreur lisible', $err['error']);
check(wl($a, 'echo json_encode(Wordless\Migrate\Importer::get("../../etc/passwd"));') === null, 'identifiant de tâche invalide refusé');
$cancel = wl($a, '$s = Wordless\Migrate\Importer::create(["type" => "wxr", "file" => ' . var_export($FIXTURE_DIR . '/export.xml', true) . ']); Wordless\Migrate\Importer::cancel($s["id"]); echo json_encode(Wordless\Migrate\Importer::get($s["id"]), JSON_UNESCAPED_UNICODE);');
check($cancel['finished'] === true && $cancel['cancelled'] === true, 'annulation d’une migration');

// ----------------------------------------------------------------------
// 4. Fonctions de conversion
// ----------------------------------------------------------------------

section('Fonctions de conversion');
$unit = wl($a, <<<'PHP'
use Wordless\Migrate\{Convert, Importer, SeoMeta, Context};
$vars = ['title' => 'Mon article', 'sitename' => 'Mon site', 'sitedesc' => 'Slogan', 'sep' => '–', 'excerpt' => 'Résumé'];
$parsed = Convert::parse("<!-- wp:group -->\n<div><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:image {\"id\":5} /--></div>\n<!-- /wp:group -->");
echo json_encode([
    'resolve' => SeoMeta::resolve('%%title%% %%page%% %%sep%% %%sitename%%', $vars),
    'default' => SeoMeta::isDefaultTitle(SeoMeta::resolve('%title% %sep% %sitename%', $vars), $vars),
    'aioseo' => SeoMeta::resolve('#post_title #separator_sa #site_title', $vars),
    'percent' => SeoMeta::resolve('Promo -20 % sur %title%', $vars),
    'perm1' => Importer::inferPermalink([['link' => 'https://ex.fr/2024/05/mon-article/', 'slug' => 'mon-article', 'date' => '2024-05-12 10:00:00', 'id' => 7, 'cats' => []]], [], ''),
    'perm2' => Importer::inferPermalink([['link' => 'https://ex.fr/blog/actus/mon-article.html', 'slug' => 'mon-article', 'date' => '2024-05-12 10:00:00', 'id' => 7, 'cats' => ['actus']]], ['actus' => 'actus'], ''),
    'tpl' => Importer::permalinkFromTemplate('https://ex.fr/%year%/%monthnum%/%postname%/', 'https://ex.fr'),
    'orig' => Context::originalRel('2024/05/photo-e1612345678901-300x200.jpg'),
    'scaled' => Context::originalRel('2024/05/photo-scaled.jpg'),
    'nested' => [$parsed[0]['blockName'], count($parsed[0]['innerBlocks']), $parsed[0]['innerBlocks'][1]['attrs']['id'] ?? null],
    'attrs' => Convert::attrs('ids="4,6" columns=3 link=\'file\''),
    'yoast' => SeoMeta::fromYoastHead(['title' => 'Mon article – Mon site', 'description' => 'Résumé &amp; plus', 'robots' => ['index' => 'noindex', 'follow' => 'follow'],
        'canonical' => 'https://ailleurs.example/page/'], $vars, new Context(Wordless\Migrate\Job::create(['type' => 'wxr'], [])), '/mon-article'),
], JSON_UNESCAPED_UNICODE);
PHP);
check($unit['resolve'] === 'Mon article – Mon site', 'variables Yoast remplacées (%%page%% vide retiré)', $unit['resolve']);
check($unit['default'] === true, 'titre Rank Math par défaut reconnu');
check($unit['aioseo'] === 'Mon article – Mon site', 'variables All in One SEO remplacées', $unit['aioseo']);
check($unit['percent'] === 'Promo -20 % sur Mon article', 'pourcentages du texte préservés', $unit['percent']);
check($unit['perm1'] === '/{year}/{month}/{slug}', 'structure /%year%/%monthnum%/%postname%/ reconnue', $unit['perm1']);
check($unit['perm2'] === '/blog/{category}/{slug}.html', 'structure avec catégorie et .html reconnue', $unit['perm2']);
check(($unit['tpl']['pattern'] ?? '') === '/{year}/{month}/{slug}' && ($unit['tpl']['trailing'] ?? false) === true, 'modèle de permalien de l’API converti', $unit['tpl']);
check($unit['orig'] === '2024/05/photo.jpg' && $unit['scaled'] === '2024/05/photo.jpg', 'fichier original retrouvé (-300x200, -scaled, -e…)', [$unit['orig'], $unit['scaled']]);
check($unit['nested'] === ['core/group', 2, 5], 'analyse des blocs imbriqués et auto-fermants', $unit['nested']);
check($unit['attrs'] === ['ids' => '4,6', 'columns' => '3', 'link' => 'file'], 'attributs de code court', $unit['attrs']);
check($unit['yoast'] === ['title' => '', 'description' => 'Résumé & plus', 'canonical' => 'https://ailleurs.example/page/', 'noindex' => true], 'API REST avec Yoast : yoast_head_json repris', $unit['yoast']);

// ----------------------------------------------------------------------

echo PHP_EOL . ($failures === 0 ? "OK : $checks vérifications réussies." : "ÉCHEC : $failures vérification(s) sur $checks.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
