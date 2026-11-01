<?php
/**
 * Fonctions globales courtes, utilisées partout (vues, thèmes, extensions).
 */
declare(strict_types=1);

use Wordless\Auth;
use Wordless\Config;
use Wordless\I18n;
use Wordless\Settings;

/** Échappe pour HTML (texte et attributs). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Traduit une chaîne (source en français). Variables : {nom}. */
function __(string $text, array $vars = []): string
{
    $t = I18n::translate($text);
    if ($vars) {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        $t = strtr($t, $map);
    }
    return $t;
}

/** Pluriel : _n('{n} article', '{n} articles', $n). */
function _n(string $one, string $many, int $n, array $vars = []): string
{
    $vars['n'] = I18n::number($n);
    return __(I18n::plural($n) ? $many : $one, $vars);
}

/** Adresse absolue d'un chemin interne (sitemaps, canonical, flux). */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return Config::baseUrl() . link_path($path);
}

/**
 * Chemin public d'une page interne, en respectant le choix « barre finale » du
 * site (WordPress utilise /mon-article/ par défaut, on garde la même forme).
 */
function link_path(string $path): string
{
    if ($path === '' || $path === '/') {
        return '/';
    }
    $hash = '';
    if (($p = strpos($path, '#')) !== false) {
        $hash = substr($path, $p);
        $path = substr($path, 0, $p);
    }
    $query = '';
    if (($p = strpos($path, '?')) !== false) {
        $query = substr($path, $p);
        $path = substr($path, 0, $p);
    }
    $path = '/' . ltrim($path, '/');
    $isFile = (bool) preg_match('#\.[a-z0-9]{2,5}$#i', $path);
    if (!$isFile && Config::installed()) {
        $path = rtrim($path, '/');
        if (Settings::get('trailing_slash', true)) {
            $path .= '/';
        }
        if ($path === '') {
            $path = '/';
        }
    }
    return $path . $query . $hash;
}

/** Lien relatif à la racine du serveur (avec le sous-dossier éventuel). */
function href(string $path = '/'): string
{
    if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $path)) {
        return $path;
    }
    return Config::basePath() . link_path($path);
}

function admin_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return Config::basePath() . Config::adminPath() . ($path !== '' ? '/' . $path : '');
}

/** Fichier statique versionné (cache navigateur longue durée). */
function asset_url(string $relative): string
{
    $relative = ltrim($relative, '/');
    $file = WL_ROOT . '/' . $relative;
    $v = is_file($file) ? substr(md5((string) filemtime($file) . WL_VERSION), 0, 8) : WL_VERSION;
    return Config::basePath() . '/' . $relative . '?v=' . $v;
}

function media_url(string $relative): string
{
    return Config::basePath() . '/media/' . ltrim($relative, '/');
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('#^https?://#i', $to) && !str_starts_with($to, '/')) {
        $to = '/' . $to;
    }
    header('Location: ' . $to, true, $code);
    exit;
}

function json_response(mixed $data, int $code = 200, array $headers = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Valeur envoyée (POST d'abord, puis GET). */
function input(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    $json = Wordless\Http::json();
    return $json[$key] ?? $default;
}

function input_str(string $key, string $default = ''): string
{
    $v = input($key, $default);
    return is_scalar($v) ? trim((string) $v) : $default;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key, $default);
    return is_numeric($v) ? (int) $v : $default;
}

function is_post(): bool
{
    return Wordless\Http::method() === 'POST';
}

function now(): int
{
    return time();
}

function csrf_token(): string
{
    return Auth::csrf();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Auth::csrf()) . '">';
}

/** Identifiant d'URL : « Été à Málaga ! » => « ete-a-malaga ». */
function slugify(string $text, int $max = 80): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    static $tr = null;
    if ($tr === null && class_exists('Transliterator')) {
        $tr = Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?: false;
    }
    if ($tr) {
        $text = (string) $tr->transliterate($text);
    } else {
        $text = strtr($text, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
            'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a', 'Æ' => 'ae', 'Ç' => 'c',
            'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o', 'Ø' => 'o', 'Œ' => 'oe',
            'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y', '’' => '-', "'" => '-',
        ]);
        $text = strtolower($text);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if (strlen($text) > $max) {
        $text = rtrim(substr($text, 0, $max), '-');
        if (($p = strrpos($text, '-')) !== false && $p > $max / 2) {
            $text = substr($text, 0, $p);
        }
    }
    return $text;
}

