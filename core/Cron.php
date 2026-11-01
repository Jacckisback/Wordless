<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Tâches de fond, déclenchées au plus toutes les 10 minutes après une visite
 * (ou par une vraie tâche planifiée : php bin/wordless cron).
 */
final class Cron
{
    public static function maybeRun(): void
    {
        $flag = WL_DATA . '/cron.last';
        if (is_file($flag) && time() - (int) filemtime($flag) < 600) {
            return;
        }
        @touch($flag);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            self::run();
        } catch (\Throwable $e) {
            App::logError($e);
        }
    }

    public static function run(): array
    {
        @set_time_limit(120);
        $done = [];
        Analytics::aggregate();
        $done[] = 'stats';
        $done[] = 'indexnow:' . IndexNow::flush();
        Content::publishDue();
        Auth::purgeSessions();
        Throttle::purge();
        Db::run('DELETE FROM tokens WHERE expires_at < ?', [time()]);
        Backup::auto();
        Activity::purge();
        Db::run('DELETE FROM not_found WHERE last_at < ? AND hits < 3', [time() - 90 * 86400]);
        wl_do('cron');
        $done[] = 'maintenance';
        return $done;
    }
}
