<?php
declare(strict_types=1);

namespace Wordless;

/** Gabarits PHP simples : variables extraites, sortie capturée. */
final class View
{
    public static function render(string $file, array $vars = []): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Gabarit introuvable : ' . $file);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Vue d'administration (core/views/admin/<nom>.php). */
    public static function admin(string $name, array $vars = []): string
    {
        return self::render(WL_CORE . '/views/admin/' . $name . '.php', $vars);
    }
}
