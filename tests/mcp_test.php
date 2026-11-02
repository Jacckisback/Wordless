<?php
declare(strict_types=1);

/**
 * Test de bout en bout du connecteur IA de Wordless (MCP + OAuth 2.1).
 *
 *   php tests/mcp_test.php            (KEEP=1 pour conserver le dossier temporaire)
 *
 * Copie Wordless dans un dossier temporaire, l'installe, lance `php -S` sur
 * 127.0.0.1:8093 (le site) et 127.0.0.1:8094 (image de test), un petit serveur
 * HTTPS sur 127.0.0.1:8095 (fiches de clients « Client ID Metadata Documents »),
 * puis rejoue les parcours de Claude.ai / ChatGPT (inscription dynamique ou fiche
 * publiée, consentement, PKCE), de Claude Code (clé API) et les cas d'erreur.
 * Code de sortie 1 en cas d'échec.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const PORT = 8093;
const ASSET_PORT = 8094;
const TLS_PORT = 8095;
const CIMD_BASE = 'https://127.0.0.1:8095';
const BASE = 'http://127.0.0.1:8093';
const ADMIN_EMAIL = 'admin@example.test';
const ADMIN_PASSWORD = 'Correct-horse-battery-staple-42';
const CLAUDE_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static string $root = '';
    public static string $assets = '';
    /** Dossier servi en HTTPS (fiches CIMD) */
    public static string $cimd = '';
    /** @var list<resource> */
    public static array $procs = [];
    public static ?PDO $db = null;

    public static function check(bool $ok, string $label, mixed $debug = null): bool
    {
        if ($ok) {
            self::$pass++;
            echo "  ok    $label\n";
            return true;
        }
        self::$fail++;
        echo "  FAIL  $label\n";
        if ($debug !== null) {
            $d = is_string($debug) ? $debug : (string) json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo '        ' . substr(str_replace("\n", "\n        ", $d), 0, 2500) . "\n";
        }
        return false;
    }

    public static function section(string $title): void
    {
        echo "\n== $title\n";
    }
}

// ----------------------------------------------------------------------
// Outils
// ----------------------------------------------------------------------

function copy_tree(string $from, string $to, array $skipTop): void
{
    mkdir($to, 0775, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($from) + 1);
        if (in_array(explode('/', $rel)[0], $skipTop, true)) {
            continue;
        }
        $dest = $to . '/' . $rel;
        if ($file->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0775, true);
            }
        } else {
            copy($file->getPathname(), $dest);
        }
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

function start_server(int $port, string $cwd, array $args, array $env): void
{
    $log = $cwd . '/../' . basename($cwd) . '-' . $port . '.log';
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, ...$args], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Impossible de lancer php -S sur le port ' . $port);
    }
    T::$procs[] = $proc;
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('Le serveur ne répond pas sur le port ' . $port);
}

/** @return array{status: int, headers: array<string, list<string>>, body: string, json: mixed} */
function req(string $method, string $path, array $headers = [], string|array|null $body = null, ?string $cookie = null): array
{
    $url = str_starts_with($path, 'http') ? $path : BASE . $path;
    if (is_array($body)) {
        $body = http_build_query($body);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookie !== null) {
        $headers[] = 'Cookie: wl_s=' . $cookie;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
    ]);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('HTTP ' . $method . ' ' . $url . ' : ' . curl_error($ch));
    }
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $out = ['status' => $status, 'headers' => [], 'body' => (string) substr((string) $raw, $size), 'json' => null];
    foreach (explode("\r\n", substr((string) $raw, 0, $size)) as $line) {
        if (str_contains($line, ':') && !str_starts_with($line, 'HTTP/')) {
            [$k, $v] = explode(':', $line, 2);
            $out['headers'][strtolower(trim($k))][] = trim($v);
        }
    }
    $out['json'] = json_decode($out['body'], true);
    return $out;
}

function hdr(array $r, string $name): string
{
    return implode(', ', $r['headers'][strtolower($name)] ?? []);
}

function location_params(array $r): array
{
    $q = (string) parse_url(hdr($r, 'Location'), PHP_URL_QUERY);
    parse_str($q, $p);
    return $p;
}

function mcp_headers(string $token, array $extra = []): array
{
    return array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $token], $extra);
}

function rpc(string $token, string $method, mixed $params = null, int|string|null $id = 1, array $extra = []): array
{
    $msg = ['jsonrpc' => '2.0', 'method' => $method];
    if ($id !== null) {
        $msg['id'] = $id;
    }
    if ($params !== null) {
        $msg['params'] = $params;
    }
    return req('POST', '/mcp', mcp_headers($token, $extra ?: ['MCP-Protocol-Version: 2025-06-18']), (string) json_encode($msg, JSON_UNESCAPED_UNICODE));
}

/** @return array{http: array, isError: ?bool, data: ?array, text: string} */
function tool(string $token, string $name, array $args = []): array
{
    $r = rpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) $args]);
    $result = $r['json']['result'] ?? null;
    return [
        'http' => $r,
        'isError' => is_array($result) ? ($result['isError'] ?? false) : null,
        'data' => is_array($result) ? ($result['structuredContent'] ?? null) : null,
        'text' => is_array($result) ? (string) ($result['content'][0]['text'] ?? '') : '',
    ];
}

/** @return array{0: string, 1: string} [code_verifier, code_challenge] */
function pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

function authorize_params(string $clientId, string $redirect, string $challenge, string $state = 'state-123'): array
{
    return [
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'code_challenge' => $challenge,
        'code_challenge_method' => 'S256', 'state' => $state, 'scope' => 'wordless', 'resource' => BASE . '/mcp',
    ];
}

/** Horodatage signé de l'écran de consentement (champ caché _t). */
function consent_stamp(string $html): string
{
    return preg_match('/name="_t" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
}

/** Consentement accordé comme un humain : affichage, lecture (> 1 s), envoi du formulaire. */
function approve(array $params, string $cookie, string $csrf): array
{
    $page = req('GET', '/oauth/authorize?' . http_build_query($params), [], null, $cookie);
    usleep(1_100_000);
    return req('POST', '/oauth/authorize', [], $params + ['_csrf' => $csrf, 'decision' => 'approve', '_t' => consent_stamp($page['body'])], $cookie);
}

function token_request(array $form, array $headers = []): array
{
    return req('POST', '/oauth/token', $headers, $form);
}

/** Inscription + consentement + échange : renvoie la réponse du point d'accès token. */
function full_oauth(string $clientId, string $cookie, string $csrf): array
{
    [$verifier, $challenge] = pkce();
    $r = approve(authorize_params($clientId, CLAUDE_REDIRECT, $challenge), $cookie, $csrf);
    $code = location_params($r)['code'] ?? '';
    return token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CLAUDE_REDIRECT, 'client_id' => $clientId, 'code_verifier' => $verifier]);
}

function probe(string ...$args): mixed
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/mcp-probe.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = shell_exec($cmd . ' 2>&1');
    return json_decode((string) $out, true);
}

function set_setting(string $key, mixed $value): void
{
    T::$db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, json_encode($value)]);
}

/** Certificat auto-signé pour 127.0.0.1 (serveur HTTPS de test), sans outil externe. */
function make_tls_cert(string $dir): bool
{
    if (!function_exists('openssl_pkey_new')) {
        return false;
    }
    mkdir($dir, 0775, true);
    $cnf = $dir . '/openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n[v3]\nsubjectAltName = IP:127.0.0.1\nbasicConstraints = critical, CA:TRUE\n"
        . "keyUsage = critical, digitalSignature, keyEncipherment, keyCertSign\nextendedKeyUsage = serverAuth\n");
    $opts = ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($opts);
    $csr = $key ? openssl_csr_new(['commonName' => '127.0.0.1'], $key, $opts) : false;
    $crt = $csr ? openssl_csr_sign($csr, null, $key, 2, $opts, random_int(1, PHP_INT_MAX)) : false;
    if (!$crt || !openssl_x509_export($crt, $pem) || !openssl_pkey_export($key, $keyPem, null, $opts)) {
        return false;
    }
    file_put_contents($dir . '/ca.pem', $pem);
    file_put_contents($dir . '/server.pem', $pem . $keyPem);
    // Serveur HTTPS minimal : sert les fichiers du dossier, statut et en-têtes dans <fichier>.status
    // et <fichier>.headers, et note chaque chemin demandé dans requests.log.
    file_put_contents($dir . '/server.php', <<<'PHP'
<?php
[, $port, $root, $cert] = $argv;
$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
    fwrite(STDERR, $errstr . "\n");
    exit(1);
}
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && strlen($head) < 16384 && ($line = fgets($conn)) !== false) {
        $head .= $line;
    }
    $path = preg_match('#^GET (\S+)#', $head, $m) ? (string) parse_url($m[1], PHP_URL_PATH) : '';
    file_put_contents($root . '/requests.log', $path . "\n", FILE_APPEND);
    $file = $root . '/' . basename($path);
    $status = is_file($file . '.status') ? trim((string) file_get_contents($file . '.status')) : (is_file($file) ? '200 OK' : '404 Not Found');
    $headers = is_file($file . '.headers') ? trim((string) file_get_contents($file . '.headers')) : 'Content-Type: text/plain';
    $body = is_file($file) ? (string) file_get_contents($file) : 'not found';
    @fwrite($conn, "HTTP/1.1 $status\r\n$headers\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    @fclose($conn);
}
PHP);
    return true;
}

