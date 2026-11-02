<?php
declare(strict_types=1);

/**
 * Tests des correctifs de Wordless 1.0.1 (la migration et le connecteur MCP ont leurs propres tests).
 *
 *   php tests/core_test.php            (KEEP=1 pour conserver le dossier temporaire)
 *
 * Copie Wordless dans un dossier temporaire, l'installe en anglais, lance `php -S` sur
 * 127.0.0.1:8096, puis vérifie : chiffres reconnus par l'analyse GEO (français, anglais,
 * espagnol), llms.txt sans versions Markdown, « L'essentiel » dans les données structurées,
 * langue de l'administration propre à chaque utilisateur, recherche rapide dans l'éditeur,
 * connexion par e-mail uniquement, adresse IP derrière un proxy de confiance, robots.txt,
 * ligne de commande et User-Agent sortant. Code de sortie 1 en cas d'échec.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const PORT = 8096;
const BASE = 'http://127.0.0.1:8096';
const EMAIL = 'admin@example.test';
const PASSWORD = 'Correct-horse-battery-staple-42';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label, mixed $debug = null): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok    $label\n";
        return;
    }
    $fail++;
    echo "  FAIL  $label\n";
    if ($debug !== null) {
        $d = is_string($debug) ? $debug : (string) json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '        ' . substr(str_replace("\n", "\n        ", $d), 0, 1500) . "\n";
    }
}

function section(string $title): void
{
    echo "\n== $title\n";
}

function copy_tree(string $from, string $to, array $skipTop): void
{
    mkdir($to, 0775, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($from) + 1);
        if (in_array(explode('/', $rel)[0], $skipTop, true)) {
            continue;
        }
        $file->isDir() ? (is_dir($to . '/' . $rel) || mkdir($to . '/' . $rel, 0775, true)) : copy($file->getPathname(), $to . '/' . $rel);
    }
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** @return array{status: int, body: string} */
function get(string $path, ?string $cookie = null): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => $cookie !== null ? ['Cookie: wl_s=' . $cookie] : [],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/** @return array{code: int, out: string, err: string} */
function cli(string $root, array $args): array
{
    $proc = proc_open([PHP_BINARY, $root . '/bin/wordless', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => $out, 'err' => $err];
}

// ----------------------------------------------------------------------
// Préparation : site anglais avec le contenu de démonstration
// ----------------------------------------------------------------------

$src = dirname(__DIR__);
$root = sys_get_temp_dir() . '/wordless-core-test-' . bin2hex(random_bytes(4));
$server = null;
register_shutdown_function(static function () use (&$server, $root): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (getenv('KEEP') === '1') {
        echo "\nDossier conservé : $root\n";
        return;
    }
    remove_tree($root);
    @unlink($root . '.log');
});

if ($s = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.2)) {
    fclose($s);
    fwrite(STDERR, 'Le port ' . PORT . " est déjà utilisé.\n");
    exit(1);
}
echo "Wordless — tests des correctifs 1.0.1\nDossier : $root\n";
copy_tree($src, $root, ['.git', 'data', 'media', 'tests', 'dist']);
mkdir($root . '/data', 0775, true);
mkdir($root . '/media', 0775, true);
$install = cli($root, ['install', '--url=' . BASE, '--email=' . EMAIL, '--password=' . PASSWORD, '--site=Test Site', '--name=Alex', '--lang=en']);
if ($install['code'] !== 0) {
    fwrite(STDERR, "Installation impossible :\n" . $install['out'] . $install['err']);
    exit(1);
}
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . PORT, 'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '.log', 'a'], 2 => ['file', $root . '.log', 'a']], $pipes, $root);
for ($i = 0; $i < 100 && !($s = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.2)); $i++) {
    usleep(100000);
}
if (!$s) {
    fwrite(STDERR, "Le serveur ne répond pas.\n");
    exit(1);
}
fclose($s);

// L'application est aussi chargée dans ce processus, sur la même base.
define('WL_ROOT', $root);
require $root . '/core/bootstrap.php';
Wordless\App::boot();

