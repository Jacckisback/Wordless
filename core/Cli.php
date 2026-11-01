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
                echo Search::rebuild() . " contenus indexés\n";
                return 0;
            }],
            'backup' => ['backup [--full]', static function (array $o): int {
                echo (isset($o['full']) ? Backup::createFull() : Backup::createDb()) . PHP_EOL;
                return 0;
            }],
            'user:password' => ['user:password <email> <nouveau-mot-de-passe>', static function (array $o, array $args): int {
                [$email, $pass] = array_pad($args, 2, '');
                if ($p = Auth::passwordProblem($pass, $email)) {
                    fwrite(STDERR, $p . PHP_EOL);
                    return 1;
                }
                $id = (int) Db::val('SELECT id FROM users WHERE email = ?', [mb_strtolower($email)]);
                if (!$id) {
                    echo "Utilisateur introuvable\n";
                    return 1;
                }
                Db::update('users', ['password' => Auth::hashPassword($pass)], ['id' => $id]);
                Auth::logoutEverywhere($id); // un mot de passe changé ferme toutes les sessions
                echo "OK\n";
                return 0;
            }],
            'user:2fa-reset' => ['user:2fa-reset <email>', static function (array $o, array $args): int {
                $n = Db::update('users', ['totp_enabled' => 0, 'totp_secret' => '', 'recovery_codes' => '[]'], ['email' => mb_strtolower($args[0] ?? '')]);
                echo $n ? "OK\n" : "Utilisateur introuvable\n";
                return $n ? 0 : 1;
            }],
            'plugin:list' => [__('Liste les extensions du dossier plugins/'), static function (): int {
                $active = (array) Settings::get('plugins.active', []);
                $found = glob(WL_PLUGINS . '/*/plugin.php') ?: [];
                foreach ($found as $f) {
                    $name = basename(dirname($f));
                    echo (in_array($name, $active, true) ? '[actif]   ' : '[inactif] ') . $name . PHP_EOL;
                }
                if (!$found) {
                    echo "Aucune extension (plugins/<nom>/plugin.php).\n";
                }
                return 0;
            }],
            'plugin:enable' => ['plugin:enable <nom>', static fn (array $o, array $args): int => self::togglePlugin((string) ($args[0] ?? ''), true)],
            'plugin:disable' => ['plugin:disable <nom>', static fn (array $o, array $args): int => self::togglePlugin((string) ($args[0] ?? ''), false)],
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
        if ($name !== 'install' && $name !== 'help') {
            if (!Config::installed()) {
                fwrite(STDERR, "Wordless n'est pas installé.\n");
                return 1;
            }
            App::boot();
        }
        $cmds = self::commands();
        if (!isset($cmds[$name])) {
            fwrite(STDERR, "Commande inconnue : $name\n");
            return self::help();
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
            fwrite(STDERR, "Extension introuvable : plugins/{$name}/plugin.php\n");
            return 1;
        }
        $active = array_values(array_filter((array) Settings::get('plugins.active', []), static fn ($p) => $p !== $name));
        if ($on) {
            $active[] = $name;
        }
        Settings::set('plugins.active', $active);
        Cache::purge();
        echo ($on ? 'Activée : ' : 'Désactivée : ') . $name . PHP_EOL;
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
        if (Config::installed()) {
            fwrite(STDERR, "Déjà installé.\n");
            return 1;
        }
        foreach (['url', 'email', 'password'] as $req) {
            if (empty($o[$req])) {
                fwrite(STDERR, "Option manquante : --$req\n");
                return 1;
            }
        }
        if ($p = Auth::passwordProblem((string) $o['password'], (string) $o['email'])) {
            fwrite(STDERR, $p . PHP_EOL);
            return 1;
        }
        I18n::load((string) ($o['lang'] ?? 'fr'));
        Installer::install([
            'url' => rtrim((string) $o['url'], '/'), 'site_name' => (string) ($o['site'] ?? 'Mon site'), 'email' => (string) $o['email'],
            'name' => (string) ($o['name'] ?? strstr((string) $o['email'], '@', true)), 'password' => (string) $o['password'],
            'language' => (string) ($o['lang'] ?? 'fr'), 'timezone' => (string) ($o['timezone'] ?? 'Europe/Paris'),
            'skip_starter' => isset($o['no-starter']),
        ]);
        echo "Installé : " . $o['url'] . "\n";
        return 0;
    }
}