function start_tls_server(string $dir, array $env): void
{
    $log = T::$root . '-' . TLS_PORT . '.log';
    $proc = proc_open([PHP_BINARY, $dir . '/server.php', (string) TLS_PORT, T::$cimd, $dir . '/server.pem'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Impossible de lancer le serveur HTTPS de test');
    }
    T::$procs[] = $proc;
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', TLS_PORT, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('Le serveur HTTPS de test ne répond pas');
}

/** Publie une fiche (ou une réponse quelconque) sur le serveur HTTPS de test. */
function cimd_file(string $name, array|string $doc, string $headers = 'Content-Type: application/json', string $status = ''): void
{
    $file = T::$cimd . '/' . $name;
    file_put_contents($file, is_string($doc) ? $doc : (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($file . '.headers', $headers);
    $status !== '' ? file_put_contents($file . '.status', $status) : @unlink($file . '.status');
}

/** @return list<string> chemins demandés au serveur HTTPS de test, dans l'ordre */
function cimd_fetches(): array
{
    $log = T::$cimd . '/requests.log';
    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
}

// ----------------------------------------------------------------------
// Préparation
// ----------------------------------------------------------------------

$src = dirname(__DIR__);
T::$root = sys_get_temp_dir() . '/wordless-mcp-test-' . bin2hex(random_bytes(4));
T::$assets = T::$root . '-assets';

register_shutdown_function(static function (): void {
    foreach (T::$procs as $p) {
        @proc_terminate($p);
        @proc_close($p);
    }
    if (getenv('KEEP') === '1') {
        echo "\nDossier conservé : " . T::$root . "\n";
        return;
    }
    remove_tree(T::$root);
    remove_tree(T::$assets);
    remove_tree(T::$root . '-sub');
    remove_tree(T::$root . '-cimd');
    remove_tree(T::$root . '-tls');
    foreach (glob(T::$root . '-*.log') ?: [] as $f) {
        @unlink($f);
    }
});

foreach ([PORT, ASSET_PORT, TLS_PORT] as $port) {
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($s) {
        fclose($s);
        fwrite(STDERR, "Le port $port est déjà utilisé.\n");
        exit(1);
    }
}

echo "Wordless MCP — test de bout en bout\nDossier : " . T::$root . "\n";
copy_tree($src, T::$root, ['.git', 'data', 'media', 'tests']);
mkdir(T::$root . '/data', 0775, true);
mkdir(T::$root . '/media', 0775, true);

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/bin/wordless') . ' install --url=' . BASE . ' --email=' . ADMIN_EMAIL
    . ' --password=' . escapeshellarg(ADMIN_PASSWORD) . ' --site=' . escapeshellarg('Atelier Test') . ' --name=Camille --lang=fr 2>&1', $installOut, $code);
if ($code !== 0) {
    fwrite(STDERR, "Installation impossible :\n" . implode("\n", $installOut) . "\n");
    exit(1);
}
$config = include T::$root . '/data/config.php';
T::$db = new PDO('sqlite:' . T::$root . '/data/' . $config['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
T::$db->exec('PRAGMA busy_timeout = 5000');
$adminId = (int) T::$db->query("SELECT id FROM users WHERE email = '" . ADMIN_EMAIL . "'")->fetchColumn();

file_put_contents(T::$root . '/mcp-probe.php', <<<'PHP'
<?php
declare(strict_types=1);
define('WL_ROOT', __DIR__);
require WL_ROOT . '/core/bootstrap.php';
Wordless\App::boot();
$a = array_slice($argv, 1);
$out = match ($a[0] ?? '') {
    'create-key' => Wordless\Mcp\ApiKeys::create((int) $a[1], (string) $a[2]),
    'keys' => Wordless\Mcp\ApiKeys::list((int) $a[1]),
    'revoke-key' => (Wordless\Mcp\ApiKeys::revoke((string) $a[1], (int) $a[2]) ?? 'ok'),
    'clients' => Wordless\Mcp\OAuth::clients((int) $a[1]),
    'revoke-client' => (Wordless\Mcp\OAuth::revokeClient((string) $a[1], (int) $a[2]) ?? 'ok'),
    default => null,
};
echo json_encode($out);
PHP);

mkdir(T::$assets, 0775, true);
$hasGd = function_exists('imagecreatetruecolor') && function_exists('imagepng');
if ($hasGd) {
    $img = imagecreatetruecolor(900, 600);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 44, 106, 77));
    imagefilledrectangle($img, 100, 100, 500, 400, (int) imagecolorallocate($img, 240, 178, 50));
    imagepng($img, T::$assets . '/velo-atelier.png');
    copy(T::$assets . '/velo-atelier.png', T::$assets . '/vélo-atelier.png');
}

$env = array_merge(getenv(), ['WL_ALLOW_PRIVATE_FETCH' => '1']);
// Serveur HTTPS des fiches CIMD : son certificat est la seule autorité reconnue par le site de test.
T::$cimd = T::$root . '-cimd';
mkdir(T::$cimd, 0775, true);
$tls = T::$root . '-tls';
$tlsReady = make_tls_cert($tls);
$siteArgs = $tlsReady ? ['-d', 'curl.cainfo=' . $tls . '/ca.pem', '-d', 'openssl.cafile=' . $tls . '/ca.pem', 'router.php'] : ['router.php'];
start_server(PORT, T::$root, $siteArgs, $env);
start_server(ASSET_PORT, T::$assets, ['-t', T::$assets], $env);
if ($tlsReady) {
    start_tls_server($tls, $env);
}

// ----------------------------------------------------------------------
T::section('Métadonnées OAuth (RFC 9728, RFC 8414)');
// ----------------------------------------------------------------------

$r = req('GET', '/.well-known/oauth-protected-resource');
T::check($r['status'] === 200 && ($r['json']['resource'] ?? '') === BASE . '/mcp', 'ressource protégée : resource = ' . BASE . '/mcp', $r['body']);
T::check(($r['json']['authorization_servers'] ?? []) === [BASE] && ($r['json']['bearer_methods_supported'] ?? []) === ['header'] && ($r['json']['scopes_supported'] ?? []) === ['wordless'], 'ressource protégée : serveur d’autorisation, méthodes, portées');
T::check(($r['json']['resource_name'] ?? '') === 'Atelier Test', 'ressource protégée : nom du site');
T::check(hdr($r, 'Access-Control-Allow-Origin') === '*', 'CORS ouvert sur les métadonnées');
$r = req('GET', '/.well-known/oauth-protected-resource/mcp');
T::check($r['status'] === 200 && ($r['json']['resource'] ?? '') === BASE . '/mcp', 'ressource protégée (chemin /mcp inséré)');

$r = req('GET', '/.well-known/oauth-authorization-server');
$meta = $r['json'] ?? [];
T::check($r['status'] === 200 && ($meta['issuer'] ?? '') === BASE, 'serveur d’autorisation : issuer', $r['body']);
T::check(($meta['authorization_endpoint'] ?? '') === BASE . '/oauth/authorize' && ($meta['token_endpoint'] ?? '') === BASE . '/oauth/token'
    && ($meta['registration_endpoint'] ?? '') === BASE . '/oauth/register' && ($meta['revocation_endpoint'] ?? '') === BASE . '/oauth/revoke', 'serveur d’autorisation : points d’accès');
T::check(($meta['code_challenge_methods_supported'] ?? []) === ['S256'] && ($meta['response_types_supported'] ?? []) === ['code']
    && ($meta['grant_types_supported'] ?? []) === ['authorization_code', 'refresh_token'], 'serveur d’autorisation : PKCE S256, code, refresh_token');
T::check(($meta['token_endpoint_auth_methods_supported'] ?? []) === ['none', 'client_secret_post', 'client_secret_basic'] && ($meta['authorization_response_iss_parameter_supported'] ?? false) === true, 'serveur d’autorisation : authentification des clients, paramètre iss');
$r = req('GET', '/.well-known/openid-configuration');
T::check($r['status'] === 200 && ($r['json']['issuer'] ?? '') === BASE && ($r['json']['token_endpoint'] ?? '') === BASE . '/oauth/token', 'openid-configuration : même document');
$r = req('OPTIONS', '/oauth/token', ['Origin: http://localhost:6274', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type, mcp-protocol-version']);
T::check($r['status'] === 204 && str_contains(hdr($r, 'Access-Control-Allow-Headers'), 'MCP-Protocol-Version') && hdr($r, 'Access-Control-Allow-Origin') === '*', 'pré-vérification CORS du point d’accès token');

// ----------------------------------------------------------------------
T::section('MCP sans authentification');
// ----------------------------------------------------------------------

$init = ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test-client', 'version' => '1.0']];
$r = req('POST', '/mcp', ['Content-Type: application/json'], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $init]));
T::check($r['status'] === 401, 'POST /mcp sans jeton : 401', $r['body']);
T::check(hdr($r, 'WWW-Authenticate') === 'Bearer resource_metadata="' . BASE . '/.well-known/oauth-protected-resource"', 'WWW-Authenticate avec resource_metadata', hdr($r, 'WWW-Authenticate'));
T::check(str_contains(hdr($r, 'Access-Control-Expose-Headers'), 'WWW-Authenticate'), 'WWW-Authenticate exposé en CORS');
$r = rpc('wlat_jeton-invalide', 'initialize', $init);
T::check($r['status'] === 401 && str_contains(hdr($r, 'WWW-Authenticate'), 'error="invalid_token"') && str_contains(hdr($r, 'WWW-Authenticate'), 'resource_metadata='), 'jeton invalide : 401 + error="invalid_token"', hdr($r, 'WWW-Authenticate'));
$r = req('GET', '/mcp', ['Accept: text/event-stream']);
T::check($r['status'] === 405 && hdr($r, 'Allow') === 'POST', 'GET /mcp : 405, Allow: POST', $r['status']);
$r = req('OPTIONS', '/mcp', ['Origin: http://localhost:6274', 'Access-Control-Request-Method: POST']);
T::check($r['status'] === 204 && str_contains(hdr($r, 'Access-Control-Allow-Headers'), 'Mcp-Session-Id'), 'OPTIONS /mcp : 204 + en-têtes CORS');
$r = req('POST', '/mcp', ['Content-Type: application/json', 'Origin: https://evil.example'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
T::check($r['status'] === 403, 'Origin inconnue : 403 (protection DNS rebinding)', $r['status']);

// ----------------------------------------------------------------------
T::section('Inscription dynamique des clients (RFC 7591)');
// ----------------------------------------------------------------------

$r = req('POST', '/oauth/register', ['Content-Type: application/json'], (string) json_encode([
    'client_name' => 'Claude', 'redirect_uris' => [CLAUDE_REDIRECT], 'token_endpoint_auth_method' => 'none',
    'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
]));
$claudeId = (string) ($r['json']['client_id'] ?? '');
T::check($r['status'] === 201 && $claudeId !== '' && !isset($r['json']['client_secret']), 'client public inscrit (201, pas de secret)', $r['body']);
T::check(($r['json']['redirect_uris'] ?? []) === [CLAUDE_REDIRECT] && ($r['json']['token_endpoint_auth_method'] ?? '') === 'none' && is_int($r['json']['client_id_issued_at'] ?? null), 'réponse d’inscription complète');
T::check(hdr($r, 'Access-Control-Allow-Origin') === '*' && str_contains(hdr($r, 'Cache-Control'), 'no-store'), 'inscription : CORS et no-store');
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], '{"redirect_uris":["http://evil.example/callback"]}');
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_redirect_uri', 'http hors localhost refusé', $r['body']);
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], '{"redirect_uris":["https://app.example/cb#frag"]}');
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_redirect_uri', 'fragment refusé');
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], '{"client_name":"x"}');
T::check($r['status'] === 400, 'redirect_uris obligatoire');
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], '{nope');
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_client_metadata', 'JSON invalide refusé');
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], (string) json_encode([
    'client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:33418/callback'], 'token_endpoint_auth_method' => 'client_secret_basic',
]));
$codeClientId = (string) ($r['json']['client_id'] ?? '');
$codeClientSecret = (string) ($r['json']['client_secret'] ?? '');
T::check($r['status'] === 201 && $codeClientSecret !== '' && ($r['json']['client_secret_expires_at'] ?? null) === 0, 'client confidentiel : secret délivré une fois', $r['body']);
$stored = T::$db->query("SELECT secret FROM oauth_clients WHERE id = " . T::$db->quote($codeClientId))->fetchColumn();
T::check($stored === hash('sha256', $codeClientSecret), 'secret du client stocké haché (SHA-256)');
$r = req('POST', '/oauth/register', ['Content-Type: application/json'], (string) json_encode([
    'client_name' => '<script>alert(1)</script>Évil', 'redirect_uris' => ['https://evil.example/cb'], 'token_endpoint_auth_method' => 'none',
]));
$evilId = (string) ($r['json']['client_id'] ?? '');

// ----------------------------------------------------------------------
T::section('Autorisation et consentement');
// ----------------------------------------------------------------------

