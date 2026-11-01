<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\App;
use Wordless\Auth;
use Wordless\Config;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Http;
use Wordless\I18n;
use Wordless\Settings;
use Wordless\Throttle;

/**
 * Point d'accès MCP (Model Context Protocol), transport « Streamable HTTP » :
 * JSON-RPC 2.0 en POST sur /mcp, réponses application/json (pas de flux SSE,
 * aucune notification à l'initiative du serveur), sans état côté serveur.
 * Authentification par jeton OAuth (Claude.ai, ChatGPT) ou clé API (Claude Code,
 * Claude Desktop, API) : l'IA agit avec les droits de l'utilisateur concerné.
 */
final class Server
{
    /** Versions du protocole prises en charge, de la plus récente à la plus ancienne. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const MAX_BODY = 8 * 1024 * 1024;
    private const MAX_BATCH = 50;
    private const RATE_MAX = 240;
    private const RATE_WINDOW = 60;

    /** Jeton de la requête en cours (ligne de la table tokens). */
    private static ?array $token = null;

    public static function handle(string $path): void
    {
        try {
            self::serve($path);
        } catch (\Throwable $e) {
            App::logError($e);
            Response::json(500, self::error(null, -32603, 'Internal error'));
        }
    }

    /** Jeton authentifié de la requête en cours (null hors MCP). */
    public static function token(): ?array
    {
        return self::$token;
    }

    private static function serve(string $path): void
    {
        Response::cors('GET, POST, DELETE, OPTIONS');
        if (!Settings::get('mcp.enabled', true)) {
            Response::json(404, ['error' => 'not_found', 'error_description' => 'The AI connector (MCP) is disabled on this site.']);
            return;
        }
        if ($path !== '' && $path !== '/') {
            Response::json(404, ['error' => 'not_found', 'error_description' => 'The MCP endpoint is ' . Config::baseUrl() . '/mcp']);
            return;
        }
        // Protection contre le « DNS rebinding » : origine inconnue = refus.
        if (!self::originAllowed(Http::header('Origin'))) {
            Response::json(403, self::error(null, -32000, 'Forbidden: origin not allowed'));
            return;
        }
        switch (Http::method()) {
            case 'OPTIONS':
                Response::status(204);
                return;
            case 'DELETE':
                Response::status(204); // fin de session : rien à fermer, le serveur est sans état
                return;
            case 'POST':
                break;
            default:
                // Pas de flux d'événements à l'initiative du serveur.
                Response::json(405, self::error(null, -32000, 'Method not allowed: send JSON-RPC messages with POST'), ['Allow: POST']);
                return;
        }
        if (!self::authenticate()) {
            return;
        }
        $version = trim(Http::header('MCP-Protocol-Version'));
        if ($version !== '' && !in_array($version, self::VERSIONS, true)) {
            Response::json(400, self::error(null, -32600, 'Unsupported MCP-Protocol-Version "' . substr($version, 0, 40) . '" (supported: ' . implode(', ', self::VERSIONS) . ')'));
            return;
        }
        $key = 'mcp:rate:' . substr((string) self::$token['id'], 0, 32);
        if (($wait = Throttle::blocked($key)) > 0 || ($wait = Throttle::hit($key, self::RATE_MAX, self::RATE_WINDOW, self::RATE_WINDOW)) > 0) {
            Response::json(429, self::error(null, -32000, 'Rate limit exceeded (' . self::RATE_MAX . ' requests per minute), retry in ' . $wait . ' s'), ['Retry-After: ' . max(1, $wait)]);
            return;
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            Response::json(413, self::error(null, -32600, 'Request too large'));
            return;
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            Response::json(413, self::error(null, -32600, 'Request too large'));
            return;
        }
        try {
            $message = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::json(400, self::error(null, -32700, 'Parse error'));
            return;
        }

        $session = null;
        if (is_array($message)) {
            if ($message === [] || count($message) > self::MAX_BATCH) {
                Response::json(400, self::error(null, -32600, $message === [] ? 'Invalid Request: empty batch' : 'Invalid Request: batch too large (max ' . self::MAX_BATCH . ')'));
                return;
            }
            $responses = [];
            foreach ($message as $m) {
                $r = self::process($m, $session);
                if ($r !== null) {
                    $responses[] = $r;
                }
            }
            $headers = $session !== null ? ['Mcp-Session-Id: ' . $session] : [];
            $responses ? Response::json(200, $responses, $headers) : Response::status(202, $headers);
            return;
        }
        $response = self::process($message, $session);
        if ($response === null) {
            Response::status(202); // notification ou réponse du client : accusé de réception, sans corps
            return;
        }
        $status = isset($response['error']) && in_array($response['error']['code'], [-32600, -32700], true) ? 400 : 200;
        Response::json($status, $response, $session !== null ? ['Mcp-Session-Id: ' . $session] : []);
    }