/** Texte brut à partir de HTML, espaces normalisés. */
function plain_text(string $html): string
{
    $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
    $html = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/blockquote|/tr|/figcaption)\b[^>]*>#i', "$0 ", $html) ?? '';
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

/** Coupe proprement un texte à $len caractères, sans couper un mot. */
function excerpt(string $text, int $len = 160, string $more = '…'): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    $cut = mb_substr($text, 0, $len);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $len * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,;:.-–—") . $more;
}

function word_count(string $text): int
{
    return count(preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Empreinte d'IP non réversible (anti-spam, journaux) : on ne stocke jamais l'IP brute. */
function ip_hash(?string $ip = null): string
{
    return substr(hash_hmac('sha256', $ip ?? client_ip(), Config::installed() ? Config::secret() : 'wl'), 0, 20);
}

/** Date localisée selon la langue et le fuseau du site. */
function fmt_date(?int $ts, string $style = 'long'): string
{
    if (!$ts) {
        return '';
    }
    $tz = Config::installed() ? (string) Settings::get('timezone', 'Europe/Paris') : 'UTC';
    $lang = I18n::lang();
    if (class_exists('IntlDateFormatter')) {
        $types = [
            'long' => [IntlDateFormatter::LONG, IntlDateFormatter::NONE],
            'short' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE],
            'datetime' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT],
        ][$style] ?? [IntlDateFormatter::LONG, IntlDateFormatter::NONE];
        $f = new IntlDateFormatter($lang, $types[0], $types[1], $tz);
        $out = $f->format($ts);
        if ($out !== false) {
            return $out;
        }
    }
    $d = new DateTimeImmutable('@' . $ts);
    $d = $d->setTimezone(new DateTimeZone($tz));
    $months = [
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ][$lang] ?? null;
    if ($months === null || $style === 'short') {
        return $d->format($style === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y');
    }
    $m = $months[(int) $d->format('n') - 1];
    $s = $lang === 'en' ? "$m {$d->format('j, Y')}" : ($lang === 'es' ? "{$d->format('j')} de $m de {$d->format('Y')}" : "{$d->format('j')} $m {$d->format('Y')}");
    return $style === 'datetime' ? $s . ' ' . $d->format('H:i') : $s;
}

/** « il y a 5 min », « dans 2 jours ». */
function human_time(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $diff = time() - $ts;
    $abs = abs($diff);
    if ($abs < 60) {
        return __('à l’instant');
    }
    $units = [[86400 * 365, '{n} an', '{n} ans'], [86400 * 30, '{n} mois', '{n} mois'], [86400, '{n} jour', '{n} jours'], [3600, '{n} h', '{n} h'], [60, '{n} min', '{n} min']];
    foreach ($units as [$sec, $one, $many]) {
        if ($abs >= $sec) {
            $n = (int) floor($abs / $sec);
            $label = _n($one, $many, $n);
            return $diff >= 0 ? __('il y a {t}', ['t' => $label]) : __('dans {t}', ['t' => $label]);
        }
    }
    return '';
}

function fmt_bytes(int $bytes): string
{
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < 3) {
        $v /= 1024;
        $i++;
    }
    return I18n::number($v, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function json_enc(mixed $v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function json_dec(?string $v, mixed $default = []): mixed
{
    if ($v === null || $v === '') {
        return $default;
    }
    $d = json_decode($v, true);
    return $d ?? $default;
}

/** Icône SVG de l'interface (sprite admin). */
function icon(string $name, string $class = 'i'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(asset_url('admin/assets/icons.svg')) . '#' . e($name) . '"/></svg>';
}

/** Sélectionne la valeur d'un tableau imbriqué : arr_get($a, 'seo.title'). */
function arr_get(array $a, string $path, mixed $default = null): mixed
{
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return $default;
        }
        $a = $a[$k];
    }
    return $a;
}

/** Extension : déclencher / écouter des événements et des filtres. */
function wl_on(string $hook, callable $fn, int $priority = 10): void
{
    Wordless\Hooks::on($hook, $fn, $priority);
}

function wl_filter(string $hook, mixed $value, mixed ...$args): mixed
{
    return Wordless\Hooks::filter($hook, $value, ...$args);
}

function wl_do(string $hook, mixed ...$args): void
{
    Wordless\Hooks::run($hook, ...$args);
}