[$verifier, $challenge] = pkce();
$params = authorize_params($claudeId, CLAUDE_REDIRECT, $challenge);
$r = req('GET', '/oauth/authorize?' . http_build_query($params));
$expected = '/admin/login?redirect=' . rawurlencode('/oauth/authorize?' . http_build_query($params));
T::check($r['status'] === 302 && hdr($r, 'Location') === $expected, 'non connecté : redirection vers la connexion avec ?redirect=', hdr($r, 'Location'));
$login = req('GET', hdr($r, 'Location'));
if ($login['status'] === 200 && preg_match('/name="_pre" value="([^"]+)"/', $login['body'], $pre) && preg_match('/name="redirect" value="([^"]*)"/', $login['body'], $back)) {
    preg_match('/wl_pre=([a-f0-9]+)/', hdr($login, 'Set-Cookie'), $preCookie);
    $r2 = req('POST', '/admin/login', ['Cookie: wl_pre=' . ($preCookie[1] ?? '')], [
        'email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD, '_pre' => html_entity_decode($pre[1]), 'redirect' => html_entity_decode($back[1]),
    ]);
    preg_match('/wl_s=([^;]+)/', hdr($r2, 'Set-Cookie'), $sessionCookie);
    T::check($r2['status'] === 302 && hdr($r2, 'Location') === '/oauth/authorize?' . http_build_query($params) && ($sessionCookie[1] ?? '') !== '', 'vraie page de connexion : retour à l’autorisation', [$r2['status'], hdr($r2, 'Location')]);
    $r2 = req('GET', hdr($r2, 'Location'), [], null, $sessionCookie[1] ?? '');
    T::check($r2['status'] === 200 && str_contains($r2['body'], 'value="approve"'), 'après connexion : écran de consentement', $r2['status']);
} else {
    echo "  (page de connexion de l’administration indisponible : parcours de connexion réel ignoré)\n";
}
$r = req('GET', '/oauth/authorize?' . http_build_query(['client_id' => 'inconnu'] + $params));
T::check($r['status'] === 400 && hdr($r, 'Location') === '' && str_contains($r['body'], 'Application inconnue'), 'client inconnu : page d’erreur, jamais de redirection', $r['status']);
$r = req('GET', '/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://attacker.example/cb'] + $params));
T::check($r['status'] === 400 && hdr($r, 'Location') === '', 'adresse de retour non inscrite : page d’erreur', $r['status']);
$noPkce = $params;
unset($noPkce['code_challenge']);
$r = req('GET', '/oauth/authorize?' . http_build_query($noPkce));
$p = location_params($r);
T::check($r['status'] === 302 && str_starts_with(hdr($r, 'Location'), CLAUDE_REDIRECT . '?') && ($p['error'] ?? '') === 'invalid_request' && ($p['state'] ?? '') === 'state-123' && ($p['iss'] ?? '') === BASE, 'sans PKCE : retour avec error=invalid_request, state et iss', hdr($r, 'Location'));
$r = req('GET', '/oauth/authorize?' . http_build_query(['code_challenge_method' => 'plain'] + $params));
T::check(($r['status'] === 302) && (location_params($r)['error'] ?? '') === 'invalid_request', 'méthode PKCE « plain » refusée');
$r = req('GET', '/oauth/authorize?' . http_build_query(['resource' => 'https://autre.example/mcp'] + $params));
T::check((location_params($r)['error'] ?? '') === 'invalid_target', 'resource étrangère : invalid_target');

// Session d'administrateur simulée (pas encore d'écran de connexion).
$cookie = bin2hex(random_bytes(24));
$csrf = bin2hex(random_bytes(16));
T::$db->prepare('INSERT INTO sessions (id, user_id, csrf, pending_2fa, ip, ua, created_at, seen_at, expires_at) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)')
    ->execute([hash('sha256', $cookie), $adminId, $csrf, '', 'mcp-test', time(), time(), time() + 3600]);

$r = req('GET', '/oauth/authorize?' . http_build_query($params), [], null, $cookie);
T::check($r['status'] === 200 && str_contains($r['body'], 'Claude') && str_contains($r['body'], 'Autoriser') && str_contains($r['body'], 'Refuser'), 'page de consentement affichée', substr($r['body'], 0, 600));
T::check(str_contains($r['body'], 'name="_csrf" value="' . $csrf . '"') && str_contains($r['body'], 'value="' . $challenge . '"') && str_contains($r['body'], 'claude.ai'), 'consentement : CSRF, paramètres d’origine, hôte de retour');
T::check(str_contains($r['body'], 'class="wl-auth"') && str_contains($r['body'], 'auth-card') && str_contains($r['body'], 'checklist') && !preg_match('/<script|style=/i', $r['body']), 'consentement : page autonome sans script ni style en ligne');
$csp = hdr($r, 'Content-Security-Policy');
T::check(str_contains($csp, "form-action 'self' https://claude.ai") && str_contains($csp, "frame-ancestors 'none'") && !str_contains($csp, 'unsafe-inline'), 'consentement : CSP stricte (form-action vers l’origine de retour)', $csp);
T::check(str_contains(hdr($r, 'X-Frame-Options'), 'DENY') && str_contains(hdr($r, 'Cache-Control'), 'no-store'), 'consentement : anti-clickjacking et no-store');
T::check(str_contains($r['body'], 'Administrateur') && str_contains($r['body'], 'Camille'), 'consentement : utilisateur et rôle affichés');
T::check((bool) preg_match('/value="deny" autofocus/', $r['body']), 'consentement : « Refuser » a le focus (Entrée = refus)');
$r = req('GET', '/oauth/authorize?' . http_build_query(authorize_params($evilId, 'https://evil.example/cb', $challenge)), [], null, $cookie);
T::check($r['status'] === 200 && !str_contains($r['body'], '<script>alert') && str_contains($r['body'], 'evil.example'), 'nom d’application hostile neutralisé');

$r = req('POST', '/oauth/authorize', [], $params + ['decision' => 'approve'], $cookie);
T::check($r['status'] === 400 && hdr($r, 'Location') === '', 'approbation sans jeton CSRF refusée');
$r = req('POST', '/oauth/authorize', [], $params + ['_csrf' => $csrf, 'decision' => 'deny'], $cookie);
$p = location_params($r);
T::check($r['status'] === 302 && ($p['error'] ?? '') === 'access_denied' && ($p['state'] ?? '') === 'state-123' && ($p['iss'] ?? '') === BASE, 'refus : error=access_denied + state + iss', hdr($r, 'Location'));
$page = req('GET', '/oauth/authorize?' . http_build_query($params), [], null, $cookie);
$r = req('POST', '/oauth/authorize', [], $params + ['_csrf' => $csrf, 'decision' => 'approve', '_t' => consent_stamp($page['body'])], $cookie);
T::check($r['status'] === 200 && hdr($r, 'Location') === '' && str_contains($r['body'], 'notice--bad') && consent_stamp($r['body']) !== '', 'approbation instantanée (double-clickjacking) : écran remontré avec avertissement', $r['status']);
$r = req('POST', '/oauth/authorize', [], $params + ['_csrf' => $csrf, 'decision' => 'approve', '_t' => '1700000000000.forged-signature-value-xx'], $cookie);
T::check($r['status'] === 200 && hdr($r, 'Location') === '', 'horodatage de consentement falsifié : refusé');
$r = approve($params, $cookie, $csrf);
$p = location_params($r);
$code = (string) ($p['code'] ?? '');
T::check($r['status'] === 302 && str_starts_with(hdr($r, 'Location'), CLAUDE_REDIRECT . '?') && $code !== '' && ($p['state'] ?? '') === 'state-123' && ($p['iss'] ?? '') === BASE, 'approbation : code + state + iss', hdr($r, 'Location'));
T::check((int) T::$db->query("SELECT COUNT(*) FROM tokens WHERE kind = 'oauth_code' AND id = " . T::$db->quote(hash('sha256', $code)))->fetchColumn() === 1
    && (int) T::$db->query("SELECT COUNT(*) FROM tokens WHERE id = " . T::$db->quote($code))->fetchColumn() === 0, 'code stocké sous forme d’empreinte uniquement');

// ----------------------------------------------------------------------
T::section('Échange du code (PKCE)');
// ----------------------------------------------------------------------

