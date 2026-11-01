<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Système d'extension minimal : actions (wl_do) et filtres (wl_filter).
 * Les extensions sont de simples fichiers plugins/<nom>/plugin.php.
 */
final class Hooks
{
    /** @var array<string, array<int, list<callable>>> */
    private static array $hooks = [];

    public static function on(string $hook, callable $fn, int $priority = 10): void
    {
        self::$hooks[$hook][$priority][] = $fn;
    }

    public static function has(string $hook): bool
    {
        return !empty(self::$hooks[$hook]);
    }

    public static function run(string $hook, mixed ...$args): void
    {
        foreach (self::ordered($hook) as $fn) {
            $fn(...$args);
        }
    }

    public static function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach (self::ordered($hook) as $fn) {
            $value = $fn($value, ...$args);
        }
        return $value;
    }

    /** @return list<callable> */
    private static function ordered(string $hook): array
    {
        if (empty(self::$hooks[$hook])) {
            return [];
        }
        ksort(self::$hooks[$hook]);
        return array_merge(...array_values(self::$hooks[$hook]));
    }

    /** Charge les extensions actives (réglage plugins.active). */
    public static function loadPlugins(): void
    {
        foreach ((array) Settings::get('plugins.active', []) as $name) {
            $name = basename((string) $name);
            $file = WL_PLUGINS . '/' . $name . '/plugin.php';
            if ($name !== '' && is_file($file)) {
                require_once $file;
            }
        }
    }
}
