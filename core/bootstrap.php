<?php
/**
 * Démarrage : constantes, chargement automatique des classes, aides globales.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Wordless nécessite PHP 8.1 ou plus récent.');
}

const WL_VERSION = '1.0.0';
/** Adresse du code source (licence AGPL-3.0, article 13 : à adapter dans une version modifiée). */
const WL_SOURCE = 'https://github.com/Jacckisback/Wordless';
defined('WL_ROOT') || define('WL_ROOT', dirname(__DIR__));
defined('WL_T0') || define('WL_T0', hrtime(true));
define('WL_CORE', WL_ROOT . '/core');
define('WL_DATA', (string) (getenv('WL_DATA_DIR') ?: WL_ROOT . '/data'));
define('WL_MEDIA', WL_ROOT . '/media');
define('WL_THEMES', WL_ROOT . '/themes');
define('WL_PLUGINS', WL_ROOT . '/plugins');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
date_default_timezone_set('UTC');
ini_set('default_charset', 'UTF-8');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Wordless\\', 9) === 0) {
        $file = WL_CORE . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require WL_CORE . '/helpers.php';