$form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => CLAUDE_REDIRECT, 'client_id' => $claudeId];
$r = token_request($form + ['code_verifier' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=')]);
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', 'mauvais code_verifier : invalid_grant', $r['body']);
$r = token_request(['redirect_uri' => 'https://claude.ai/other'] + $form + ['code_verifier' => $verifier]);
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', 'redirect_uri différente : invalid_grant');
$r = token_request($form + ['code_verifier' => $verifier]);
$first = $r['json'] ?? [];
T::check($r['status'] === 200 && str_starts_with((string) ($first['access_token'] ?? ''), 'wlat_') && str_starts_with((string) ($first['refresh_token'] ?? ''), 'wlrt_')
    && ($first['token_type'] ?? '') === 'Bearer' && ($first['expires_in'] ?? 0) === 3600 && ($first['scope'] ?? '') === 'wordless', 'bon code_verifier : jetons délivrés', $r['body']);
T::check(str_contains(hdr($r, 'Cache-Control'), 'no-store') && str_contains(hdr($r, 'Pragma'), 'no-cache'), 'réponse token : Cache-Control no-store, Pragma no-cache');
$r = rpc((string) ($first['access_token'] ?? ''), 'ping');
T::check($r['status'] === 200, 'le jeton d’accès fonctionne', $r['body']);
$r = token_request($form + ['code_verifier' => $verifier]);
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', 'code réutilisé : invalid_grant', $r['body']);
$r = rpc((string) ($first['access_token'] ?? ''), 'ping');
T::check($r['status'] === 401, 'code réutilisé : les jetons émis avec ce code sont révoqués');
$r = token_request(['grant_type' => 'password', 'client_id' => $claudeId]);
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'unsupported_grant_type', 'grant_type non pris en charge');
$r = token_request(['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => 'inconnu', 'code_verifier' => $verifier]);
T::check($r['status'] === 401 && ($r['json']['error'] ?? '') === 'invalid_client', 'client inconnu : 401 invalid_client');

$tokens = full_oauth($claudeId, $cookie, $csrf);
$access = (string) ($tokens['json']['access_token'] ?? '');
T::check($tokens['status'] === 200 && $access !== '', 'nouvelle autorisation : jetons délivrés', $tokens['body']);

// ----------------------------------------------------------------------
T::section('Clients identifiés par une adresse (Client ID Metadata Documents)');
// ----------------------------------------------------------------------

$asMeta = req('GET', '/.well-known/oauth-authorization-server')['json'] ?? [];
T::check(($asMeta['client_id_metadata_document_supported'] ?? null) === true, 'métadonnées : client_id_metadata_document_supported = true');
if (!$tlsReady) {
    echo "  (extension openssl indisponible : parcours de bout en bout ignoré)\n";
} else {
    $cimdId = CIMD_BASE . '/client.json';
    $cimdRedirect = 'https://app.example/callback';
    $cimdDoc = [
        'client_id' => $cimdId, 'client_name' => 'Assistant CIMD', 'client_uri' => CIMD_BASE . '/',
        'redirect_uris' => [$cimdRedirect, 'http://127.0.0.1/callback'], 'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
    ];
    cimd_file('client.json', $cimdDoc, "Content-Type: application/json\r\nCache-Control: max-age=600");
    [$cimdVerifier, $cimdChallenge] = pkce();
    $cimdParams = authorize_params($cimdId, $cimdRedirect, $cimdChallenge);

    $r = req('GET', '/oauth/authorize?' . http_build_query($cimdParams));
    T::check($r['status'] === 302 && str_contains(hdr($r, 'Location'), '/admin/login?redirect=') && cimd_fetches() === [], 'non connecté : connexion d’abord, aucune requête sortante', [$r['status'], cimd_fetches()]);
    $badIds = [
        'http://127.0.0.1:8095/client.json' => 'http', CIMD_BASE . '/client.json#x' => 'fragment', CIMD_BASE . '/a/../client.json' => 'segment ..',
        CIMD_BASE => 'sans chemin', 'https://moi:secret@127.0.0.1:8095/client.json' => 'identifiants dans l’adresse',
    ];
    foreach ($badIds as $badId => $why) {
        $r = req('GET', '/oauth/authorize?' . http_build_query(['client_id' => $badId] + $cimdParams), [], null, $cookie);
        T::check($r['status'] === 400 && hdr($r, 'Location') === '' && str_contains($r['body'], 'Application non vérifiable'), "identifiant refusé ($why) : page d’erreur, pas de redirection", $r['status']);
    }
    T::check(cimd_fetches() === [], 'identifiants refusés : aucune requête sortante', cimd_fetches());

    $r = req('GET', '/oauth/authorize?' . http_build_query($cimdParams), [], null, $cookie);
    T::check($r['status'] === 200 && str_contains($r['body'], 'Assistant CIMD') && str_contains($r['body'], 'value="approve"'), 'fiche lue en HTTPS : consentement au nom déclaré par l’application', substr($r['body'], 0, 500));
    T::check(str_contains($r['body'], 'publiée par : 127.0.0.1') && str_contains($r['body'], 'app.example') && !str_contains($r['body'], 'notice--warn'), 'consentement : hôte de la fiche et hôte de retour affichés');
    T::check(cimd_fetches() === ['/client.json'], 'fiche lue une fois', cimd_fetches());
    $cimdRow = T::$db->query('SELECT * FROM oauth_clients WHERE id = ' . T::$db->quote($cimdId))->fetch() ?: [];
    $cimdData = json_decode((string) ($cimdRow['data'] ?? '{}'), true);
    T::check(array_key_exists('secret', $cimdRow) && $cimdRow['secret'] === null && !empty($cimdData['cimd']) && abs((int) ($cimdData['expires_at'] ?? 0) - (time() + 600)) <= 5, 'fiche gardée en cache selon Cache-Control: max-age=600', $cimdRow);

    $r = approve($cimdParams, $cookie, $csrf);
    $cimdCode = (string) (location_params($r)['code'] ?? '');
    T::check($r['status'] === 302 && str_starts_with(hdr($r, 'Location'), $cimdRedirect . '?') && $cimdCode !== '' && (location_params($r)['iss'] ?? '') === BASE, 'approbation : code renvoyé à une adresse de la fiche', hdr($r, 'Location'));
    $r = token_request(['grant_type' => 'authorization_code', 'code' => $cimdCode, 'redirect_uri' => $cimdRedirect, 'client_id' => $cimdId, 'code_verifier' => $cimdVerifier, 'resource' => BASE . '/mcp']);
    $cimdTokens = $r['json'] ?? [];
    T::check($r['status'] === 200 && str_starts_with((string) ($cimdTokens['access_token'] ?? ''), 'wlat_'), 'échange du code avec l’adresse comme client_id', $r['body']);
    T::check(rpc((string) ($cimdTokens['access_token'] ?? ''), 'ping')['status'] === 200, 'le jeton d’accès fonctionne sur /mcp');
    $r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($cimdTokens['refresh_token'] ?? ''), 'client_id' => $cimdId]);
    $cimdAccess = (string) ($r['json']['access_token'] ?? '');
    T::check($r['status'] === 200 && $cimdAccess !== '', 'rafraîchissement avec l’adresse comme client_id', $r['body']);
    T::check(cimd_fetches() === ['/client.json'], 'cache utilisé : approbation et jetons sans nouvelle lecture', cimd_fetches());
    $apps = probe('clients', (string) $adminId);
    $cimdApp = array_values(array_filter(is_array($apps) ? $apps : [], static fn ($a) => ($a['id'] ?? '') === $cimdId))[0] ?? [];
    T::check(($cimdApp['name'] ?? '') === 'Assistant CIMD' && ($cimdApp['publisher'] ?? '') === '127.0.0.1', 'applications connectées : nom et hôte de la fiche', $apps);

    $r = req('GET', '/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://attacker.example/cb'] + $cimdParams), [], null, $cookie);
    T::check($r['status'] === 400 && hdr($r, 'Location') === '' && str_contains($r['body'], 'Adresse de retour refusée'), 'adresse de retour absente de la fiche : refusée');
    $r = req('GET', '/oauth/authorize?' . http_build_query(['redirect_uri' => 'http://127.0.0.1:43123/callback'] + $cimdParams), [], null, $cookie);
    T::check($r['status'] === 200 && str_contains($r['body'], 'notice--warn') && str_contains($r['body'], 'N’importe quel programme'), 'retour vers l’ordinateur (port libre, RFC 8252) : avertissement renforcé', $r['status']);

    // Fiche modifiée : relue seulement quand le cache a expiré.
    cimd_file('client.json', ['redirect_uris' => ['https://app.example/autre']] + $cimdDoc, "Content-Type: application/json\r\nCache-Control: no-store");
    $r = req('GET', '/oauth/authorize?' . http_build_query($cimdParams), [], null, $cookie);
    T::check($r['status'] === 200 && count(cimd_fetches()) === 1, 'cache valide : l’ancienne fiche sert encore');
    T::$db->exec("UPDATE oauth_clients SET data = json_set(data, '$.expires_at', 0) WHERE id = " . T::$db->quote($cimdId));
    $r = req('GET', '/oauth/authorize?' . http_build_query($cimdParams), [], null, $cookie);
    T::check($r['status'] === 400 && str_contains($r['body'], 'Adresse de retour refusée') && count(cimd_fetches()) === 2, 'cache expiré : fiche relue, nouvelles adresses appliquées', cimd_fetches());
    $r = req('GET', '/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://app.example/autre'] + $cimdParams), [], null, $cookie);
    T::check($r['status'] === 200 && count(cimd_fetches()) === 3, 'Cache-Control: no-store : fiche relue à chaque autorisation', cimd_fetches());
    T::check(rpc($cimdAccess, 'ping')['status'] === 200, 'fiche modifiée : les jetons déjà délivrés restent valables');

    $refused = [
        'mismatch.json' => [['client_id' => CIMD_BASE . '/autre.json'] + $cimdDoc, '', 'client_id'],
        'secret.json' => [['client_id' => CIMD_BASE . '/secret.json', 'token_endpoint_auth_method' => 'client_secret_basic'] + $cimdDoc, '', 'client_secret_basic'],
        'withsecret.json' => [['client_id' => CIMD_BASE . '/withsecret.json', 'client_secret' => 'abc'] + $cimdDoc, '', 'secret'],
        'noname.json' => [array_diff_key(['client_id' => CIMD_BASE . '/noname.json'] + $cimdDoc, ['client_name' => true]), '', 'client_name'],
        'badredirect.json' => [['client_id' => CIMD_BASE . '/badredirect.json', 'redirect_uris' => ['http://evil.example/cb']] + $cimdDoc, '', 'evil.example'],
        'big.json' => [['client_id' => CIMD_BASE . '/big.json', 'client_name' => str_repeat('x', 6000)] + $cimdDoc, '', 'volumineux'],
        'redirect.json' => ['', '302 Found', 'code HTTP 302'],
        'notjson.json' => ['ceci n’est pas du JSON', '', 'JSON'],
        'missing.json' => [null, '', 'code HTTP 404'],
    ];
    foreach ($refused as $file => [$doc, $status, $expect]) {
        if ($doc !== null) {
            cimd_file($file, $doc, $file === 'redirect.json' ? 'Location: ' . $cimdId : 'Content-Type: application/json', $status);
        }
        $r = req('GET', '/oauth/authorize?' . http_build_query(['client_id' => CIMD_BASE . '/' . $file] + $cimdParams), [], null, $cookie);
        T::check($r['status'] === 400 && hdr($r, 'Location') === '' && str_contains($r['body'], 'Application non vérifiable') && str_contains($r['body'], $expect), "fiche refusée ($file) : « $expect »", substr(strip_tags($r['body']), 0, 600));
    }
    $fetched = cimd_fetches();
    T::check(count($fetched) === 3 + count($refused) && end($fetched) === '/missing.json' && !in_array('/client.json', array_slice($fetched, 3), true), 'redirection jamais suivie (une lecture par fiche)', $fetched);
    T::check((int) T::$db->query("SELECT COUNT(*) FROM oauth_clients WHERE id LIKE 'https://%'")->fetchColumn() === 1, 'fiches refusées jamais gardées en cache');
    $r = token_request(['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => CIMD_BASE . '/jamais.json', 'code_verifier' => $cimdVerifier]);
    T::check($r['status'] === 401 && ($r['json']['error'] ?? '') === 'invalid_client' && !in_array('/jamais.json', cimd_fetches(), true), 'point d’accès token : adresse jamais autorisée = invalid_client, sans requête sortante', $r['body']);
}

// ----------------------------------------------------------------------
T::section('Protocole MCP');
// ----------------------------------------------------------------------

$r = rpc($access, 'initialize', $init, 1, ['Origin: https://claude.ai']);
$res = $r['json']['result'] ?? [];
T::check($r['status'] === 200 && ($res['protocolVersion'] ?? '') === '2025-06-18', 'initialize : version négociée = celle du client', $r['body']);
T::check(($res['serverInfo']['name'] ?? '') === 'wordless' && ($res['serverInfo']['title'] ?? '') === 'Atelier Test' && ($res['serverInfo']['version'] ?? '') !== '', 'initialize : serverInfo');
T::check(($res['capabilities']['tools']['listChanged'] ?? null) === false && str_contains($r['body'], '"resources":{}') && str_contains($r['body'], '"prompts":{}'), 'initialize : capacités tools/resources/prompts');
T::check(str_contains((string) ($res['instructions'] ?? ''), 'French') && str_contains((string) ($res['instructions'] ?? ''), 'FAQ') && str_contains((string) ($res['instructions'] ?? ''), 'draft'), 'initialize : instructions (langue, GEO, brouillons)');
T::check(strlen(hdr($r, 'Mcp-Session-Id')) >= 20, 'initialize : en-tête Mcp-Session-Id', hdr($r, 'Mcp-Session-Id'));
$r = rpc($access, 'initialize', ['protocolVersion' => '2099-01-01'] + $init);
T::check(($r['json']['result']['protocolVersion'] ?? '') === '2025-11-25', 'version inconnue : la plus récente est proposée');
foreach (['2025-11-25', '2025-03-26'] as $v) {
    $r = rpc($access, 'initialize', ['protocolVersion' => $v] + $init, 1, ['MCP-Protocol-Version: ' . $v]);
    T::check(($r['json']['result']['protocolVersion'] ?? '') === $v, 'version ' . $v . ' acceptée');
}
$r = rpc($access, 'ping', null, 7, ['MCP-Protocol-Version: 1999-01-01']);
T::check($r['status'] === 400, 'en-tête MCP-Protocol-Version non pris en charge : 400');
$r = rpc($access, 'notifications/initialized', null, null);
T::check($r['status'] === 202 && $r['body'] === '', 'notification : 202 sans corps', $r['status'] . ' ' . $r['body']);
$r = rpc($access, 'ping', null, 'p-1');
T::check($r['status'] === 200 && str_contains($r['body'], '"result":{}') && ($r['json']['id'] ?? null) === 'p-1', 'ping : {} et id texte renvoyé', $r['body']);
$r = rpc($access, 'nope/method', null, 3);
T::check(($r['json']['error']['code'] ?? 0) === -32601 && ($r['json']['id'] ?? null) === 3, 'méthode inconnue : -32601');
$r = req('POST', '/mcp', mcp_headers($access), '{"jsonrpc":"2.0","id":1,"method":');
T::check($r['status'] === 400 && ($r['json']['error']['code'] ?? 0) === -32700 && array_key_exists('id', $r['json'] ?? []) && $r['json']['id'] === null, 'JSON invalide : -32700', $r['body']);
$r = req('POST', '/mcp', mcp_headers($access), '{"jsonrpc":"1.0","id":1,"method":"ping"}');
T::check($r['status'] === 400 && ($r['json']['error']['code'] ?? 0) === -32600, 'requête invalide : -32600');
$r = req('POST', '/mcp', mcp_headers($access), '[]');
T::check($r['status'] === 400 && ($r['json']['error']['code'] ?? 0) === -32600, 'lot vide : -32600');
$r = req('POST', '/mcp', mcp_headers($access), '{"jsonrpc":"2.0","id":9,"result":{}}');
T::check($r['status'] === 202, 'réponse envoyée par le client : 202');
$r = req('POST', '/mcp', mcp_headers($access, ['Origin: http://localhost:6274']), (string) json_encode([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'tools/list'],
    ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'inconnue'],
    7,
]));
$batch = $r['json'] ?? [];
T::check($r['status'] === 200 && is_array($batch) && count($batch) === 4, 'lot : 4 réponses (la notification n’en a pas)', $r['body']);
T::check(($batch[0]['id'] ?? null) === 1 && ($batch[1]['id'] ?? null) === 'b' && isset($batch[1]['result']['tools']) && ($batch[2]['error']['code'] ?? 0) === -32601 && ($batch[3]['error']['code'] ?? 0) === -32600, 'lot : réponses dans l’ordre, erreurs individuelles');
$r = req('POST', '/mcp', mcp_headers($access), '[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}]');
T::check($r['status'] === 202 && $r['body'] === '', 'lot de notifications : 202');
$r = req('DELETE', '/mcp', mcp_headers($access));
T::check($r['status'] === 204, 'DELETE /mcp : 204');

