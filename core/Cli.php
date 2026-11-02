<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Commandes en ligne de commande. Les modules ajoutent leurs commandes via
 * une classe <Module>\Cli exposant commands(): array<string, array{0: string, 1: callable}>.
 */
final class Cli
{
    public static function commands(): array
    {
        $cmds = [
            'help' => [__('Liste des commandes'), [self::class, 'help']],
            'install' => ['install --url=… --email=… --password=… [--name=…] [--site=…] [--lang=fr] [--no-starter]', [self::class, 'install']],
            'cron' => [__('Exécute les tâches de fond (à planifier toutes les 10 minutes)'), static function (): int {
                echo implode(', ', Cron::run()) . PHP_EOL;
                return 0;
            }],
            'cache:clear' => [__('Vide le cache de pages'), static function (): int {
                Cache::purge();
                Theme::resetCache();
                echo "OK\n";
                return 0;
            }],
            'search:rebuild' => [__('Reconstruit l’index de recherche'), static function (): int {
                echo __('{n} contenus indexés', ['n' => Search::rebuild()]) . PHP_EOL;
                return 0;
            }],
            'backup' => ['backup [--full]', static function (array $o): int {
                echo (isset($o['full']) ? Backup::createFull() : Backup::createDb()) . PHP_EOL;
                return 0;
            }],
            'user:password' => [__('user:password <email> <nouveau-mot-de-passe>'), static function (array $o, array $args): int {
                [$email, $pass] = array_pad($args, 2, '');
                if ($p = Auth::passwordProblem($pass, $email)) {
                    fwrite(STDERR, $p . PHP_EOL);
                    return 1;
                }
                $id = (int) Db::val('SELECT id FROM users WHERE email = ?', [mb_strtolower($email)]);
                if (!$id) {
                    fwrite(STDERR, __('Utilisateur introuvable : {e}', ['e' => $email]) . PHP_EOL);
                    return 1;
                }
                Db::update('users', ['password' => Auth::hashPassword($pass)], ['id' => $id]);
                Auth::logoutEverywhere($id); // un mot de passe changé ferme toutes les sessions
                echo "OK\n";
                return 0;
            }],
            'user:2fa-reset' => ['user:2fa-reset <email>', static function (array $o, array $args): int {
                $n = Db::update('users', ['totp_enabled' => 0, 'totp_secret' => '', 'recovery_codes' => '[]'], ['email' => mb_strtolower($args[0] ?? '')]);
                if (!$n) {
                    fwrite(STDERR, __('Utilisateur introuvable : {e}', ['e' => (string) ($args[0] ?? '')]) . PHP_EOL);
                    return 1;
                }
                echo "OK\n";
                return 0;
            }],
            'plugin:list' => [__('Liste les extensions du dossier plugins/'), static function (): int {
                $active = (array) Settings::get('plugins.active', []);
                $found = glob(WL_PLUGINS . '/*/plugin.php') ?: [];
                foreach ($found as $f) {
                    $name = basename(dirname($f));
                    printf("%-12s %s\n", in_array($name, $active, true) ? __('[actif]') : __('[inactif]'), $name);
                }
                if (!$found) {
                    echo __('Aucune extension (plugins/<nom>/plugin.php).') . PHP_EOL;
                }
                return 0;
            }],
            'plugin:enable' => [__('plugin:enable <nom>'), static fn (array $o, array $args): int => self::togglePlugin((string) ($args[0] ?? ''), true)],
            'plugin:disable' => [__('plugin:disable <nom>'), static fn (array $o, array $args): int => self::togglePlugin((string) ($args[0] ?? ''), false)],
        ];
        foreach (['Migrate', 'Mcp', 'Ai'] as $module) {
            $class = 'Wordless\\' . $module . '\\Cli';
            if (class_exists($class) && method_exists($class, 'commands')) {
                $cmds += $class::commands();
            }
        }
        return $cmds;
    }

