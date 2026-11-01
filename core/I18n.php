<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Traductions : les chaînes sources sont en français dans le code,
 * core/lang/<langue>.php fournit les autres langues (en, es…).
 */
final class I18n
{
    private static string $lang = 'fr';
    /** @var array<string, string> */
    private static array $strings = [];

    public const LANGUAGES = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español'];

    public static function load(string $lang): void
    {
        $lang = strtolower(substr(preg_replace('/[^a-zA-Z_-]/', '', $lang) ?? 'fr', 0, 2));
        self::$lang = $lang !== '' ? $lang : 'fr';
        self::$strings = [];
        if (self::$lang !== 'fr') {
            $file = WL_CORE . '/lang/' . self::$lang . '.php';
            if (is_file($file)) {
                self::$strings = (array) require $file;
            }
        }
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function locale(): string
    {
        return ['fr' => 'fr_FR', 'en' => 'en_US', 'es' => 'es_ES', 'de' => 'de_DE', 'it' => 'it_IT', 'pt' => 'pt_PT'][self::$lang] ?? self::$lang;
    }

    public static function translate(string $text): string
    {
        return self::$strings[$text] ?? $text;
    }

    /** true si la forme plurielle s'applique (français : 0 et 1 au singulier). */
    public static function plural(int $n): bool
    {
        return self::$lang === 'fr' ? abs($n) > 1 : abs($n) !== 1;
    }

    public static function number(int|float $n, int $decimals = 0): string
    {
        [$dec, $th] = match (self::$lang) {
            'en' => ['.', ','],
            'es', 'de', 'it', 'pt' => [',', '.'],
            default => [',', "\u{202F}"],
        };
        return number_format((float) $n, $decimals, $dec, $th);
    }

    /** Langue préférée du navigateur parmi celles disponibles. */
    public static function detect(): string
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (preg_split('/,\s*/', $accept) ?: [] as $part) {
            $code = substr($part, 0, 2);
            if (isset(self::LANGUAGES[$code])) {
                return $code;
            }
        }
        return 'fr';
    }
}
