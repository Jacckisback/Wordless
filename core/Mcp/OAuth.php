<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Activity;
use Wordless\Auth;
use Wordless\Config;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Http;
use Wordless\HttpClient;
use Wordless\Settings;
use Wordless\Throttle;
use Wordless\View;

/**
 * Serveur d'autorisation OAuth 2.1 du connecteur IA, sur la même origine que le site :
 * - métadonnées : ressource protégée (RFC 9728) et serveur d'autorisation (RFC 8414) ;
 * - inscription dynamique des clients (RFC 7591), comme le font Claude.ai et ChatGPT ;
 * - clients identifiés par une adresse https (« Client ID Metadata Documents »,
 *   spécification MCP 2025-11-25) : la fiche JSON publiée par l'application est lue,
 *   vérifiée et gardée en cache ;
 * - autorisation par code avec PKCE S256 obligatoire et écran de consentement ;
 * - jetons d'accès (1 h) et de rafraîchissement (60 jours, rotation à chaque usage,
 *   réutilisation détectée => toute l'autorisation est révoquée) ;
 * - révocation (RFC 7009).
 * Les messages « error_description » restent en anglais ASCII (exigence de la RFC 6749).
 */
final class OAuth
{
    public const SCOPE = 'wordless';

    /** Durée minimale d'affichage de l'écran de consentement avant approbation (ms). */
    private const MIN_CONSENT_MS = 1000;

    /** Fiche d'un client identifié par une adresse : taille maximale (5 Ko, valeur du brouillon IETF). */
    private const CIMD_MAX_BYTES = 5120;

    /** Durée de cache d'une fiche sans en-tête de cache, et durée maximale (secondes). */
    private const CIMD_TTL = 3600;
    private const CIMD_MAX_TTL = 86400;

    private const API_PATHS = [
        '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp',
        '/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server/mcp',
        '/.well-known/openid-configuration', '/.well-known/openid-configuration/mcp',
        '/oauth/register', '/oauth/token', '/oauth/revoke',
    ];

    public static function handle(string $path): void
    {
        $path = rtrim($path, '/');
        $base = Config::basePath();
        if ($base !== '') {
            // Installation dans un sous-dossier : les clients cherchent les métadonnées à la racine du
            // domaine (/.well-known/…/sous-dossier, RFC 8414 et 9728) ; une réécriture vers Wordless suffit.
            $path = [
                '/.well-known/oauth-authorization-server' . $base => '/.well-known/oauth-authorization-server',
                '/.well-known/openid-configuration' . $base => '/.well-known/openid-configuration',
                '/.well-known/oauth-protected-resource' . $base . '/mcp' => '/.well-known/oauth-protected-resource',
            ][$path] ?? $path;
        }
        $method = Http::method();
        $api = in_array($path, self::API_PATHS, true);
        if ($api) {
            Response::cors();
        }
        if (!self::enabled()) {
            if ($path === '/oauth/authorize') {
                self::errorPage(404, __('Connecteur IA désactivé'), __('Le connecteur IA (MCP) est désactivé sur ce site. Un administrateur peut l’activer dans l’administration, menu « Connecteur Claude / ChatGPT ».'));
                return;
            }
            Response::json(404, ['error' => 'not_found', 'error_description' => 'The AI connector (MCP) is disabled on this site.']);
            return;
        }
        if ($api && $method === 'OPTIONS') {
            Response::status(204);
            return;
        }
        $get = in_array($method, ['GET', 'HEAD'], true);
        switch ($path) {
            case '/.well-known/oauth-protected-resource':
            case '/.well-known/oauth-protected-resource/mcp':
                $get ? Response::json(200, self::resourceMetadata()) : self::notAllowed('GET, OPTIONS');
                return;
            case '/.well-known/oauth-authorization-server':
            case '/.well-known/oauth-authorization-server/mcp':
            case '/.well-known/openid-configuration':
            case '/.well-known/openid-configuration/mcp':
                $get ? Response::json(200, self::serverMetadata()) : self::notAllowed('GET, OPTIONS');
                return;
            case '/oauth/register':
                $method === 'POST' ? self::register() : self::notAllowed('POST, OPTIONS');
                return;
            case '/oauth/authorize':
                if ($get) {
                    self::authorize(self::strings($_GET), false);
                } elseif ($method === 'POST') {
                    self::authorize(self::strings($_POST), true);
                } else {
                    self::notAllowed('GET, POST');
                }
                return;
            case '/oauth/token':
                $method === 'POST' ? self::token() : self::notAllowed('POST, OPTIONS');
                return;
            case '/oauth/revoke':
                $method === 'POST' ? self::revoke() : self::notAllowed('POST, OPTIONS');
                return;
        }
        Response::json(404, ['error' => 'not_found']);
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('mcp.enabled', true);
    }

    /** Adresse de la ressource protégée (le point d'accès MCP). */
    public static function resource(): string
    {
        return Config::baseUrl() . '/mcp';
    }

    // ------------------------------------------------------------------
    // Métadonnées
    // ------------------------------------------------------------------

    public static function resourceMetadata(): array
    {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [Config::baseUrl()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
            'resource_name' => (string) Settings::get('site.name', 'Wordless'),
        ];
    }