    public static function run(array $argv): int
    {
        $name = $argv[0] ?? 'help';
        $opts = [];
        $args = [];
        foreach (array_slice($argv, 1) as $a) {
            if (preg_match('/^--([\w-]+)(?:=(.*))?$/s', $a, $m)) {
                $opts[$m[1]] = $m[2] ?? true;
            } else {
                $args[] = $a;
            }
        }
        if ($name !== 'install') {
            if (Config::installed()) {
                try {
                    App::boot(); // la langue du site s'applique aussi à l'aide et aux messages
                } catch (\Throwable $e) {
                    if ($name !== 'help') {
                        throw $e;
                    }
                }
            } else {
                I18n::load(self::shellLanguage($opts));
                if ($name !== 'help') {
                    fwrite(STDERR, __('Wordless n’est pas installé.') . PHP_EOL);
                    return 1;
                }
            }
        }
        $cmds = self::commands();
        if (!isset($cmds[$name])) {
            fwrite(STDERR, __('Commande inconnue : {c}', ['c' => $name]) . PHP_EOL . PHP_EOL);
            self::help();
            return 1;
        }
        try {
            return (int) ($cmds[$name][1]($opts, $args) ?? 0);
        } catch (\Throwable $e) {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function togglePlugin(string $name, bool $on): int
    {
        $name = basename($name);
        if ($name === '' || !is_file(WL_PLUGINS . '/' . $name . '/plugin.php')) {
            fwrite(STDERR, __('Extension introuvable : {f}', ['f' => 'plugins/' . $name . '/plugin.php']) . PHP_EOL);
            return 1;
        }
        $active = array_values(array_filter((array) Settings::get('plugins.active', []), static fn ($p) => $p !== $name));
        if ($on) {
            $active[] = $name;
        }
        Settings::set('plugins.active', $active);
        Cache::purge();
        echo ($on ? __('Extension activée : {n}', ['n' => $name]) : __('Extension désactivée : {n}', ['n' => $name])) . PHP_EOL;
        return 0;
    }

    public static function help(): int
    {
        echo "Wordless " . WL_VERSION . "\n\n";
        foreach (self::commands() as $name => [$desc]) {
            printf("  %-18s %s\n", $name, $desc);
        }
        return 0;
    }

    public static function install(array $o): int
    {
        // Messages dans la langue demandée (--lang), sinon celle du terminal.
        I18n::load(self::shellLanguage($o));
        if (Config::installed()) {
            fwrite(STDERR, __('Wordless est déjà installé.') . PHP_EOL);
            return 1;
        }
        foreach (['url', 'email', 'password'] as $req) {
            if (empty($o[$req])) {
                fwrite(STDERR, __('Option manquante : {o}', ['o' => '--' . $req]) . PHP_EOL);
                return 1;
            }
        }
        if ($p = Auth::passwordProblem((string) $o['password'], (string) $o['email'])) {
            fwrite(STDERR, $p . PHP_EOL);
            return 1;
        }
        I18n::load((string) ($o['lang'] ?? 'fr'));
        Installer::install([
            'url' => rtrim((string) $o['url'], '/'), 'site_name' => (string) ($o['site'] ?? __('Mon site')), 'email' => (string) $o['email'],
            'name' => (string) ($o['name'] ?? strstr((string) $o['email'], '@', true)), 'password' => (string) $o['password'],
            'language' => (string) ($o['lang'] ?? 'fr'), 'timezone' => (string) ($o['timezone'] ?? 'Europe/Paris'),
            'skip_starter' => isset($o['no-starter']),
        ]);
        echo __('Wordless est installé : {url}', ['url' => (string) $o['url']]) . PHP_EOL;
        return 0;
    }

    /** Langue des messages : --lang, sinon celle du terminal (LC_ALL, LC_MESSAGES, LANG), sinon le français. */
    private static function shellLanguage(array $o = []): string
    {
        foreach ([(string) ($o['lang'] ?? ''), (string) getenv('LC_ALL'), (string) getenv('LC_MESSAGES'), (string) getenv('LANG')] as $value) {
            $code = strtolower(substr($value, 0, 2));
            if (isset(I18n::LANGUAGES[$code])) {
                return $code;
            }
        }
        return 'fr';
    }
}