use Wordless\Auth;
use Wordless\Cache;
use Wordless\Content;
use Wordless\Db;
use Wordless\HttpClient;
use Wordless\I18n;
use Wordless\Seo;
use Wordless\Settings;

$adminId = (int) Db::val('SELECT id FROM users WHERE email = ?', [EMAIL]);
$cookie = bin2hex(random_bytes(24));
Db::run('INSERT INTO sessions (id, user_id, csrf, pending_2fa, ip, ua, created_at, seen_at, expires_at) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)',
    [hash('sha256', $cookie), $adminId, bin2hex(random_bytes(16)), '', 'core-test', time(), time(), time() + 3600]);

// ----------------------------------------------------------------------
section('Analyse GEO : chiffres reconnus en français, anglais et espagnol');
// ----------------------------------------------------------------------

$figures = [
    'Pages load in 120 ms.', 'The archive weighs 606 KB.', 'Set it up in 5 minutes.', 'Trusted by 2 million sites.', '58× smaller than WordPress.',
    'Plans start at €29.', 'It saves 40% of the bandwidth.', 'La page pèse 1,9 Mo.', 'Comptez 3 jours.', 'Tarda 2 segundos.', 'Ahorra un 40 %.', 'En 3 días.',
];
foreach ($figures as $sentence) {
    check(Seo::hasFigures($sentence), 'chiffre reconnu : « ' . $sentence . ' »');
}
foreach (['Wordless 1.0 ships today.', 'We answered 2 questions.', 'Read chapter 3.', 'It runs 3 sites.'] as $sentence) {
    check(!Seo::hasFigures($sentence), 'pas un chiffre précis : « ' . $sentence . ' »');
}
$input = ['id' => 0, 'type' => 'post', 'title' => 'Speed test', 'slug' => 'speed-test', 'lang' => 'en', 'author_id' => $adminId, 'seo' => [],
    'blocks' => [['type' => 'text', 'data' => ['html' => '<p>Each page loads in 120 ms and weighs 30 KB on average.</p>']]], 'updated_at' => time(), 'published_at' => time()];
$report = Seo::analyze($input);
$report['__input'] = $input;
$statistics = array_values(array_filter($report['geo']['checks'] ?? [], static fn ($c) => ($c['id'] ?? '') === 'statistics'))[0] ?? [];
check(($statistics['status'] ?? '') === 'good', 'analyse GEO d’un texte anglais : critère « chiffres » validé', $statistics);
$long = ['type' => 'text', 'data' => ['html' => '<p>' . str_repeat('Each page loads in 120 ms and weighs 30 KB on average. ', 4) . '</p>']];
$readEn = Seo::analyze(['lang' => 'en', 'blocks' => [$long]] + $report['__input'])['stats']['readability'] ?? null;
$readFr = Seo::analyze(['lang' => 'fr', 'blocks' => [$long]] + $report['__input'])['stats']['readability'] ?? null;
check($readEn !== null && $readFr !== null && $readEn !== $readFr, 'lisibilité : formule de la langue du contenu, pas celle de l’administration', [$readEn, $readFr]);

// ----------------------------------------------------------------------
section('llms.txt et versions Markdown');
// ----------------------------------------------------------------------

$r = get('/llms.txt');
check($r['status'] === 200 && str_contains($r['body'], '.md)'), 'versions Markdown actives : llms.txt pointe vers les .md', substr($r['body'], 0, 400));
Settings::set('geo.markdown', false);
Cache::purge();
$r = get('/llms.txt');
check($r['status'] === 200 && !str_contains($r['body'], '.md)') && str_contains($r['body'], '](' . BASE . '/'), 'versions Markdown désactivées : llms.txt pointe vers les pages', substr($r['body'], 0, 400));
Settings::set('geo.markdown', true);
Cache::purge();

// ----------------------------------------------------------------------
section('« L’essentiel » dans les données structurées');
// ----------------------------------------------------------------------