$r = rpc($access, 'tools/list');
$tools = [];
foreach ($r['json']['result']['tools'] ?? [] as $t) {
    $tools[$t['name']] = $t;
}
$expectedTools = ['site_overview', 'search', 'fetch', 'list_content', 'get_content', 'create_content', 'update_content', 'publish_content', 'delete_content', 'analyze_content',
    'list_media', 'upload_media', 'update_media', 'list_terms', 'create_term', 'manage_redirects', 'get_analytics', 'list_comments', 'moderate_comment', 'get_design', 'update_design', 'update_site_settings'];
T::check(array_keys($tools) === $expectedTools, 'tools/list : les 22 outils (administrateur)', array_keys($tools));
T::check(($tools['site_overview']['annotations']['readOnlyHint'] ?? null) === true && ($tools['delete_content']['annotations']['destructiveHint'] ?? null) === true
    && ($tools['create_content']['annotations']['readOnlyHint'] ?? null) === false && ($tools['upload_media']['annotations']['openWorldHint'] ?? null) === true, 'annotations readOnly / destructive / openWorld');
T::check(str_contains($r['body'], '"inputSchema":{"type":"object","properties":{}') && ($tools['create_content']['inputSchema']['required'] ?? []) === ['title'], 'schémas : objets JSON, champs obligatoires');

$r = tool($access, 'site_overview');
$ov = $r['data'] ?? [];
T::check($r['isError'] === false && ($ov['site']['name'] ?? '') === 'Atelier Test' && ($ov['site']['language'] ?? '') === 'fr', 'site_overview : site', $r['text']);
T::check(isset($ov['counts']['posts']['published'], $ov['seo_geo']['average_seo_score'], $ov['traffic_30_days']['ai_assistant_referrals'], $ov['traffic_30_days']['ai_crawler_visits'], $ov['issues'], $ov['recently_updated']), 'site_overview : compteurs, SEO/GEO, trafic IA, problèmes, contenus récents', array_keys($ov));
T::check(json_decode($r['text'], true) === $ov, 'résultat texte = contenu structuré (JSON)');

$markdown = <<<'MD'
# Comment choisir un vélo électrique

Un vélo électrique se choisit selon trois critères : l’usage (ville ou randonnée), l’autonomie de la batterie (au moins 60 km pour un usage quotidien) et le budget, qui démarre autour de 1 200 €. Voici comment faire le bon choix en 2026, sans vous tromper.

## Quel budget prévoir pour un vélo électrique ?