    // ------------------------------------------------------------------
    // Sécurité
    // ------------------------------------------------------------------

    /** Origine autorisée : le site, Claude, ChatGPT, et localhost (MCP Inspector). */
    public static function originAllowed(string $origin): bool
    {
        $origin = trim($origin);
        if ($origin === '') {
            return true; // client serveur à serveur (pas de navigateur)
        }
        $p = parse_url($origin);
        if ($p === false || empty($p['scheme']) || empty($p['host']) || isset($p['path']) && $p['path'] !== '/') {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower(trim($p['host'], '[]'));
        if (in_array($scheme, ['http', 'https'], true) && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        $normalized = $scheme . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
        $site = parse_url(Config::baseUrl());
        $siteOrigin = strtolower(($site['scheme'] ?? '') . '://' . ($site['host'] ?? '')) . (isset($site['port']) ? ':' . $site['port'] : '');
        $allowed = wl_filter('mcp_allowed_origins', [$siteOrigin, 'https://claude.ai', 'https://claude.com', 'https://chatgpt.com', 'https://chat.openai.com']);
        return in_array($normalized, array_map('strtolower', (array) $allowed), true);
    }

    private static function authenticate(): bool
    {
        $plain = Http::bearer();
        if ($plain === '') {
            self::unauthorized(null);
            return false;
        }
        $ipKey = 'mcp:badtoken:' . ip_hash();
        if (($wait = Throttle::blocked($ipKey)) > 0) {
            Response::json(429, ['error' => 'too_many_requests', 'error_description' => 'Too many invalid tokens, retry later.'], ['Retry-After: ' . $wait]);
            return false;
        }
        $row = Tokens::find($plain, ['oauth_access', 'api_key']);
        $user = null;
        if ($row && !Tokens::expired($row) && self::audienceOk($row)) {
            $user = Db::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int) $row['user_id']]);
        }
        if (!$user) {
            // Seuls les jetons inconnus comptent : un jeton expiré est normal (le client le renouvelle),
            // et Claude.ai / ChatGPT partagent leurs adresses IP entre tous leurs utilisateurs.
            if (!$row) {
                Throttle::hit($ipKey, 120, 300, 60);
            }
            self::unauthorized('The access token is invalid, expired or revoked.');
            return false;
        }
        Auth::actAs($user);
        Tokens::touch($row);
        self::$token = $row;
        return true;
    }

    /** Un jeton OAuth émis pour une autre ressource n'est pas accepté ici. */
    private static function audienceOk(array $row): bool
    {
        $resource = $row['data']['resource'] ?? null;
        return $resource === null || $resource === '' || $resource === OAuth::resource();
    }

    private static function unauthorized(?string $invalid): void
    {
        $header = 'WWW-Authenticate: Bearer resource_metadata="' . Config::baseUrl() . '/.well-known/oauth-protected-resource"';
        if ($invalid !== null) {
            $header .= ', error="invalid_token", error_description="' . $invalid . '"';
        }
        Response::json(401, ['error' => $invalid !== null ? 'invalid_token' : 'unauthorized', 'error_description' => $invalid ?? 'Authentication required: connect with OAuth or send an API key (Authorization: Bearer wl_...).'], [$header]);
    }

    // ------------------------------------------------------------------
    // JSON-RPC
    // ------------------------------------------------------------------

    /** Traite un message ; renvoie la réponse, ou null pour une notification. */
    private static function process(mixed $m, ?string &$session): ?array
    {
        if (!$m instanceof \stdClass) {
            return self::error(null, -32600, 'Invalid Request: expected a JSON-RPC object');
        }
        $hasId = property_exists($m, 'id');
        $id = $hasId ? $m->id : null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return self::error(null, -32600, 'Invalid Request: id must be a string or an integer');
        }
        if (($m->jsonrpc ?? null) !== '2.0') {
            return self::error($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
        }
        if (!property_exists($m, 'method')) {
            if ($hasId && (property_exists($m, 'result') || property_exists($m, 'error'))) {
                return null; // réponse du client à une requête du serveur : on n'en envoie jamais, on l'ignore
            }
            return self::error($id, -32600, 'Invalid Request: method is required');
        }
        if (!is_string($m->method) || $m->method === '') {
            return self::error($id, -32600, 'Invalid Request: method must be a non-empty string');
        }
        $params = $m->params ?? new \stdClass();
        if ($params === []) {
            $params = new \stdClass();
        }
        if (!$hasId) {
            return null; // notifications/initialized, notifications/cancelled… : rien à faire
        }
        if (!$params instanceof \stdClass) {
            return self::error($id, -32602, 'Invalid params: params must be an object');
        }
        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::call($m->method, $params, $session)];
        } catch (RpcError $e) {
            return self::error($id, $e->getCode(), $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            App::logError($e);
            return self::error($id, -32603, 'Internal error');
        }
    }

    private static function call(string $method, \stdClass $params, ?string &$session): mixed
    {
        return match ($method) {
            'initialize' => self::initialize($params, $session),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => Tools::listing()],
            'tools/call' => Tools::call($params),
            'resources/list' => ['resources' => Resources::listing()],
            'resources/templates/list' => ['resourceTemplates' => []],
            'resources/read' => Resources::read($params),
            'prompts/list' => ['prompts' => Prompts::listing()],
            'prompts/get' => Prompts::get($params),
            default => throw new RpcError(-32601, 'Method not found: ' . substr($method, 0, 100)),
        };
    }

    private static function initialize(\stdClass $p, ?string &$session): array
    {
        $requested = $p->protocolVersion ?? null;
        if (!is_string($requested) || $requested === '') {
            throw new RpcError(-32602, 'Invalid params: protocolVersion is required');
        }
        $session = Crypto::token(24);
        self::rememberClient($p->clientInfo ?? null);
        $site = (string) Settings::get('site.name', 'Wordless');
        return [
            'protocolVersion' => in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => new \stdClass(),
                'prompts' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => 'wordless',
                'title' => $site,
                'version' => WL_VERSION,
                'websiteUrl' => Config::baseUrl() . '/',
            ],
            'instructions' => self::instructions(),
        ];
    }

    /** Note le client MCP qui utilise le jeton (affiché dans l'écran des clés API). */
    private static function rememberClient(mixed $info): void
    {
        if (!$info instanceof \stdClass || !self::$token || !is_string($info->name ?? null)) {
            return;
        }
        $label = mb_substr(trim(strip_tags($info->name . (is_string($info->version ?? null) ? ' ' . $info->version : ''))), 0, 80);
        if ($label !== '' && ($label !== (self::$token['data']['client'] ?? null))) {
            Tokens::mergeData(self::$token, ['client' => $label]);
        }
    }

    /** Mode d'emploi donné au modèle à l'initialisation (anglais : lu par le modèle). */
    public static function instructions(): string
    {
        $site = (string) Settings::get('site.name', 'Wordless');
        $url = Config::baseUrl() . '/';
        $lang = (string) Settings::get('language', 'fr');
        $language = self::languageName($lang);
        $user = Auth::user();
        $who = $user ? (string) $user['name'] : 'the site owner';
        $role = $user ? (string) $user['role'] : 'unknown';
        return <<<TXT
You are connected to "{$site}" ({$url}), a website powered by Wordless, a lightweight CMS with built-in SEO and GEO (visibility in AI answer engines). You act on behalf of {$who} (role: {$role}); every write is recorded in the site's activity log.

- The site is written in {$language} (code "{$lang}"): write content in {$language} unless the user asks otherwise, and talk to the user in their own language.
- Start with `site_overview` to understand the site, its traffic (including AI referrals and AI crawler visits) and its top SEO/GEO issues. Read the resource `wordless://site/guidelines` before writing.
- Before writing about a topic, `search` existing content to avoid duplicates and to find pages to link to internally.
- Write in Markdown. Do not repeat the title as a "# H1": the title is the H1. Use "##" sections and "###" sub-sections.
- Content that ranks (SEO): one main keyword per page in the SEO title (30-60 characters), the meta description (120-160 characters), the introduction, one "##" heading and a short slug; at least one internal link; descriptive alt text on every image.
- Content that AI engines cite (GEO): answer first (the opening paragraph answers the main question in 40-60 words); phrase several headings as real questions; add precise, dated statistics with their sources (links to authoritative sites); use lists, tables and steps; finish with a "## FAQ" section made of "### Question?" headings, each followed by a short answer (it becomes a FAQ block with structured data); add a short key-takeaways list.
- Create content as a draft unless the user explicitly asks to publish or schedule it, then give them the admin edit link. After creating or updating, check `analyze_content` and fix the main warnings.
- Pages built with layout blocks (hero, columns, features, call to action…) must be edited with `get_content` format "blocks" and `update_content` blocks, so that the layout is preserved.
- Tools only do what the user's role allows; if a tool reports a permission error, tell the user instead of retrying.
TXT;
    }

    public static function languageName(string $code): string
    {
        return ['fr' => 'French', 'en' => 'English', 'es' => 'Spanish', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch'][$code]
            ?? (I18n::LANGUAGES[$code] ?? $code);
    }

    /** @return array{jsonrpc: string, id: mixed, error: array} */
    private static function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
