<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Limitation de fréquence (connexion, formulaires, commentaires, API).
 * Fenêtre glissante simple + verrouillage exponentiel après abus.
 */
final class Throttle
{
    /** Vrai si la clé est actuellement bloquée. */
    public static function blocked(string $key): int
    {
        $row = Db::one('SELECT locked_until FROM throttle WHERE key = ?', [$key]);
        $until = (int) ($row['locked_until'] ?? 0);
        return $until > time() ? $until - time() : 0;
    }

    /**
     * Enregistre une tentative. Au-delà de $max dans la fenêtre, verrouille la clé
     * $lock secondes, doublées à chaque récidive dans les 24 h (plafond 24 h).
     * Retourne la durée de blocage appliquée (0 si la tentative est autorisée).
     * Toujours appeler blocked() avant hit().
     */
    public static function hit(string $key, int $max, int $window, int $lock = 0): int
    {
        $now = time();
        $row = Db::one('SELECT * FROM throttle WHERE key = ?', [$key]);
        if (!$row || (int) $row['window_start'] + $window < $now) {
            Db::upsert('throttle', [
                'key' => $key, 'hits' => 1, 'window_start' => $now,
                'locked_until' => $row ? (int) $row['locked_until'] : 0,
            ], ['key']);
            return 0;
        }
        $hits = (int) $row['hits'] + 1;
        if ($hits <= $max) {
            Db::update('throttle', ['hits' => $hits], ['key' => $key]);
            return 0;
        }
        $strikeKey = $key . '#strikes';
        $s = Db::one('SELECT hits, window_start FROM throttle WHERE key = ?', [$strikeKey]);
        $strikes = ($s && (int) $s['window_start'] + 86400 > $now) ? (int) $s['hits'] : 0;
        $duration = (int) min(86400, ($lock > 0 ? $lock : $window) * (2 ** min($strikes, 10)));
        Db::upsert('throttle', ['key' => $strikeKey, 'hits' => $strikes + 1, 'window_start' => $now, 'locked_until' => 0], ['key']);
        Db::update('throttle', ['hits' => 0, 'window_start' => $now, 'locked_until' => $now + $duration], ['key' => $key]);
        return $duration;
    }

    public static function clear(string $key): void
    {
        Db::delete('throttle', ['key' => $key]);
    }

    public static function purge(): void
    {
        Db::run('DELETE FROM throttle WHERE window_start < ? AND locked_until < ?', [time() - 86400, time()]);
    }
}