Selon l’[ADEME](https://www.ademe.fr/), le prix moyen d’un vélo électrique neuf atteignait 1 900 € en 2025. Comptez :

- 1 200 à 1 800 € pour un vélo de ville ;
- 2 000 à 3 500 € pour un VTT électrique.

## Quelle autonomie choisir ?

Une batterie de 500 Wh offre environ 80 km d’autonomie en mode éco. Pour un trajet de 15 km par jour, elle suffit pour la semaine.

> Une batterie bien entretenue dure au moins cinq ans.
> — Marie Dupont, réparatrice

Pour un conseil personnalisé, passez par notre [page de contact](/contact).

## Questions fréquentes sur le vélo électrique

### Faut-il un permis pour un vélo électrique ?

Non, aucun permis n’est nécessaire tant que l’assistance s’arrête à 25 km/h.

### Combien de temps dure une batterie ?

Entre 500 et 1 000 cycles de charge, soit trois à cinq ans d’usage courant.

### Existe-t-il une aide à l’achat ?

Oui, l’État et de nombreuses collectivités proposent des aides qui peuvent atteindre 400 €.
MD;
$r = tool($access, 'create_content', [
    'title' => 'Comment choisir un vélo électrique',
    'markdown' => $markdown,
    'seo' => ['title' => 'Vélo électrique : comment bien le choisir en 2026', 'description' => 'Budget, autonomie, usage : notre méthode simple pour choisir un vélo électrique adapté à vos trajets, avec les prix 2026 et les aides à l’achat.', 'keyword' => 'vélo électrique'],
    'categories' => ['Mobilité'],
    'tags' => ['vélo', 'électrique'],
    'slug' => 'choisir-velo-electrique',
]);
$article = $r['data'] ?? [];
$articleId = (int) ($article['id'] ?? 0);
T::check($r['isError'] === false && $articleId > 0 && ($article['status'] ?? '') === 'draft', 'create_content : brouillon créé', $r['text']);
T::check(($article['url'] ?? '') === BASE . '/choisir-velo-electrique/' && ($article['admin_url'] ?? '') === BASE . '/admin/content/' . $articleId, 'create_content : adresse publique et lien d’édition', [$article['url'] ?? null, $article['admin_url'] ?? null]);
T::check(isset($article['scores']['seo'], $article['scores']['geo']) && is_array($article['suggestions'] ?? null), 'create_content : scores et suggestions');
T::check(in_array('The leading "# ..." heading was removed: the title is already displayed as the H1.', $article['warnings'] ?? [], true), 'create_content : « # Titre » initial retiré');
$blocks = json_decode((string) T::$db->query('SELECT blocks FROM content WHERE id = ' . $articleId)->fetchColumn(), true);
$faq = array_values(array_filter($blocks, static fn ($b) => $b['type'] === 'faq'))[0] ?? null;
T::check($faq !== null && count($faq['data']['items']) === 3 && $faq['data']['title'] === 'Questions fréquentes sur le vélo électrique', 'create_content : section FAQ convertie en bloc FAQ (3 questions)', $blocks);
$activity = (int) T::$db->query("SELECT COUNT(*) FROM activity WHERE action = 'mcp' AND object_id = $articleId")->fetchColumn();
T::check($activity === 1, 'create_content : action inscrite au journal (mcp)');

$r = tool($access, 'get_content', ['id' => $articleId]);
$c = $r['data'] ?? [];
T::check($r['isError'] === false && str_contains((string) ($c['markdown'] ?? ''), '### Faut-il un permis pour un vélo électrique ?') && ($c['seo']['keyword'] ?? '') === 'vélo électrique', 'get_content : Markdown et champs SEO', $r['text']);
T::check(($c['categories'][0]['name'] ?? '') === 'Mobilité' && count($c['tags'] ?? []) === 2 && isset($c['scores']['seo'], $c['scores']['geo']), 'get_content : catégories, étiquettes, scores');
$r = tool($access, 'get_content', ['url' => BASE . '/choisir-velo-electrique/', 'format' => 'blocks']);
T::check(($r['data']['id'] ?? 0) === $articleId && in_array('faq', array_column($r['data']['blocks'] ?? [], 'type'), true), 'get_content par URL, format blocs');
$r = tool($access, 'get_content', ['id' => 999999]);
T::check($r['isError'] === true && str_contains($r['text'], 'not found'), 'get_content : contenu introuvable => isError');

$r = tool($access, 'analyze_content', ['id' => $articleId]);
$an = $r['data'] ?? [];
$geoFaq = array_values(array_filter($an['geo_checks'] ?? [], static fn ($ch) => $ch['id'] === 'faq'))[0] ?? [];
$sources = array_values(array_filter($an['geo_checks'] ?? [], static fn ($ch) => $ch['id'] === 'sources'))[0] ?? [];
T::check($r['isError'] === false && is_int($an['scores']['seo'] ?? null) && ($geoFaq['status'] ?? '') === 'good' && ($sources['status'] ?? '') === 'good', 'analyze_content : contrôles GEO (FAQ, sources) au vert', $an['geo_checks'] ?? $r['text']);
T::check(count($an['seo_checks'] ?? []) > 5 && isset($an['stats']['words']), 'analyze_content : contrôles SEO et statistiques');

$r = tool($access, 'search', ['query' => 'vélo électrique']);
$ids = array_column($r['data']['results'] ?? [], 'id');
T::check($r['isError'] === false && in_array((string) $articleId, $ids, true), 'search : le brouillon est trouvé', $r['text']);
T::check(json_decode($r['text'], true) === $r['data'] && str_starts_with((string) ($r['data']['results'][0]['text'] ?? ''), '[draft]'), 'search : format OpenAI {"results":[…]} et statut signalé');

$r = tool($access, 'update_content', ['id' => $articleId, 'excerpt' => 'Notre méthode pour bien choisir.', 'seo' => ['keyword' => 'vélo électrique']]);
T::check($r['isError'] === false && ($r['data']['updated'] ?? []) === ['seo', 'excerpt'], 'update_content : mise à jour partielle', $r['text']);
$keptDescription = (string) json_decode((string) T::$db->query('SELECT seo FROM content WHERE id = ' . $articleId)->fetchColumn(), true)['description'];
T::check(str_starts_with($keptDescription, 'Budget, autonomie'), 'update_content : champs SEO fusionnés (description conservée)');
$r = tool($access, 'update_content', ['id' => $articleId]);
T::check($r['isError'] === true && str_contains($r['text'], 'Nothing to update'), 'update_content sans champ : isError');
$r = tool($access, 'update_content', ['id' => $articleId, 'markdown' => 'x', 'blocks' => []]);
T::check($r['isError'] === true, 'update_content : markdown et blocks ensemble refusés');
$r = tool($access, 'update_content', ['id' => $articleId, 'append_markdown' => "## L’essentiel\n\n- Budget : 1 200 € minimum.\n- Autonomie : 60 km au moins."]);
$types = array_column(json_decode((string) T::$db->query('SELECT blocks FROM content WHERE id = ' . $articleId)->fetchColumn(), true), 'type');
T::check($r['isError'] === false && $types === ['text', 'faq', 'text'], 'update_content append_markdown : blocs existants conservés', [$types, $r['text']]);
$revisions = (int) T::$db->query('SELECT COUNT(*) FROM revisions WHERE content_id = ' . $articleId)->fetchColumn();
T::check($revisions >= 2, 'update_content : révisions enregistrées (retour arrière possible)');

$r = tool($access, 'publish_content', ['id' => $articleId]);
T::check($r['isError'] === false && ($r['data']['status'] ?? '') === 'published', 'publish_content : publié', $r['text']);
$page = req('GET', '/choisir-velo-electrique/');
T::check($page['status'] === 200 && str_contains($page['body'], 'Quel budget prévoir'), 'l’article est en ligne');
$r = tool($access, 'search', ['query' => 'autonomie batterie']);
T::check(in_array((string) $articleId, array_column($r['data']['results'] ?? [], 'id'), true), 'search : article publié trouvé (index plein texte)', $r['text']);
$r = tool($access, 'fetch', ['id' => (string) $articleId]);
$f = $r['data'] ?? [];
T::check($r['isError'] === false && ($f['id'] ?? '') === (string) $articleId && str_contains((string) ($f['text'] ?? ''), '## Quel budget prévoir') && ($f['url'] ?? '') === BASE . '/choisir-velo-electrique/' && isset($f['metadata']['seo_title']), 'fetch : {id, title, text, url, metadata}', $r['text']);
$r = tool($access, 'fetch', ['id' => BASE . '/choisir-velo-electrique/']);
T::check(($r['data']['id'] ?? '') === (string) $articleId, 'fetch par URL');
$future = (new DateTimeImmutable('+3 days'))->format(DATE_ATOM);
$r = tool($access, 'create_content', ['title' => 'Entretenir sa batterie', 'markdown' => "Une batterie s’entretient en évitant les charges complètes.\n\n## Pourquoi ?\n\nParce que.", 'status' => 'scheduled', 'publish_at' => $future, 'type' => 'post']);
T::check(($r['data']['status'] ?? '') === 'scheduled', 'create_content programmé (publish_at)', $r['text']);
$r = tool($access, 'create_content', ['title' => 'Mauvaise date', 'publish_at' => 'demain matin']);
T::check($r['isError'] === true && str_contains($r['text'], 'ISO 8601'), 'date invalide : isError explicite');
$r = tool($access, 'create_content', ['markdown' => 'Sans titre']);
T::check($r['isError'] === true && str_contains($r['text'], 'title') && str_contains($r['text'], 'required'), 'argument obligatoire manquant : isError', $r['text']);
$r = tool($access, 'create_content', ['title' => 'x', 'status' => 'publié']);
T::check($r['isError'] === true && str_contains($r['text'], 'must be one of'), 'valeur hors énumération : isError');
$r = tool($access, 'create_content', ['title' => 'x', 'couleur' => 'bleu']);
T::check($r['isError'] === true && str_contains($r['text'], 'unknown property'), 'propriété inconnue : isError');
$r = tool($access, 'create_content', ['title' => 'Avec HTML', 'blocks' => [['type' => 'html', 'data' => ['code' => '<p>Bonjour</p><script>alert(1)</script>']], ['type' => 'heading', 'data' => ['text' => 'Titre', 'level' => '2']]]]);
$htmlBlocks = (string) T::$db->query('SELECT blocks FROM content WHERE id = ' . (int) ($r['data']['id'] ?? 0))->fetchColumn();
T::check($r['isError'] === false && !str_contains($htmlBlocks, '<script') && str_contains($htmlBlocks, 'Bonjour'), 'blocs envoyés par l’IA : HTML nettoyé (jamais de script)', $htmlBlocks);
$r = tool($access, 'create_content', ['title' => 'x', 'blocks' => [['type' => 'carousel', 'data' => []]]]);
T::check($r['isError'] === true && str_contains($r['text'], 'unknown block type'), 'type de bloc inconnu : isError');
$r = rpc($access, 'tools/call', ['name' => 'outil_imaginaire', 'arguments' => new stdClass()]);
T::check(($r['json']['error']['code'] ?? 0) === -32602, 'outil inconnu : erreur JSON-RPC -32602');

$r = tool($access, 'list_content', ['type' => 'post', 'status' => 'any', 'order' => 'seo', 'limit' => 5]);
T::check($r['isError'] === false && ($r['data']['total'] ?? 0) >= 3 && count($r['data']['items'] ?? []) <= 5 && isset($r['data']['items'][0]['seo_score']), 'list_content : filtres et pagination', $r['text']);
$r = tool($access, 'list_content', ['category' => 'mobilite']);
T::check(array_column($r['data']['items'] ?? [], 'id') === [$articleId], 'list_content : filtre par catégorie', $r['text']);

// Design
$r = tool($access, 'get_design');
T::check($r['isError'] === false && isset($r['data']['colors']['text'], $r['data']['available_presets']['atelier'], $r['data']['contrast'][0]['ratio']), 'get_design : jetons, préréglages, contrastes', $r['text']);
$r = tool($access, 'update_design', ['colors' => ['text' => '#cccccc', 'accent' => '#2c6a4d'], 'radius' => 12]);
$d = $r['data'] ?? [];
$fix = $d['contrast_corrections'][0] ?? [];
T::check($r['isError'] === false && ($fix['token'] ?? '') === 'text' && ($fix['requested'] ?? '') === '#cccccc' && ($fix['contrast_after'] ?? 0) >= 4.5, 'update_design : couleur peu lisible corrigée et signalée', $r['text']);
T::check(($d['colors']['text'] ?? '') === ($fix['applied'] ?? 'x') && ($d['radius'] ?? 0) === 12, 'update_design : valeurs appliquées');
$r = tool($access, 'get_design');
$pairs = array_column($r['data']['contrast'] ?? [], 'passes', 'pair');
T::check(($pairs['text_on_bg'] ?? false) === true && ($pairs['muted_on_bg'] ?? false) === true && ($pairs['accent_text_on_accent'] ?? false) === true, 'get_design : contrastes AA respectés après correction');
$r = tool($access, 'update_design', ['colors' => ['bg' => 'pas-une-couleur']]);
T::check($r['isError'] === true && str_contains($r['text'], 'pattern'), 'update_design : couleur invalide refusée');
$r = tool($access, 'update_design', ['preset' => 'nuit', 'colors' => ['accent_text' => '#f0b232']]);
$fixes = array_column($r['data']['contrast_corrections'] ?? [], 'token');
T::check($r['isError'] === false && ($r['data']['preset'] ?? '') === 'nuit' && ($r['data']['scheme'] ?? '') === 'dark' && in_array('accent_text', $fixes, true), 'update_design : préréglage + texte sur couleur d’accent corrigé', $r['text']);

// Médias, catégories, redirections, statistiques, commentaires, réglages
if ($hasGd) {
    $r = tool($access, 'upload_media', ['url' => 'http://127.0.0.1:' . ASSET_PORT . '/velo-atelier.png', 'alt' => 'Vélo électrique garé devant un atelier']);
    $media = $r['data'] ?? [];
    T::check($r['isError'] === false && ($media['id'] ?? 0) > 0 && ($media['width'] ?? 0) === 900 && str_starts_with((string) ($media['markdown'] ?? ''), '![Vélo électrique'), 'upload_media : image importée depuis une URL', $r['text']);
    $r = tool($access, 'update_media', ['id' => (int) ($media['id'] ?? 0), 'alt' => 'Vélo électrique vert devant l’atelier']);
    T::check($r['isError'] === false && ($r['data']['alt'] ?? '') === 'Vélo électrique vert devant l’atelier', 'update_media : texte alternatif', $r['text']);
    $r = tool($access, 'update_content', ['id' => $articleId, 'featured_media_id' => (int) ($media['id'] ?? 0)]);
    $r2 = tool($access, 'get_content', ['id' => $articleId]);
    T::check(($r2['data']['featured_image']['id'] ?? 0) === (int) ($media['id'] ?? -1), 'update_content : image à la une');
    $r = tool($access, 'list_media', ['type' => 'image']);
    T::check(in_array((int) ($media['id'] ?? 0), array_column($r['data']['items'] ?? [], 'id'), true), 'list_media');
    $r = tool($access, 'upload_media', ['url' => 'http://127.0.0.1:' . ASSET_PORT . '/vélo-atelier.png', 'alt' => 'Vélo']);
    T::check($r['isError'] === false && ($r['data']['existing'] ?? false) === true && ($r['data']['id'] ?? 0) === (int) ($media['id'] ?? -1), 'upload_media : URL accentuée acceptée, doublon reconnu', $r['text']);
    T::check(($r['data']['alt'] ?? '') === 'Vélo électrique vert devant l’atelier', 'upload_media : texte alternatif existant conservé');
    $r = tool($access, 'create_content', ['type' => 'page', 'title' => 'Notre atelier', 'markdown' => 'Notre atelier répare tous les vélos.',
        'featured_image_url' => 'http://127.0.0.1:' . ASSET_PORT . '/velo-atelier.png', 'featured_image_alt' => 'Atelier de réparation']);
    $r2 = tool($access, 'get_content', ['id' => (int) ($r['data']['id'] ?? 0)]);
    T::check($r['isError'] === false && ($r2['data']['featured_image']['id'] ?? 0) === (int) ($media['id'] ?? -1) && ($r2['data']['type'] ?? '') === 'page', 'create_content : featured_image_url importée (fichier déjà présent réutilisé)', $r2['text']);
    $r = tool($access, 'create_content', ['title' => 'Image introuvable', 'featured_image_url' => 'http://127.0.0.1:' . ASSET_PORT . '/absente.png']);
    T::check($r['isError'] === false && str_contains(implode(' ', $r['data']['warnings'] ?? []), 'Featured image not imported'), 'create_content : échec d’import signalé sans bloquer la création', $r['text']);
} else {
    echo "  (GD absent : test d’import d’image ignoré)\n";
}
$r = tool($access, 'upload_media', ['url' => 'file:///etc/passwd']);
T::check($r['isError'] === true, 'upload_media : schéma file:// refusé');

$r = tool($access, 'create_term', ['taxonomy' => 'category', 'name' => 'Conseils pratiques', 'description' => 'Nos guides.']);
$termId = (int) ($r['data']['id'] ?? 0);
T::check($r['isError'] === false && ($r['data']['created'] ?? false) === true && ($r['data']['slug'] ?? '') === 'conseils-pratiques', 'create_term', $r['text']);
$r = tool($access, 'create_term', ['taxonomy' => 'category', 'name' => 'Conseils pratiques']);
T::check(($r['data']['created'] ?? true) === false && ($r['data']['id'] ?? 0) === $termId, 'create_term : idempotent');
$r = tool($access, 'list_terms', ['taxonomy' => 'category']);
T::check(in_array('conseils-pratiques', array_column($r['data']['categories'] ?? [], 'slug'), true) && !isset($r['data']['tags']), 'list_terms');

$r = tool($access, 'manage_redirects', ['action' => 'add', 'source' => '/ancien-guide-velo', 'target' => BASE . '/choisir-velo-electrique/']);
T::check($r['isError'] === false && ($r['data']['target'] ?? '') === '/choisir-velo-electrique' && ($r['data']['code'] ?? 0) === 301, 'manage_redirects add (URL du site convertie en chemin)', $r['text']);
$hop = req('GET', '/ancien-guide-velo/'); // le site ajoute d'abord la barre finale (adresse canonique)
T::check($hop['status'] === 301 && str_ends_with(hdr($hop, 'Location'), '/choisir-velo-electrique/'), 'la redirection fonctionne', hdr($hop, 'Location'));
$r = tool($access, 'manage_redirects', ['action' => 'list']);
T::check(in_array('/ancien-guide-velo', array_column($r['data']['redirects'] ?? [], 'source'), true) && isset($r['data']['top_404']), 'manage_redirects list (+ 404 fréquentes)');
$r = tool($access, 'manage_redirects', ['action' => 'add', 'source' => '/boucle', 'target' => '/boucle']);
T::check($r['isError'] === true && str_contains($r['text'], 'loop'), 'manage_redirects : boucle refusée');
$r = tool($access, 'manage_redirects', ['action' => 'add', 'source' => 'https://autre-site.example/x', 'target' => '/']);
T::check($r['isError'] === true, 'manage_redirects : source d’un autre site refusée');
$r = tool($access, 'manage_redirects', ['action' => 'delete', 'source' => '/ancien-guide-velo']);
T::check($r['isError'] === false && ($r['data']['deleted'] ?? false) === true, 'manage_redirects delete');

$r = tool($access, 'get_analytics', ['days' => 7]);
T::check($r['isError'] === false && is_int($r['data']['views'] ?? null) && count($r['data']['daily'] ?? []) === 7 && array_key_exists('ai_crawler_visits', $r['data'] ?? []), 'get_analytics', $r['text']);
T::$db->prepare("INSERT INTO comments (content_id, author, email, body, status, created_at) VALUES (?, 'Lou', 'lou@example.test', '<p>Super article, merci !</p>', 'pending', ?)")->execute([$articleId, time()]);
$commentId = (int) T::$db->lastInsertId();
$r = tool($access, 'list_comments');
T::check($r['isError'] === false && ($r['data']['counts']['pending'] ?? 0) === 1 && ($r['data']['items'][0]['text'] ?? '') === 'Super article, merci !' && !str_contains($r['text'], 'lou@example.test'), 'list_comments (sans e-mail des visiteurs)', $r['text']);
$r = tool($access, 'moderate_comment', ['id' => $commentId, 'action' => 'approve']);
T::check($r['isError'] === false && ($r['data']['status'] ?? '') === 'approved' && T::$db->query('SELECT status FROM comments WHERE id = ' . $commentId)->fetchColumn() === 'approved', 'moderate_comment', $r['text']);
$r = tool($access, 'update_site_settings', ['site_tagline' => 'Vélos et conseils depuis 2010', 'ai_crawlers' => ['CCBot' => true]]);
T::check($r['isError'] === false && ($r['data']['settings']['site.tagline'] ?? '') === 'Vélos et conseils depuis 2010' && ($r['data']['settings']['geo.ai_crawlers']['CCBot'] ?? false) === true, 'update_site_settings', $r['text']);
$r = tool($access, 'update_site_settings', ['ai_crawlers' => ['EvilBot' => true]]);
T::check($r['isError'] === true && str_contains($r['text'], 'Unknown crawler'), 'update_site_settings : robot inconnu refusé');

// Ressources et invites
$r = rpc($access, 'resources/list');
T::check(array_column($r['json']['result']['resources'] ?? [], 'uri') === ['wordless://site/guidelines', 'wordless://design/system'], 'resources/list', $r['body']);
$r = rpc($access, 'resources/read', ['uri' => 'wordless://site/guidelines']);
$text = (string) ($r['json']['result']['contents'][0]['text'] ?? '');
T::check(str_contains($text, 'Atelier Test') && str_contains($text, 'Answer first') && str_contains($text, '## FAQ'), 'resources/read guidelines', substr($text, 0, 300));
$r = rpc($access, 'resources/read', ['uri' => 'wordless://design/system']);
T::check(str_contains((string) ($r['json']['result']['contents'][0]['text'] ?? ''), 'Contrast'), 'resources/read design/system');
$r = rpc($access, 'resources/read', ['uri' => 'wordless://nope']);
T::check(($r['json']['error']['code'] ?? 0) === -32002, 'ressource inconnue : -32002');
$r = rpc($access, 'prompts/list');
T::check(array_column($r['json']['result']['prompts'] ?? [], 'name') === ['write_article', 'improve_page', 'design_review'], 'prompts/list');
$r = rpc($access, 'prompts/get', ['name' => 'write_article', 'arguments' => ['topic' => 'Le vélo cargo', 'keyword' => 'vélo cargo']]);
$msgs = $r['json']['result']['messages'] ?? [];
T::check(count($msgs) === 2 && str_contains((string) ($msgs[0]['content']['text'] ?? ''), 'create_content') && ($msgs[1]['content']['type'] ?? '') === 'resource', 'prompts/get write_article', $r['body']);
$r = rpc($access, 'prompts/get', ['name' => 'improve_page', 'arguments' => ['id' => (string) $articleId]]);
T::check(str_contains((string) ($r['json']['result']['messages'][0]['content']['text'] ?? ''), 'analyze_content'), 'prompts/get improve_page');
$r = rpc($access, 'prompts/get', ['name' => 'design_review', 'arguments' => ['target' => 'lisibilité']]);
T::check(str_contains((string) ($r['json']['result']['messages'][0]['content']['text'] ?? ''), 'update_design') && ($r['json']['result']['messages'][1]['content']['resource']['uri'] ?? '') === 'wordless://design/system', 'prompts/get design_review');
$r = rpc($access, 'prompts/get', ['name' => 'write_article', 'arguments' => new stdClass()]);
T::check(($r['json']['error']['code'] ?? 0) === -32602, 'prompts/get : argument obligatoire manquant => -32602');

$r = tool($access, 'delete_content', ['id' => $articleId]);
T::check($r['isError'] === false && ($r['data']['status'] ?? '') === 'trash', 'delete_content : corbeille', $r['text']);
$gone = req('GET', '/choisir-velo-electrique/');
T::check($gone['status'] === 404, 'contenu à la corbeille : 404');
$homeId = (int) T::$db->query("SELECT value FROM settings WHERE key = 'home.page_id'")->fetchColumn();
if ($homeId > 0) {
    $r = tool($access, 'delete_content', ['id' => $homeId]);
    T::check($r['isError'] === true && str_contains($r['text'], 'home page'), 'delete_content : page d’accueil protégée');
}

// ----------------------------------------------------------------------
T::section('Rafraîchissement, rotation et révocation');
// ----------------------------------------------------------------------

$t1 = full_oauth($claudeId, $cookie, $csrf)['json'] ?? [];
$r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t1['refresh_token'] ?? ''), 'client_id' => $claudeId]);
$t2 = $r['json'] ?? [];
T::check($r['status'] === 200 && ($t2['refresh_token'] ?? '') !== '' && ($t2['refresh_token'] ?? '') !== ($t1['refresh_token'] ?? '') && ($t2['access_token'] ?? '') !== ($t1['access_token'] ?? ''), 'refresh_token : nouvelle paire (rotation)', $r['body']);
T::check(rpc((string) ($t2['access_token'] ?? ''), 'ping')['status'] === 200, 'nouveau jeton d’accès valide');
$r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t1['refresh_token'] ?? ''), 'client_id' => $claudeId]);
T::check($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', 'ancien refresh_token réutilisé : invalid_grant', $r['body']);
$r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t2['refresh_token'] ?? ''), 'client_id' => $claudeId]);
T::check($r['status'] === 400, 'réutilisation détectée : toute l’autorisation est révoquée (refresh)');
T::check(rpc((string) ($t2['access_token'] ?? ''), 'ping')['status'] === 401, 'réutilisation détectée : jeton d’accès révoqué');
$reuseLog = (int) T::$db->query("SELECT COUNT(*) FROM activity WHERE action = 'mcp' AND object = 'oauth' AND label LIKE 'Réutilisation%'")->fetchColumn();
T::check($reuseLog >= 2, 'réutilisations inscrites au journal');