    public static function serverMetadata(): array
    {
        $base = Config::baseUrl();
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
        ];
    }

    // ------------------------------------------------------------------
    // Inscription dynamique (RFC 7591)
    // ------------------------------------------------------------------

    private static function register(): void
    {
        $key = 'oauth:register:' . ip_hash();
        if (($wait = Throttle::blocked($key)) > 0 || ($wait = Throttle::hit($key, 50, 3600, 3600)) > 0) {
            self::oauthError(429, 'temporarily_unavailable', 'Too many client registrations from this address, retry later.', ['Retry-After: ' . $wait]);
            return;
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
        $in = strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (!is_array($in) || ($in !== [] && array_is_list($in))) {
            self::oauthError(400, 'invalid_client_metadata', 'The request body must be a JSON object.');
            return;
        }
        $uris = $in['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > 10) {
            self::oauthError(400, 'invalid_redirect_uri', 'redirect_uris must be a non-empty array (at most 10 URIs).');
            return;
        }
        foreach ($uris as $u) {
            if (!is_string($u) || ($problem = self::redirectUriProblem($u)) !== null) {
                self::oauthError(400, 'invalid_redirect_uri', is_string($u) ? $problem . ': ' . substr($u, 0, 200) : 'Each redirect URI must be a string.');
                return;
            }
        }
        $method = $in['token_endpoint_auth_method'] ?? 'client_secret_basic'; // valeur par défaut de la RFC 7591
        if (!is_string($method) || !in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            self::oauthError(400, 'invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_post or client_secret_basic.');
            return;
        }
        // Les autres valeurs éventuelles sont remplacées par celles prises en charge (RFC 7591 §3.2.1).
        $grants = $in['grant_types'] ?? ['authorization_code'];
        if (!is_array($grants) || !in_array('authorization_code', $grants, true)) {
            self::oauthError(400, 'invalid_client_metadata', 'grant_types must include authorization_code (supported: authorization_code, refresh_token).');
            return;
        }
        $responseTypes = $in['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || !in_array('code', $responseTypes, true)) {
            self::oauthError(400, 'invalid_client_metadata', 'response_types must include "code".');
            return;
        }
        $name = self::cleanText(is_string($in['client_name'] ?? null) ? $in['client_name'] : '', 100);
        $clientUri = is_string($in['client_uri'] ?? null) && preg_match('#^https://#i', $in['client_uri']) ? substr($in['client_uri'], 0, 300) : '';

        self::purgeClients();
        $id = Crypto::token(18);
        $secret = $method === 'none' ? null : Crypto::token(32);
        $now = time();
        $grantTypes = ['authorization_code', 'refresh_token'];
        Db::insert('oauth_clients', [
            'id' => $id,
            'secret' => $secret !== null ? Crypto::hash($secret) : null,
            'name' => $name,
            'redirect_uris' => json_enc(array_values($uris)),
            'data' => json_enc([
                'token_endpoint_auth_method' => $method,
                'grant_types' => $grantTypes,
                'client_uri' => $clientUri,
                'software_id' => self::cleanText(is_string($in['software_id'] ?? null) ? $in['software_id'] : '', 100),
                'software_version' => self::cleanText(is_string($in['software_version'] ?? null) ? $in['software_version'] : '', 40),
            ]),
            'created_at' => $now,
        ]);
        $out = [
            'client_id' => $id,
            'client_id_issued_at' => $now,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope' => self::SCOPE,
        ];
        if ($clientUri !== '') {
            $out['client_uri'] = $clientUri;
        }
        if ($secret !== null) {
            $out['client_secret'] = $secret;
            $out['client_secret_expires_at'] = 0;
        }
        Response::json(201, $out, ['Pragma: no-cache']);
    }

    /** null si l'adresse de retour est acceptable, sinon la raison du refus (anglais). */
    public static function redirectUriProblem(string $uri): ?string
    {
        if ($uri === '' || strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            return 'Invalid redirect URI';
        }
        $p = parse_url($uri);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return 'Redirect URI must be an absolute URL';
        }
        if (isset($p['fragment']) || str_contains($uri, '#')) {
            return 'Redirect URI must not contain a fragment';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'Redirect URI must not contain credentials';
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme === 'https') {
            return null;
        }
        if ($scheme === 'http' && self::isLoopback((string) $p['host'])) {
            return null;
        }
        return 'Redirect URI must use https (http is only allowed for localhost / 127.0.0.1)';
    }

    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** Supprime les inscriptions jamais utilisées de plus de 24 h (anti-encombrement). */
    private static function purgeClients(): void
    {
        Db::run("DELETE FROM oauth_clients WHERE created_at < ? AND json_extract(data, '$.authorized_at') IS NULL
                 AND NOT EXISTS (SELECT 1 FROM tokens t WHERE t.client_id = oauth_clients.id)", [time() - 86400]);
    }

    /** Client connu du site (inscrit, ou fiche déjà lue pour un identifiant en adresse https). */
    public static function client(string $id): ?array
    {
        if ($id === '' || strlen($id) > 2000) {
            return null;
        }
        $row = Db::one('SELECT * FROM oauth_clients WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        $row['redirect_uris'] = (array) json_dec((string) $row['redirect_uris'], []);
        $row['data'] = (array) json_dec((string) $row['data'], []);
        return $row;
    }

    // ------------------------------------------------------------------
    // Clients identifiés par une adresse (Client ID Metadata Documents)
    // ------------------------------------------------------------------

    /** Vrai si l'identifiant est une adresse : les identifiants inscrits n'en contiennent jamais. */
    public static function isMetadataUrl(string $id): bool
    {
        return str_contains($id, '://');
    }

    /** null si l'adresse peut servir d'identifiant de client (brouillon IETF, §3), sinon la raison. */
    public static function clientIdUrlProblem(string $url): ?string
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return __('adresse invalide');
        }
        $p = parse_url($url);
        if ($p === false || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) {
            return __('l’adresse doit commencer par https://');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return __('l’adresse ne doit pas contenir d’identifiants');
        }
        if (isset($p['fragment']) || str_contains($url, '#')) {
            return __('l’adresse ne doit pas contenir de fragment (#)');
        }
        $path = (string) ($p['path'] ?? '');
        if ($path === '') {
            return __('l’adresse doit désigner un document (exemple : https://exemple.com/client.json)');
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(rawurldecode($segment), ['.', '..'], true)) {
                return __('l’adresse ne doit pas contenir de segment « . » ou « .. »');
            }
        }
        return null;
    }

    /**
     * Client identifié par une adresse https : la fiche JSON publiée à cette adresse est
     * lue (5 Ko au plus, sans redirection, adresses internes refusées), vérifiée, puis
     * gardée en cache selon ses en-têtes HTTP (24 h au plus). Une erreur ou une fiche
     * invalide n'est jamais gardée.
     * @return array{0: ?array, 1: string} [client, raison du refus]
     */
    private static function metadataClient(string $url): array
    {
        if (($problem = self::clientIdUrlProblem($url)) !== null) {
            return [null, $problem];
        }
        $cached = self::client($url);
        if ($cached && !empty($cached['data']['cimd']) && (int) ($cached['data']['expires_at'] ?? 0) > time()) {
            return [$cached, ''];
        }
        $key = 'oauth:cimd:' . ip_hash();
        if (Throttle::blocked($key) > 0 || Throttle::hit($key, 30, 3600, 3600) > 0) {
            return [null, __('trop de tentatives, réessayez dans une heure')];
        }
        try {
            $res = HttpClient::get($url, ['max_bytes' => self::CIMD_MAX_BYTES, 'timeout' => 5, 'redirects' => 0, 'headers' => ['Accept: application/json']]);
        } catch (\Throwable $e) {
            return [null, __('lecture impossible ({error})', ['error' => $e->getMessage()])];
        }
        if ($res['status'] !== 200) {
            return [null, __('la fiche est inaccessible (code HTTP {code})', ['code' => $res['status']])];
        }
        $doc = json_decode($res['body'], true);
        if (!is_array($doc) || $doc === [] || array_is_list($doc)) {
            return [null, __('la fiche n’est pas un objet JSON valide')];
        }
        if (($doc['client_id'] ?? null) !== $url) {
            return [null, __('le champ client_id de la fiche ne correspond pas à son adresse')];
        }
        $name = self::cleanText(is_string($doc['client_name'] ?? null) ? $doc['client_name'] : '', 100);
        if ($name === '') {
            return [null, __('le champ client_name est absent')];
        }
        $uris = $doc['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > 20) {
            return [null, __('le champ redirect_uris est absent ou invalide')];
        }
        foreach ($uris as $u) {
            if (!is_string($u) || self::redirectUriProblem($u) !== null) {
                return [null, __('adresse de retour non conforme : {uri}', ['uri' => is_string($u) ? substr($u, 0, 200) : '?'])];
            }
        }
        if (array_key_exists('client_secret', $doc) || array_key_exists('client_secret_expires_at', $doc)) {
            return [null, __('une fiche publique ne doit pas contenir de secret')];
        }
        // Pas de secret partagé possible. private_key_jwt est accepté, mais le client est traité
        // comme un client public : PKCE et l'adresse de retour exacte protègent l'échange.
        $method = $doc['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'private_key_jwt'], true)) {
            return [null, __('méthode d’authentification non prise en charge : {method}', ['method' => is_string($method) ? substr($method, 0, 40) : '?'])];
        }
        if (isset($doc['grant_types']) && (!is_array($doc['grant_types']) || !in_array('authorization_code', $doc['grant_types'], true))) {
            return [null, __('le champ grant_types doit contenir authorization_code')];
        }
        if (isset($doc['response_types']) && (!is_array($doc['response_types']) || !in_array('code', $doc['response_types'], true))) {
            return [null, __('le champ response_types doit contenir code')];
        }
        $now = time();
        $data = [
            'cimd' => true,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'client_uri' => is_string($doc['client_uri'] ?? null) && preg_match('#^https://#i', $doc['client_uri']) ? substr($doc['client_uri'], 0, 300) : '',
            'fetched_at' => $now,
            'expires_at' => $now + self::cacheTtl($res['headers']),
        ];
        if (!empty($cached['data']['authorized_at'])) {
            $data['authorized_at'] = (int) $cached['data']['authorized_at'];
        }
        if (!$cached) {
            self::purgeClients();
        }
        Db::upsert('oauth_clients', [
            'id' => $url,
            'secret' => null,
            'name' => $name,
            'redirect_uris' => json_enc(array_values($uris)),
            'data' => json_enc($data),
            'created_at' => $cached ? (int) $cached['created_at'] : $now,
        ], ['id']);
        return [self::client($url), ''];
    }

    /** Durée de cache d'une fiche selon Cache-Control (max-age, no-store, no-cache) ou Expires. */
    private static function cacheTtl(array $headers): int
    {
        $cc = strtolower((string) ($headers['cache-control'] ?? ''));
        if (preg_match('/\b(no-store|no-cache)\b/', $cc)) {
            return 0;
        }
        if (preg_match('/\bmax-age\s*=\s*"?(\d+)/', $cc, $m)) {
            $ttl = (int) $m[1] - (int) ($headers['age'] ?? 0);
        } elseif (($expires = strtotime((string) ($headers['expires'] ?? ''))) !== false && isset($headers['expires'])) {
            $ttl = $expires - time();
        } else {
            $ttl = self::CIMD_TTL;
        }
        return max(0, min(self::CIMD_MAX_TTL, $ttl));
    }

    /**
     * Adresse de retour effective : identique à l'une des adresses inscrites ; pour
     * une adresse locale en http (application sur l'ordinateur), le port peut varier
     * (RFC 8252 §7.3). Sans redirect_uri, l'unique adresse inscrite est utilisée.
     */
    private static function matchRedirect(array $client, string $requested): ?string
    {
        $registered = array_values(array_filter($client['redirect_uris'], 'is_string'));
        if ($requested === '') {
            return count($registered) === 1 ? $registered[0] : null;
        }
        if (in_array($requested, $registered, true)) {
            return $requested;
        }
        $r = parse_url($requested);
        if ($r === false || strtolower($r['scheme'] ?? '') !== 'http' || !self::isLoopback((string) ($r['host'] ?? '')) || isset($r['fragment'])) {
            return null;
        }
        foreach ($registered as $uri) {
            $p = parse_url($uri);
            if ($p !== false && strtolower($p['scheme'] ?? '') === 'http' && strtolower((string) ($p['host'] ?? '')) === strtolower((string) $r['host'])
                && ($p['path'] ?? '') === ($r['path'] ?? '') && ($p['query'] ?? '') === ($r['query'] ?? '')) {
                return $requested;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Autorisation et consentement
    // ------------------------------------------------------------------

    /** @param array<string, string> $p paramètres de la requête (GET) ou du formulaire (POST) */
    private static function authorize(array $p, bool $isPost): void
    {
        $clientId = $p['client_id'] ?? '';
        if (self::isMetadataUrl($clientId)) {
            $host = (string) parse_url($clientId, PHP_URL_HOST);
            if (($problem = self::clientIdUrlProblem($clientId)) !== null) {
                self::errorPage(400, __('Application non vérifiable'), __('L’identifiant de cette application n’est pas une adresse valable ({reason}). Par sécurité, la connexion est bloquée.', ['reason' => $problem]));
                return;
            }
            if (!Auth::user()) {
                // Un visiteur anonyme ne déclenche aucune requête sortante : connexion d'abord.
                self::loginFirst($isPost);
                return;
            }
            [$client, $problem] = self::metadataClient($clientId);
            if (!$client) {
                self::errorPage(400, __('Application non vérifiable'), __('Ce site n’a pas pu valider la fiche publiée par l’application sur {host} : {reason}. Relancez la connexion dans quelques minutes ; si le problème persiste, contactez l’éditeur de l’application.', ['host' => $host, 'reason' => $problem]));
                return;
            }
        } else {
            $client = self::client($clientId);
        }
        if (!$client) {
            self::errorPage(400, __('Application inconnue'), __('Cette demande de connexion vient d’une application qui n’est pas (ou plus) inscrite sur ce site. Relancez la connexion depuis votre assistant IA.'));
            return;
        }
        $redirect = self::matchRedirect($client, $p['redirect_uri'] ?? '');
        if ($redirect === null) {
            self::errorPage(400, __('Adresse de retour refusée'), __('L’adresse vers laquelle cette application veut vous renvoyer n’est pas celle qu’elle a déclarée. Par sécurité, la connexion est bloquée.'));
            return;
        }
        $state = $p['state'] ?? '';
        if (strlen($state) > 2000) {
            self::redirectError($redirect, 'invalid_request', 'state is too long', '');
            return;
        }
        if (($p['response_type'] ?? '') !== 'code') {
            self::redirectError($redirect, 'unsupported_response_type', 'Only response_type=code is supported', $state);
            return;
        }
        $challenge = $p['code_challenge'] ?? '';
        if ($challenge === '' || ($p['code_challenge_method'] ?? '') !== 'S256') {
            self::redirectError($redirect, 'invalid_request', 'PKCE is required: send code_challenge with code_challenge_method=S256', $state);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            self::redirectError($redirect, 'invalid_request', 'Invalid code_challenge (expected BASE64URL(SHA256(code_verifier)))', $state);
            return;
        }
        $resource = $p['resource'] ?? '';
        if ($resource !== '' && !self::resourceMatches($resource)) {
            self::redirectError($redirect, 'invalid_target', 'Unknown resource: use ' . self::resource(), $state);
            return;
        }

        $user = Auth::user();
        if (!$user) {
            self::loginFirst($isPost);
            return;
        }

        if ($isPost) {
            if (!Auth::verifyCsrf()) {
                self::errorPage(400, __('Session expirée'), __('Le formulaire a expiré. Relancez la connexion depuis votre assistant IA.'));
                return;
            }
            if (($p['decision'] ?? '') !== 'approve') {
                Activity::log('mcp', 'oauth', null, __('Connexion refusée : {app}', ['app' => self::clientLabel($client, $redirect)]));
                self::redirectError($redirect, 'access_denied', 'The user denied the request', $state);
                return;
            }
            // Anti « double-clickjacking » : une page qui ouvre l'écran de consentement sous
            // le pointeur obtient un clic dans la fraction de seconde. On exige que l'écran
            // ait été affiché au moins une seconde (horodatage signé) ; sinon il est remontré.
            $shown = self::consentAge($p['_t'] ?? '', (string) $client['id']);
            if ($shown === null || $shown < self::MIN_CONSENT_MS) {
                self::consentPage($client, $redirect, $p, $user, __('Pour votre sécurité, vérifiez la demande ci-dessous, puis cliquez à nouveau sur « Autoriser ».'));
                return;
            }
            $code = Tokens::issue('oauth_code', '', (int) $user['id'], (string) $client['id'], Tokens::CODE_TTL, [
                'grant' => Crypto::token(16),
                'redirect_uri' => $redirect,
                'redirect_uri_sent' => ($p['redirect_uri'] ?? '') !== '',
                'code_challenge' => $challenge,
                'scope' => self::SCOPE,
                'resource' => $resource !== '' ? self::resource() : null,
                'used' => 0,
            ]);
            Db::run("UPDATE oauth_clients SET data = json_set(data, '$.authorized_at', ?) WHERE id = ? AND json_extract(data, '$.authorized_at') IS NULL", [time(), $client['id']]);
            Activity::log('mcp', 'oauth', null, __('Connexion autorisée : {app}', ['app' => self::clientLabel($client, $redirect)]));
            self::redirectTo($redirect, ['code' => $code, 'state' => $state]);
            return;
        }

        self::consentPage($client, $redirect, $p, $user);
    }

    /** Visiteur non connecté : page de connexion de l'administration, puis retour à l'autorisation. */
    private static function loginFirst(bool $isPost): void
    {
        if ($isPost) {
            self::errorPage(401, __('Session expirée'), __('Votre session a expiré. Relancez la connexion depuis votre assistant IA.'));
            return;
        }
        // Même convention que l'administration : chemin depuis la racine du serveur (sous-dossier compris).
        $back = Config::basePath() . '/oauth/authorize' . (Http::query() !== '' ? '?' . Http::query() : '');
        self::sendHtmlHeaders();
        header('Location: ' . admin_url('login') . '?redirect=' . rawurlencode($back), true, 302);
    }

    /** Écran de consentement ; les paramètres d'origine sont renvoyés tels quels dans le formulaire. */
    private static function consentPage(array $client, string $redirect, array $p, array $user, string $notice = ''): void
    {
        $host = (string) parse_url($redirect, PHP_URL_HOST);
        $cimd = !empty($client['data']['cimd']);
        $fields = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k) {
            if (($p[$k] ?? '') !== '') {
                $fields[$k] = $p[$k];
            }
        }
        $fields['_t'] = self::consentStamp((string) $client['id']);
        self::sendHtmlHeaders(self::origin($redirect));
        echo View::admin('oauth-consent', [
            'user' => $user,
            'roleLabel' => __((string) (Auth::ROLES[$user['role']]['label'] ?? $user['role'])),
            'client' => [
                'name' => (string) $client['name'] !== '' ? (string) $client['name'] : $host,
                'host' => $host,
                'local' => self::isLoopback($host),
                // Fiche publiée par l'application : l'hôte de son identifiant est affiché (anti-hameçonnage).
                'publisher' => $cimd ? (string) parse_url((string) $client['id'], PHP_URL_HOST) : '',
            ],
            'permissions' => self::permissions($user),
            'fields' => $fields,
            'notice' => $notice,
            'action' => Config::basePath() . '/oauth/authorize',
            'siteName' => (string) Settings::get('site.name', 'Wordless'),
        ]);
    }

    /** Horodatage (ms) signé de l'affichage du consentement, lié à la session et au client. */
    private static function consentStamp(string $clientId): string
    {
        $ms = (string) (int) floor(microtime(true) * 1000);
        return $ms . '.' . Crypto::sign($ms . '|' . $clientId . '|' . Auth::csrf(), 'oauth-consent');
    }

    /** Millisecondes écoulées depuis l'affichage du consentement (null si l'horodatage est invalide). */
    private static function consentAge(string $stamp, string $clientId): ?int
    {
        if (!preg_match('/^(\d{10,16})\.([A-Za-z0-9_-]{20,})$/', $stamp, $m)
            || !Crypto::verify($m[1] . '|' . $clientId . '|' . Auth::csrf(), $m[2], 'oauth-consent')) {
            return null;
        }
        return (int) floor(microtime(true) * 1000) - (int) $m[1];
    }

    private static function resourceMatches(string $resource): bool
    {
        $norm = static function (string $u): string {
            $p = parse_url(rtrim($u, '/'));
            if ($p === false || empty($p['host'])) {
                return '';
            }
            return strtolower(($p['scheme'] ?? '') . '://' . $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
        };
        $want = $norm(self::resource());
        return $want !== '' && $norm($resource) === $want;
    }

    /** Ce que l'application pourra faire, en français simple, selon le rôle de l'utilisateur. */
    public static function permissions(array $user): array
    {
        $can = static fn (string $cap): bool => Auth::userCan($user, $cap);
        $out = [];
        if ($can('edit_content')) {
            $out[] = $can('edit_others')
                ? __('Lire, rédiger et modifier tous les articles et pages du site')
                : __('Lire les contenus publiés, rédiger et modifier vos propres articles et pages');
        }
        if ($can('publish')) {
            $out[] = __('Publier ou programmer des contenus (en brouillon par défaut)');
        }
        if ($can('delete_content')) {
            $out[] = __('Mettre des contenus à la corbeille (récupérables)');
        }
        if ($can('upload')) {
            $out[] = __('Ajouter des images à la médiathèque et compléter leurs textes alternatifs');
        }
        if ($can('view_stats')) {
            $out[] = __('Consulter les statistiques de visite et les rapports SEO et GEO');
        }
        if ($can('moderate')) {
            $out[] = __('Modérer les commentaires');
        }
        if ($can('manage_seo')) {
            $out[] = __('Gérer les redirections et le référencement');
        }
        if ($can('manage_design')) {
            $out[] = __('Modifier le design du site (couleurs, typographies, espacements)');
        }
        if ($can('manage_settings')) {
            $out[] = __('Modifier le nom, le slogan, l’accueil SEO et l’accès des robots d’IA');
        }
        return $out;
    }

    private static function clientLabel(array $client, string $redirect): string
    {
        $host = (string) parse_url($redirect, PHP_URL_HOST);
        $name = (string) $client['name'];
        return $name !== '' ? $name . ' (' . $host . ')' : $host;
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        $host = (string) ($p['host'] ?? '');
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return strtolower(($p['scheme'] ?? 'https') . '://' . $host) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function redirectError(string $redirect, string $error, string $description, string $state): void
    {
        self::redirectTo($redirect, ['error' => $error, 'error_description' => $description, 'state' => $state]);
    }

    /** Renvoie vers l'application avec les paramètres (et iss, RFC 9207). */
    private static function redirectTo(string $redirect, array $params): void
    {
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
        $params['iss'] = Config::baseUrl();
        $sep = str_contains($redirect, '?') ? '&' : '?';
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $redirect . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 302);
        }
    }

    /**
     * En-têtes des pages HTML (consentement, erreur) : ceux de l'admin, puis une CSP
     * plus stricte (aucun script ni style en ligne, pas d'intégration en cadre).
     * form-action autorise l'origine de retour : le navigateur applique cette règle
     * aussi à la redirection qui suit l'envoi du formulaire.
     */
    private static function sendHtmlHeaders(string $formTarget = ''): void
    {
        if (headers_sent()) {
            return;
        }
        Http::sendHeaders(Http::securityHeaders('admin'));
        $form = "'self'" . ($formTarget !== '' ? ' ' . $formTarget : '');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; form-action $form; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
    }

    private static function errorPage(int $status, string $title, string $message): void
    {
        self::sendHtmlHeaders();
        http_response_code($status);
        echo View::admin('oauth-error', [
            'title' => $title,
            'message' => $message,
            'siteName' => (string) Settings::get('site.name', 'Wordless'),
        ]);
    }

    // ------------------------------------------------------------------
    // Jetons
    // ------------------------------------------------------------------

    private static function token(): void
    {
        $key = 'oauth:token:' . ip_hash();
        if (($wait = Throttle::blocked($key)) > 0 || ($wait = Throttle::hit($key, 120, 60, 60)) > 0) {
            self::oauthError(429, 'temporarily_unavailable', 'Too many requests, retry later.', ['Retry-After: ' . $wait]);
            return;
        }
        $in = self::params();
        [$client, $basic] = self::authenticateClient($in);
        if (!$client) {
            self::oauthError(401, 'invalid_client', 'Client authentication failed.', $basic ? ['WWW-Authenticate: Basic realm="wordless"'] : []);
            return;
        }
        switch ($in['grant_type'] ?? '') {
            case 'authorization_code':
                self::exchangeCode($client, $in);
                return;
            case 'refresh_token':
                self::refresh($client, $in);
                return;
            case '':
                self::oauthError(400, 'invalid_request', 'grant_type is required.');
                return;
            default:
                self::oauthError(400, 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.');
        }
    }

    private static function exchangeCode(array $client, array $in): void
    {
        $code = $in['code'] ?? '';
        $verifier = $in['code_verifier'] ?? '';
        if ($code === '') {
            self::oauthError(400, 'invalid_request', 'code is required.');
            return;
        }
        $row = Tokens::find($code, ['oauth_code']);
        if (!$row || $row['client_id'] !== $client['id']) {
            self::oauthError(400, 'invalid_grant', 'Invalid authorization code.');
            return;
        }
        $d = $row['data'];
        if (!empty($d['used'])) {
            self::reuseDetected($row, __('code d’autorisation'));
            self::oauthError(400, 'invalid_grant', 'Authorization code already used: the related tokens were revoked.');
            return;
        }
        if (Tokens::expired($row)) {
            Db::delete('tokens', ['id' => $row['id']]);
            self::oauthError(400, 'invalid_grant', 'Authorization code expired.');
            return;
        }
        $redirect = $in['redirect_uri'] ?? '';
        if (($redirect !== '' || !empty($d['redirect_uri_sent'])) && $redirect !== (string) ($d['redirect_uri'] ?? '')) {
            self::oauthError(400, 'invalid_grant', 'redirect_uri does not match the authorization request.');
            return;
        }
        if ($verifier === '') {
            self::oauthError(400, 'invalid_request', 'code_verifier is required (PKCE).');
            return;
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) ($d['code_challenge'] ?? ''), self::s256($verifier))) {
            self::oauthError(400, 'invalid_grant', 'PKCE verification failed.');
            return;
        }
        if (($in['resource'] ?? '') !== '' && !self::resourceMatches($in['resource'])) {
            self::oauthError(400, 'invalid_target', 'Unknown resource: use ' . self::resource());
            return;
        }
        // Consommation atomique : un seul échange possible, même en cas de requêtes simultanées.
        $won = Db::run("UPDATE tokens SET data = json_set(data, '$.used', 1), last_used_at = ? WHERE id = ? AND COALESCE(json_extract(data, '$.used'), 0) = 0", [time(), $row['id']])->rowCount();
        if ($won !== 1) {
            self::reuseDetected($row, __('code d’autorisation'));
            self::oauthError(400, 'invalid_grant', 'Authorization code already used.');
            return;
        }
        $user = Db::one("SELECT id FROM users WHERE id = ? AND status = 'active'", [(int) $row['user_id']]);
        if (!$user) {
            self::oauthError(400, 'invalid_grant', 'The user account is no longer active.');
            return;
        }
        $grant = ['grant' => (string) ($d['grant'] ?? Crypto::token(16)), 'resource' => $d['resource'] ?? null, 'since' => time()];
        Response::json(200, self::issueTokens($client, (int) $user['id'], $grant), ['Pragma: no-cache']);
    }

    private static function refresh(array $client, array $in): void
    {
        $plain = $in['refresh_token'] ?? '';
        if ($plain === '') {
            self::oauthError(400, 'invalid_request', 'refresh_token is required.');
            return;
        }
        $row = Tokens::find($plain, ['oauth_refresh']);
        if (!$row || $row['client_id'] !== $client['id']) {
            self::oauthError(400, 'invalid_grant', 'Invalid refresh token.');
            return;
        }
        if (!empty($row['data']['rotated'])) {
            self::reuseDetected($row, __('jeton de rafraîchissement'));
            self::oauthError(400, 'invalid_grant', 'Refresh token already used: the whole grant was revoked, please sign in again.');
            return;
        }
        if (Tokens::expired($row)) {
            Db::delete('tokens', ['id' => $row['id']]);
            self::oauthError(400, 'invalid_grant', 'Refresh token expired, please sign in again.');
            return;
        }
        if (($in['resource'] ?? '') !== '' && !self::resourceMatches($in['resource'])) {
            self::oauthError(400, 'invalid_target', 'Unknown resource: use ' . self::resource());
            return;
        }
        // Rotation : l'ancien jeton est marqué et conservé 14 jours au plus pour détecter sa réutilisation.
        $won = Db::run("UPDATE tokens SET data = json_set(data, '$.rotated', 1), last_used_at = ?, expires_at = MIN(expires_at, ?)
            WHERE id = ? AND COALESCE(json_extract(data, '$.rotated'), 0) = 0", [time(), time() + 14 * 86400, $row['id']])->rowCount();
        if ($won !== 1) {
            self::reuseDetected($row, __('jeton de rafraîchissement'));
            self::oauthError(400, 'invalid_grant', 'Refresh token already used.');
            return;
        }
        $user = Db::one("SELECT id FROM users WHERE id = ? AND status = 'active'", [(int) $row['user_id']]);
        if (!$user) {
            self::oauthError(400, 'invalid_grant', 'The user account is no longer active.');
            return;
        }
        $d = $row['data'];
        $grant = ['grant' => (string) ($d['grant'] ?? ''), 'resource' => $d['resource'] ?? null, 'since' => (int) ($d['since'] ?? $row['created_at'])];
        Response::json(200, self::issueTokens($client, (int) $user['id'], $grant), ['Pragma: no-cache']);
    }

    private static function issueTokens(array $client, int $userId, array $grant): array
    {
        $data = ['grant' => $grant['grant'], 'scope' => self::SCOPE, 'resource' => $grant['resource'], 'since' => $grant['since']];
        $access = Tokens::issue('oauth_access', 'wlat_', $userId, (string) $client['id'], Tokens::ACCESS_TTL, $data);
        $refresh = Tokens::issue('oauth_refresh', 'wlrt_', $userId, (string) $client['id'], Tokens::REFRESH_TTL, $data);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => Tokens::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    /** Un code ou un jeton de rafraîchissement déjà consommé revient : vol probable, tout est révoqué. */
    private static function reuseDetected(array $row, string $what): void
    {
        $n = Tokens::revokeGrant((string) $row['client_id'], (string) ($row['data']['grant'] ?? ''));
        Db::delete('tokens', ['id' => $row['id']]);
        $client = self::client((string) $row['client_id']);
        Activity::log('mcp', 'oauth', null, __('Réutilisation d’un {what} détectée : accès de « {app} » révoqué ({n} jeton(s))', [
            'what' => $what, 'app' => $client ? ($client['name'] !== '' ? $client['name'] : $client['id']) : '?', 'n' => $n,
        ]), $row['user_id'] !== null ? (int) $row['user_id'] : null);
    }

    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Authentifie le client (Basic, client_secret_post ou public avec client_id seul).
     * @return array{0: ?array, 1: bool} [client ou null, authentification Basic tentée]
     */
    private static function authenticateClient(array $in): array
    {
        $basic = self::basicCredentials();
        $id = $basic[0] ?? ($in['client_id'] ?? '');
        $secret = $basic[1] ?? ($in['client_secret'] ?? null);
        if ($basic !== null && ($in['client_id'] ?? '') !== '' && $in['client_id'] !== $basic[0]) {
            return [null, true];
        }
        $client = self::client($id);
        if (!$client) {
            return [null, $basic !== null];
        }
        if ((string) ($client['secret'] ?? '') !== '') {
            if ($secret === null || $secret === '' || !hash_equals((string) $client['secret'], Crypto::hash($secret))) {
                return [null, $basic !== null];
            }
        }
        return [$client, $basic !== null];
    }

    /** @return array{0: string, 1: string}|null identifiants HTTP Basic (RFC 6749 §2.3.1) */
    private static function basicCredentials(): ?array
    {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $h, $m)) {
            $raw = base64_decode($m[1], true);
            if ($raw !== false && str_contains($raw, ':')) {
                [$id, $secret] = explode(':', $raw, 2);
                return [urldecode($id), urldecode($secret)];
            }
            return ['', ''];
        }
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Révocation (RFC 7009)
    // ------------------------------------------------------------------

    private static function revoke(): void
    {
        $key = 'oauth:token:' . ip_hash();
        if (($wait = Throttle::blocked($key)) > 0 || ($wait = Throttle::hit($key, 120, 60, 60)) > 0) {
            self::oauthError(429, 'temporarily_unavailable', 'Too many requests, retry later.', ['Retry-After: ' . $wait]);
            return;
        }
        $in = self::params();
        $client = null;
        if (($in['client_id'] ?? '') !== '' || self::basicCredentials() !== null) {
            [$client, $basic] = self::authenticateClient($in);
            if (!$client) {
                self::oauthError(401, 'invalid_client', 'Client authentication failed.', $basic ? ['WWW-Authenticate: Basic realm="wordless"'] : []);
                return;
            }
        }
        $plain = $in['token'] ?? '';
        if ($plain === '') {
            self::oauthError(400, 'invalid_request', 'token is required.');
            return;
        }
        $row = Tokens::find($plain, ['oauth_access', 'oauth_refresh']);
        // Jeton inconnu ou d'un autre client : réponse identique, rien n'est divulgué.
        if ($row && (!$client || $row['client_id'] === $client['id'])) {
            if ($row['kind'] === 'oauth_refresh') {
                Tokens::revokeGrant((string) $row['client_id'], (string) ($row['data']['grant'] ?? ''));
            }
            Db::delete('tokens', ['id' => $row['id']]);
        }
        Response::json(200, new \stdClass(), ['Pragma: no-cache']);
    }

    // ------------------------------------------------------------------
    // Applications connectées (écran d'administration)
    // ------------------------------------------------------------------

    /**
     * Applications ayant un accès actif pour cet utilisateur.
     * @return list<array{id: string, name: string, redirect_host: string, connected_at: int, last_used_at: ?int, expires_at: int}>
     */
    public static function clients(int $userId): array
    {
        $rows = Db::all("SELECT client_id, MIN(COALESCE(json_extract(data, '$.since'), created_at)) AS since,
                MAX(COALESCE(last_used_at, 0)) AS last_used, MAX(expires_at) AS expires
            FROM tokens WHERE user_id = ? AND client_id IS NOT NULL AND kind IN ('oauth_access', 'oauth_refresh')
            GROUP BY client_id
            HAVING SUM(CASE WHEN expires_at > ? AND COALESCE(json_extract(data, '$.rotated'), 0) = 0 THEN 1 ELSE 0 END) > 0
            ORDER BY last_used DESC", [$userId, time()]);
        $out = [];
        foreach ($rows as $r) {
            $client = self::client((string) $r['client_id']);
            if (!$client) {
                continue;
            }
            $host = (string) parse_url((string) ($client['redirect_uris'][0] ?? ''), PHP_URL_HOST);
            $out[] = [
                'id' => (string) $client['id'],
                'name' => (string) $client['name'] !== '' ? (string) $client['name'] : $host,
                'redirect_host' => $host,
                'publisher' => !empty($client['data']['cimd']) ? (string) parse_url((string) $client['id'], PHP_URL_HOST) : '',
                'connected_at' => (int) $r['since'],
                'last_used_at' => (int) $r['last_used'] > 0 ? (int) $r['last_used'] : null,
                'expires_at' => (int) $r['expires'],
            ];
        }
        return $out;
    }

    /** Retire à une application tout accès au compte de cet utilisateur. */
    public static function revokeClient(string $clientId, int $userId): void
    {
        $n = Db::run("DELETE FROM tokens WHERE client_id = ? AND user_id = ? AND kind IN ('oauth_code', 'oauth_access', 'oauth_refresh')", [$clientId, $userId])->rowCount();
        if ($n > 0) {
            $client = self::client($clientId);
            Activity::log('mcp', 'oauth', null, __('Accès révoqué : {app}', ['app' => $client && $client['name'] !== '' ? $client['name'] : $clientId]));
        }
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /** Paramètres d'une requête POST : formulaire (standard OAuth) ou JSON (toléré). */
    private static function params(): array
    {
        if ($_POST) {
            return self::strings($_POST);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === '' || strlen($raw) > 65536) {
            return [];
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'json') || str_starts_with(ltrim($raw), '{')) {
            $data = json_decode($raw, true);
            return is_array($data) ? self::strings($data) : [];
        }
        parse_str($raw, $data);
        return self::strings($data);
    }

    /** Ne garde que les valeurs texte (un paramètre en tableau est ignoré) ; state est rendu tel quel. */
    private static function strings(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (is_string($v) || is_int($v)) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    private static function cleanText(string $s, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($s)) ?? '');
        return mb_substr($s, 0, $max);
    }

    private static function oauthError(int $status, string $error, string $description, array $headers = []): void
    {
        Response::json($status, ['error' => $error, 'error_description' => $description], array_merge(['Pragma: no-cache'], $headers));
    }

    private static function notAllowed(string $allow): void
    {
        Response::json(405, ['error' => 'method_not_allowed'], ['Allow: ' . $allow]);
    }
}
