<?php
/**
 * Wordless — CMS léger, rapide et sécurisé.
 * Point d'entrée unique. Les pages déjà en cache sont servies avant même
 * de charger l'application (aucune requête SQL, aucun include superflu).
 */
declare(strict_types=1);

define('WL_ROOT', __DIR__);
define('WL_T0', hrtime(true));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_COOKIE['wl_s'])) {
    require __DIR__ . '/core/fastcache.php';
}

require __DIR__ . '/core/bootstrap.php';
Wordless\App::run();