$t3 = full_oauth($claudeId, $cookie, $csrf)['json'] ?? [];
$r = req('POST', '/oauth/token', ['Content-Type: application/json'], (string) json_encode(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t3['refresh_token'] ?? ''), 'client_id' => $claudeId]));
$t3b = $r['json'] ?? [];
T::check($r['status'] === 200 && isset($t3b['access_token']), 'point d’accès token : corps JSON accepté', $r['body']);
$r = req('POST', '/oauth/revoke', [], ['token' => (string) ($t3b['refresh_token'] ?? ''), 'token_type_hint' => 'refresh_token', 'client_id' => $claudeId]);
T::check($r['status'] === 200, 'revoke : 200', $r['body']);
T::check(rpc((string) ($t3b['access_token'] ?? ''), 'ping')['status'] === 401, 'revoke du refresh_token : jetons d’accès de l’autorisation révoqués');
$r = req('POST', '/oauth/revoke', [], ['token' => 'inconnu', 'client_id' => $claudeId]);
T::check($r['status'] === 200, 'revoke d’un jeton inconnu : 200 (RFC 7009)');
$t4 = full_oauth($claudeId, $cookie, $csrf)['json'] ?? [];
req('POST', '/oauth/revoke', [], ['token' => (string) ($t4['access_token'] ?? '')]);
T::check(rpc((string) ($t4['access_token'] ?? ''), 'ping')['status'] === 401, 'revoke d’un jeton d’accès');
$r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t4['refresh_token'] ?? ''), 'client_id' => $claudeId]);
T::check($r['status'] === 200, 'revoke du seul jeton d’accès : le refresh_token reste valide');

// Client confidentiel + adresse locale à port variable (RFC 8252)
[$v5, $c5] = pkce();
$p5 = authorize_params($codeClientId, 'http://localhost:51234/callback', $c5);
$r = req('GET', '/oauth/authorize?' . http_build_query($p5), [], null, $cookie);
T::check($r['status'] === 200 && str_contains(hdr($r, 'Content-Security-Policy'), "form-action 'self' http://localhost:51234"), 'localhost : port libre accepté, CSP adaptée', hdr($r, 'Content-Security-Policy'));
$r = approve($p5, $cookie, $csrf);
$code5 = (string) (location_params($r)['code'] ?? '');
T::check(str_starts_with(hdr($r, 'Location'), 'http://localhost:51234/callback?code='), 'localhost : redirection vers le port demandé');
$f5 = ['grant_type' => 'authorization_code', 'code' => $code5, 'redirect_uri' => 'http://localhost:51234/callback', 'code_verifier' => $v5];
$r = token_request($f5 + ['client_id' => $codeClientId]);
T::check($r['status'] === 401 && ($r['json']['error'] ?? '') === 'invalid_client', 'client confidentiel sans secret : 401 invalid_client');
$r = token_request($f5, ['Authorization: Basic ' . base64_encode(urlencode($codeClientId) . ':' . urlencode('mauvais'))]);
T::check($r['status'] === 401 && str_contains(hdr($r, 'WWW-Authenticate'), 'Basic'), 'mauvais secret (Basic) : 401 + WWW-Authenticate: Basic');
$r = token_request($f5, ['Authorization: Basic ' . base64_encode(urlencode($codeClientId) . ':' . urlencode($codeClientSecret))]);
T::check($r['status'] === 200 && isset($r['json']['access_token']), 'client confidentiel (client_secret_basic) : jetons délivrés', $r['body']);

// ----------------------------------------------------------------------
T::section('Clés API (Claude Code, Claude Desktop, API)');
// ----------------------------------------------------------------------

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/bin/wordless') . ' mcp:key ' . ADMIN_EMAIL . ' --label=' . escapeshellarg('Claude Code (portable)') . ' 2>&1', $keyOut, $code);
$cliText = implode("\n", $keyOut);
preg_match('/\bwl_[A-Za-z0-9_-]{40,}/', $cliText, $m);
$apiKey = $m[0] ?? '';
T::check($code === 0 && $apiKey !== '' && str_contains($cliText, 'claude mcp add --transport http wordless ' . BASE . '/mcp --header "Authorization: Bearer ' . $apiKey . '"'), 'mcp:key : clé créée et commande Claude Code affichée', $cliText);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(T::$root . '/bin/wordless') . ' mcp:key inconnu@example.test 2>&1', $badOut, $badCode);
T::check($badCode === 1, 'mcp:key : utilisateur inconnu => code 1');
$r = rpc($apiKey, 'initialize', $init);
T::check($r['status'] === 200 && ($r['json']['result']['serverInfo']['name'] ?? '') === 'wordless', 'clé API : initialize', $r['body']);
$r = tool($apiKey, 'site_overview');
T::check($r['isError'] === false && ($r['data']['connected_as']['role'] ?? '') === 'admin', 'clé API : outils utilisables');
$keys = probe('keys', (string) $adminId);
T::check(is_array($keys) && count($keys) === 1 && ($keys[0]['label'] ?? '') === 'Claude Code (portable)' && preg_match('/^[a-f0-9]{12}$/', (string) ($keys[0]['id'] ?? '')) === 1
    && !str_contains((string) json_encode($keys), $apiKey) && ($keys[0]['client'] ?? '') === 'test-client 1.0' && is_int($keys[0]['last_used_at'] ?? null), 'ApiKeys::list : métadonnées sans la clé (client et dernier usage)', $keys);
T::check((int) T::$db->query('SELECT COUNT(*) FROM tokens WHERE id = ' . T::$db->quote(hash('sha256', $apiKey)) . " AND kind = 'api_key' AND expires_at > " . (time() + 9 * 365 * 86400))->fetchColumn() === 1, 'clé stockée hachée, valable 10 ans');
probe('revoke-key', (string) ($keys[0]['id'] ?? ''), (string) $adminId);
T::check(rpc($apiKey, 'ping')['status'] === 401, 'ApiKeys::revoke : clé révoquée');