$post = Db::one("SELECT * FROM content WHERE type = 'post' AND status = 'published' AND blocks LIKE '%takeaways%' ORDER BY id LIMIT 1");
check($post !== null, 'article de démonstration avec un bloc « L’essentiel »');
$url = $post ? (string) parse_url(Content::permalink(Content::decode($post)), PHP_URL_PATH) : '/';
$page = get($url);
$graph = [];
if (preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $page['body'], $m)) {
    foreach ($m[1] as $json) {
        $data = json_decode($json, true);
        $graph = array_merge($graph, (array) ($data['@graph'] ?? [$data]));
    }
}
$webpage = array_values(array_filter($graph, static fn ($n) => is_array($n) && isset($n['speakable'])))[0] ?? [];
$article = array_values(array_filter($graph, static fn ($n) => is_array($n) && in_array($n['@type'] ?? '', ['BlogPosting', 'Article', 'NewsArticle'], true)))[0] ?? [];
$points = $post ? Wordless\Blocks::takeaways(Content::decode($post)['blocks']) : [];
check($page['status'] === 200 && $points !== [] && str_starts_with((string) ($webpage['abstract'] ?? ''), rtrim($points[0], '.')), 'page : abstract tiré des points clés', [$points, $webpage['abstract'] ?? null]);
check(($webpage['speakable']['cssSelector'] ?? null) === ['.wl-takeaways'] && str_contains($page['body'], 'wl-takeaways'), 'page : speakable vise le bloc affiché (.wl-takeaways)');
check(($article['abstract'] ?? '') !== '' && ($article['abstract'] ?? '') === ($webpage['abstract'] ?? null), 'article : même abstract', $article);
$home = get('/');
check(!str_contains($home['body'], '"speakable"'), 'page sans « L’essentiel » : ni abstract ni speakable');

// ----------------------------------------------------------------------
section('Langue de l’administration propre à chaque utilisateur');
// ----------------------------------------------------------------------

$r = get('/admin/', $cookie);
check($r['status'] === 200 && str_contains($r['body'], '<html lang="en"'), 'sans préférence : langue du site (anglais)', $r['status']);
Db::update('users', ['lang' => 'es'], ['id' => $adminId]);
$r = get('/admin/', $cookie);
check(str_contains($r['body'], '<html lang="es"') && str_contains($r['body'], 'Ajustes'), 'préférence « español » : administration en espagnol');
$front = get('/');
check(str_contains($front['body'], '<html lang="en'), 'le site public reste dans sa langue');
Db::update('users', ['lang' => ''], ['id' => $adminId]);

// ----------------------------------------------------------------------
section('Recherche rapide (Ctrl+K) dans l’éditeur');
// ----------------------------------------------------------------------

$r = get('/admin/content/' . (int) ($post['id'] ?? 0), $cookie);
check($r['status'] === 200 && str_contains($r['body'], '<dialog id="cmdk"') && str_contains($r['body'], 'data-cmdk-pages'), 'éditeur : boîte de recherche et liste des pages présentes', $r['status']);
$editorFrame = (string) file_get_contents($root . '/admin/assets/editor-frame.js');
$editorJs = (string) file_get_contents($root . '/admin/assets/editor.js');
check(str_contains($editorFrame, "type: 'search'") && str_contains($editorJs, 'WL.openSearch'), 'Ctrl+K dans la page éditée : relayé vers l’éditeur');

// ----------------------------------------------------------------------
section('Connexion : e-mail uniquement');
// ----------------------------------------------------------------------

$slug = (string) Db::val('SELECT slug FROM users WHERE id = ?', [$adminId]);
check($slug !== '' && Auth::attempt($slug, PASSWORD)['ok'] === false, 'identifiant public d’auteur (/author/' . $slug . '/) refusé');
check(Auth::attempt(EMAIL, PASSWORD)['ok'] === true, 'adresse e-mail acceptée');

// ----------------------------------------------------------------------
section('Adresse IP derrière un proxy ou un CDN');
// ----------------------------------------------------------------------

