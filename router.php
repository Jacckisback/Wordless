<?php
/**
 * Routeur pour le serveur de développement PHP :
 *   php -S localhost:8080 router.php
 * Les fichiers statiques existants sont servis tels quels (sauf zones privées).
 */
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (preg_match('#^/(data|core|tests|bin)(/|$)#', $path) || preg_match('#/\.(?!well-known/)#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !preg_match('#\.php$#', $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