// Droits d'un auteur
T::$db->prepare("INSERT INTO users (email, name, slug, role, password, created_at) VALUES ('auteur@example.test', 'Alex Auteur', 'alex-auteur', 'author', '', ?)")->execute([time()]);
$authorId = (int) T::$db->lastInsertId();
$authorKey = (string) probe('create-key', (string) $authorId, 'Clé auteur');
$r = rpc($authorKey, 'tools/list');
$authorTools = array_column($r['json']['result']['tools'] ?? [], 'name');
T::check(in_array('create_content', $authorTools, true) && !array_intersect(['update_design', 'update_site_settings', 'manage_redirects', 'moderate_comment', 'get_design'], $authorTools), 'auteur : seuls les outils permis par son rôle', $authorTools);
$r = tool($authorKey, 'update_site_settings', ['site_name' => 'Piraté']);
T::check($r['isError'] === true && str_contains($r['text'], 'Permission denied'), 'auteur : outil réservé => isError');
$draft = tool($access, 'create_content', ['title' => 'Brouillon secret de l’admin', 'markdown' => 'Confidentiel.']);
$r = tool($authorKey, 'get_content', ['id' => (int) ($draft['data']['id'] ?? 0)]);
T::check($r['isError'] === true, 'auteur : brouillon d’un autre invisible');
$r = tool($authorKey, 'update_content', ['id' => (int) ($draft['data']['id'] ?? 0), 'title' => 'Modifié']);
T::check($r['isError'] === true, 'auteur : contenu d’un autre non modifiable');
$r = tool($authorKey, 'create_content', ['title' => 'Mon premier article', 'markdown' => 'Bonjour.']);
T::check($r['isError'] === false && (int) T::$db->query('SELECT author_id FROM content WHERE id = ' . (int) ($r['data']['id'] ?? 0))->fetchColumn() === $authorId, 'auteur : création à son nom');
T::$db->exec("UPDATE users SET status = 'disabled' WHERE id = $authorId");
T::check(rpc($authorKey, 'ping')['status'] === 401, 'compte désactivé : accès refusé');

// ----------------------------------------------------------------------
T::section('Applications connectées, désactivation');
// ----------------------------------------------------------------------

$t6 = full_oauth($claudeId, $cookie, $csrf)['json'] ?? [];
$apps = probe('clients', (string) $adminId);
$claudeApp = array_values(array_filter(is_array($apps) ? $apps : [], static fn ($a) => ($a['id'] ?? '') === $claudeId))[0] ?? [];
T::check(($claudeApp['name'] ?? '') === 'Claude' && ($claudeApp['redirect_host'] ?? '') === 'claude.ai' && is_int($claudeApp['connected_at'] ?? null), 'OAuth::clients : application connectée', $apps);
probe('revoke-client', $claudeId, (string) $adminId);
T::check(rpc((string) ($t6['access_token'] ?? ''), 'ping')['status'] === 401 && rpc($access, 'ping')['status'] === 401, 'OAuth::revokeClient : tous les jetons de l’application révoqués');
$r = token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t6['refresh_token'] ?? ''), 'client_id' => $claudeId]);
T::check($r['status'] === 400, 'OAuth::revokeClient : refresh_token révoqué');

$t7 = full_oauth($claudeId, $cookie, $csrf)['json'] ?? [];
set_setting('mcp.enabled', false);
$r = rpc((string) ($t7['access_token'] ?? ''), 'ping');
T::check($r['status'] === 404, 'mcp.enabled = false : /mcp répond 404');
T::check(req('GET', '/.well-known/oauth-authorization-server')['status'] === 404, 'mcp.enabled = false : OAuth désactivé');
set_setting('mcp.enabled', true);
T::check(rpc((string) ($t7['access_token'] ?? ''), 'ping')['status'] === 200, 'réactivation');

$rateKey = (string) probe('create-key', (string) $adminId, 'Limite');
$statuses = [];
for ($i = 0; $i < 241; $i++) {
    $statuses[] = rpc($rateKey, 'ping', null, $i)['status'];
}
$last = rpc($rateKey, 'ping');
T::check(count(array_filter($statuses, static fn ($s) => $s === 200)) === 240 && end($statuses) === 429 && $last['status'] === 429 && (int) hdr($last, 'Retry-After') > 0, 'limitation : 240 requêtes par minute et par jeton, puis 429 + Retry-After', array_count_values($statuses));
T::check(rpc((string) ($t7['access_token'] ?? ''), 'ping')['status'] === 200, 'limitation propre à chaque jeton');

$plain = (int) T::$db->query('SELECT COUNT(*) FROM tokens WHERE id LIKE \'wl%\'')->fetchColumn();
T::check($plain === 0, 'aucun jeton en clair dans la base');
$errors = glob(T::$root . '/data/logs/error-*.log') ?: [];
$log = $errors ? (string) file_get_contents($errors[0]) : '';
T::check($log === '', 'aucune erreur PHP journalisée', $log);

// ----------------------------------------------------------------------
T::section('Installation dans un sous-dossier (/blog)');
// ----------------------------------------------------------------------

foreach (T::$procs as $proc) {
    proc_terminate($proc);
    proc_close($proc);
}
T::$procs = [];
for ($i = 0; $i < 50 && ($s = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.2)); $i++) {
    fclose($s);
    usleep(100000);
}
$sub = T::$root . '-sub';
$blog = BASE . '/blog';
copy_tree($src, $sub . '/blog', ['.git', 'data', 'media', 'tests']);
mkdir($sub . '/blog/data', 0775, true);
mkdir($sub . '/blog/media', 0775, true);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sub . '/blog/bin/wordless') . ' install --url=' . $blog . ' --email=' . ADMIN_EMAIL
    . ' --password=' . escapeshellarg(ADMIN_PASSWORD) . ' --site=Blog --no-starter 2>&1', $subOut, $code);
T::check($code === 0, 'installation dans /blog', implode("\n", $subOut));
// Racine du domaine : réécrit /blog/* et les métadonnées « à chemin inséré » vers Wordless.
file_put_contents($sub . '/root-router.php', <<<'PHP'
<?php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$wordless = $path === '/blog' || str_starts_with($path, '/blog/')
    || preg_match('#^/\.well-known/(oauth-authorization-server|openid-configuration)/blog$|^/\.well-known/oauth-protected-resource/blog/mcp$#', $path);
if (!$wordless) {
    http_response_code(404);
    echo 'racine du domaine';
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/blog/index.php';
require __DIR__ . '/blog/index.php';
PHP);
start_server(PORT, $sub, ['root-router.php'], $env);
$subConfig = include $sub . '/blog/data/config.php';
$subDb = new PDO('sqlite:' . $sub . '/blog/data/' . $subConfig['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$r = req('GET', $blog . '/.well-known/oauth-protected-resource');
T::check(($r['json']['resource'] ?? '') === $blog . '/mcp' && ($r['json']['authorization_servers'] ?? []) === [$blog], 'sous-dossier : ressource protégée', $r['body']);
$r = req('GET', BASE . '/.well-known/oauth-authorization-server/blog');
T::check($r['status'] === 200 && ($r['json']['issuer'] ?? '') === $blog && ($r['json']['token_endpoint'] ?? '') === $blog . '/oauth/token', 'sous-dossier : métadonnées à la racine du domaine (RFC 8414, chemin inséré)', $r['body']);
$r = req('GET', BASE . '/.well-known/oauth-protected-resource/blog/mcp');
T::check(($r['json']['resource'] ?? '') === $blog . '/mcp', 'sous-dossier : ressource protégée à la racine du domaine (RFC 9728)');
$r = req('GET', $blog . '/.well-known/openid-configuration');
T::check(($r['json']['issuer'] ?? '') === $blog, 'sous-dossier : openid-configuration ajouté au chemin de l’émetteur');
$r = req('POST', $blog . '/mcp', ['Content-Type: application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
T::check($r['status'] === 401 && str_contains(hdr($r, 'WWW-Authenticate'), 'resource_metadata="' . $blog . '/.well-known/oauth-protected-resource"'), 'sous-dossier : WWW-Authenticate', hdr($r, 'WWW-Authenticate'));
$r = req('POST', $blog . '/oauth/register', ['Content-Type: application/json'], (string) json_encode(['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_REDIRECT], 'token_endpoint_auth_method' => 'none']));
$subClient = (string) ($r['json']['client_id'] ?? '');
[$sv, $sc] = pkce();
$sp = authorize_params($subClient, CLAUDE_REDIRECT, $sc);
$sp['resource'] = $blog . '/mcp';
$r = req('GET', $blog . '/oauth/authorize?' . http_build_query($sp));
T::check(hdr($r, 'Location') === '/blog/admin/login?redirect=' . rawurlencode('/blog/oauth/authorize?' . http_build_query($sp)), 'sous-dossier : redirection vers la connexion (retour avec le sous-dossier)', hdr($r, 'Location'));
$subCookie = bin2hex(random_bytes(24));
$subCsrf = bin2hex(random_bytes(16));
$subDb->prepare('INSERT INTO sessions (id, user_id, csrf, pending_2fa, ip, ua, created_at, seen_at, expires_at) VALUES (?, 1, ?, 0, \'\', \'\', ?, ?, ?)')
    ->execute([hash('sha256', $subCookie), $subCsrf, time(), time(), time() + 3600]);
$page = req('GET', $blog . '/oauth/authorize?' . http_build_query($sp), [], null, $subCookie);
T::check(str_contains($page['body'], 'action="/blog/oauth/authorize"'), 'sous-dossier : formulaire de consentement', substr($page['body'], 0, 300));
usleep(1_100_000);
$r = req('POST', $blog . '/oauth/authorize', [], $sp + ['_csrf' => $subCsrf, 'decision' => 'approve', '_t' => consent_stamp($page['body'])], $subCookie);
$p = location_params($r);
T::check(($p['iss'] ?? '') === $blog && ($p['code'] ?? '') !== '', 'sous-dossier : code + iss', hdr($r, 'Location'));
$r = req('POST', $blog . '/oauth/token', [], ['grant_type' => 'authorization_code', 'code' => (string) ($p['code'] ?? ''), 'redirect_uri' => CLAUDE_REDIRECT, 'client_id' => $subClient, 'code_verifier' => $sv, 'resource' => $blog . '/mcp']);
$subAccess = (string) ($r['json']['access_token'] ?? '');
$r = req('POST', $blog . '/mcp', mcp_headers($subAccess), (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'create_content', 'arguments' => ['title' => 'Premier billet', 'markdown' => 'Bonjour depuis le sous-dossier.']]]));
$created = $r['json']['result']['structuredContent'] ?? [];
T::check(($created['url'] ?? '') === $blog . '/premier-billet/' && ($created['admin_url'] ?? '') === $blog . '/admin/content/' . ($created['id'] ?? 0), 'sous-dossier : outils et adresses', $r['body']);
$r = req('POST', $blog . '/mcp', mcp_headers($subAccess), (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_content', 'arguments' => ['url' => $blog . '/premier-billet/']]]));
T::check(($r['json']['result']['structuredContent']['id'] ?? 0) === ($created['id'] ?? -1), 'sous-dossier : contenu retrouvé par son URL');
$subErrors = glob($sub . '/blog/data/logs/error-*.log') ?: [];
T::check($subErrors === [] || trim((string) file_get_contents($subErrors[0])) === '', 'sous-dossier : aucune erreur PHP journalisée', $subErrors ? (string) file_get_contents($subErrors[0]) : null);

echo "\n" . (T::$pass + T::$fail) . ' vérifications, ' . T::$fail . " échec(s).\n";
exit(T::$fail > 0 ? 1 : 0);