check(wl_ip_in('10.1.2.3', ['10.0.0.0/8']) && !wl_ip_in('11.0.0.1', ['10.0.0.0/8']), 'plage IPv4 (CIDR)');
check(wl_ip_in('192.168.1.100', ['192.168.1.0/25']) && !wl_ip_in('192.168.1.200', ['192.168.1.0/25']), 'plage IPv4 sur un octet partiel (/25)');
check(wl_ip_in('2001:db8::1', ['2001:db8::/32']) && !wl_ip_in('2001:db9::1', ['2001:db8::/32']) && !wl_ip_in('10.0.0.1', ['2001:db8::/32']), 'plage IPv6');
$ip = static function (array $server, array $trusted): string {
    $saved = $_SERVER;
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    $_SERVER = $server + $_SERVER;
    $out = wl_client_ip($trusted);
    $_SERVER = $saved;
    return $out;
};
check($ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], []) === '203.0.113.5', 'sans proxy déclaré : X-Forwarded-For ignoré (pas d’usurpation)');
check($ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], ['10.0.0.0/8']) === '203.0.113.5', 'requête directe d’un visiteur : son adresse, pas l’en-tête');
check($ip(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2'], ['10.0.0.0/8']) === '198.51.100.7', 'derrière deux proxys de confiance : adresse du visiteur');
check($ip(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7'], ['10.0.0.0/8']) === '198.51.100.7', 'adresse inventée en tête de X-Forwarded-For ignorée');
check($ip(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:52100'], ['10.0.0.0/8']) === '198.51.100.7', 'port retiré');
check($ip(['REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '2a00:1450:4007::5'], ['2001:db8::/32']) === '2a00:1450:4007::5', 'proxy et visiteur en IPv6');
check($ip(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_REAL_IP' => '198.51.100.9'], ['10.0.0.0/8']) === '198.51.100.9', 'X-Real-IP en l’absence de X-Forwarded-For');
putenv('WL_TRUSTED_PROXIES=203.0.113.0/24, 198.51.100.1');
check(wl_trusted_proxies(['173.245.48.0/20']) === ['173.245.48.0/20', '203.0.113.0/24', '198.51.100.1'], 'proxys : configuration + variable WL_TRUSTED_PROXIES');
putenv('WL_TRUSTED_PROXIES');

// ----------------------------------------------------------------------
section('Site en anglais : robots.txt, ligne de commande, messages');
// ----------------------------------------------------------------------

$r = get('/robots.txt');
check(str_starts_with($r['body'], '# robots.txt generated by Wordless'), 'robots.txt : commentaire dans la langue du site', substr($r['body'], 0, 80));
$help = cli($root, ['help']);
check($help['code'] === 0 && str_contains($help['out'], 'Command list') && str_contains($help['out'], '<new-password>'), 'aide de la ligne de commande en anglais', $help['out']);
$unknown = cli($root, ['nope']);
check($unknown['code'] === 1 && str_contains($unknown['err'], 'Unknown command: nope'), 'commande inconnue : message en anglais et code de sortie 1', $unknown);
$missing = cli($root, ['user:2fa-reset', 'nobody@example.test']);
check($missing['code'] === 1 && str_contains($missing['err'], 'User not found: nobody@example.test'), 'utilisateur introuvable : erreur sur la sortie d’erreur', $missing);
check(str_contains(HttpClient::UA, '(+https://wordless.cloud)'), 'User-Agent sortant : wordless.cloud', HttpClient::UA);
I18n::load('en');
check(str_contains(__('Votre serveur ignore le fichier .htaccess (Nginx ?). Ajoutez les règles indiquées dans Réglages › Santé du système, sinon votre base de données peut être téléchargée.'), 'Settings › System health'), 'santé du site : renvoie vers Settings › System health');
check(__('Vide = tous les administrateurs du site') === 'Empty = all site administrators', 'formulaire de contact : aide conforme à l’envoi (tous les administrateurs)');

$errors = glob($root . '/data/logs/error-*.log') ?: [];
check($errors === [] || trim((string) file_get_contents($errors[0])) === '', 'aucune erreur PHP journalisée', $errors ? (string) file_get_contents($errors[0]) : null);

echo "\n" . ($pass + $fail) . ' vérifications, ' . $fail . " échec(s).\n";
exit($fail > 0 ? 1 : 0);
