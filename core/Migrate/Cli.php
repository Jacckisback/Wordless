<?php
declare(strict_types=1);

namespace Wordless\Migrate;

/**
 * Commandes de migration WordPress :
 *   php bin/wordless import:wxr export.xml [--no-media] [--no-comments] …
 *   php bin/wordless import:wp https://site.tld --user=admin --password="xxxx xxxx xxxx xxxx" …
 * Options communes : --no-media, --no-comments, --no-users, --no-menus, --no-settings,
 * --keep-starter (garde le contenu de démonstration), --seo-fallback, --skip-other-types,
 * --budget=8 (secondes par étape), --quiet.
 */
final class Cli
{
    public static function commands(): array
    {
        return [
            'import:wxr' => [
                'import:wxr <fichier.xml> [--no-media] [--no-comments] [--no-users] [--no-menus] [--keep-starter]',
                static fn (array $opts, array $args): int => self::run(['type' => 'wxr', 'file' => (string) ($args[0] ?? ($opts['file'] ?? ''))], $opts),
            ],
            'import:wp' => [
                'import:wp <https://site.tld> [--user=… --password=…] [--no-media] [--no-comments] [--seo-fallback]',
                static fn (array $opts, array $args): int => self::run([
                    'type' => 'rest', 'url' => (string) ($args[0] ?? ($opts['url'] ?? '')),
                    'user' => (string) ($opts['user'] ?? ''), 'password' => (string) ($opts['password'] ?? (getenv('WL_WP_PASSWORD') ?: '')),
                ], $opts),
            ],
        ];
    }

    private static function run(array $source, array $opts): int
    {
        if ($source['type'] === 'wxr' && $source['file'] !== '' && !str_starts_with($source['file'], '/')) {
            $source['file'] = (string) (realpath($source['file']) ?: $source['file']);
        }
        $options = [
            'media' => !isset($opts['no-media']),
            'comments' => !isset($opts['no-comments']),
            'users' => !isset($opts['no-users']),
            'menus' => !isset($opts['no-menus']),
            'settings' => !isset($opts['no-settings']),
            'replace_starter' => !isset($opts['keep-starter']),
            'seo_fallback' => isset($opts['seo-fallback']),
            'other_types' => isset($opts['skip-other-types']) ? 'skip' : 'pages',
        ];
        $quiet = isset($opts['quiet']);
        $budget = max(1, (int) ($opts['budget'] ?? 8));

        $state = Importer::create($source, $options);
        if (!$quiet) {
            foreach ($state['log'] as $message) {
                echo '  ' . $message . PHP_EOL;
            }
        }
        if ($state['error']) {
            fwrite(STDERR, __('Erreur : {m}', ['m' => $state['error']]) . PHP_EOL);
            return 1;
        }
        $lastLine = '';
        while (!$state['finished']) {
            $before = $state['log'];
            $state = Importer::step($state['id'], $budget);
            if ($quiet) {
                continue;
            }
            // Le journal garde les 50 derniers messages : on affiche ceux qui n'y étaient pas.
            foreach (self::newMessages($before, $state['log']) as $message) {
                echo '  ' . $message . PHP_EOL;
            }
            $phase = $state['phases'][$state['phase']] ?? null;
            if ($phase) {
                $line = sprintf('[%s] %s : %d/%d', $state['phase'], $phase['label'], $phase['done'], $phase['total']);
                if ($line !== $lastLine) {
                    echo $line . PHP_EOL;
                    $lastLine = $line;
                }
            }
        }
        self::report($state);
        return $state['error'] && !$state['cancelled'] ? 1 : 0;
    }

    /** Messages ajoutés au journal entre deux états (fenêtre glissante de 50 messages). */
    private static function newMessages(array $before, array $after): array
    {
        $n = count($before);
        for ($overlap = min($n, count($after)); $overlap > 0; $overlap--) {
            if (array_slice($before, $n - $overlap) === array_slice($after, 0, $overlap)) {
                return array_slice($after, $overlap);
            }
        }
        return $after;
    }

    private static function report(array $state): void
    {
        $r = $state['report'];
        echo PHP_EOL . '=== ' . __('Rapport de migration') . ' ===' . PHP_EOL;
        if ($state['error']) {
            echo __('Erreur : {m}', ['m' => $state['error']]) . PHP_EOL;
        }
        $labels = [
            'categories' => __('Catégories'), 'tags' => __('Étiquettes'), 'users' => __('Auteurs'), 'media' => __('Médias'),
            'pages' => __('Pages'), 'posts' => __('Articles'), 'other' => __('Autres contenus'), 'comments' => __('Commentaires'),
            'menus' => __('Menus'), 'redirects' => __('Redirections 301'),
        ];
        foreach ($labels as $k => $label) {
            $c = $r['counts'][$k] ?? null;
            if (!$c || ($c['imported'] + $c['updated'] + $c['skipped']) === 0) {
                continue;
            }
            $line = sprintf('  %-18s %s', $label, __('{i} importés, {u} mis à jour, {s} ignorés', ['i' => $c['imported'], 'u' => $c['updated'], 's' => $c['skipped']]));
            if (!empty($c['reasons'])) {
                $line .= ' (' . implode(', ', array_map(static fn ($reason, $n) => $reason . ' : ' . $n, array_keys($c['reasons']), $c['reasons'])) . ')';
            }
            echo $line . PHP_EOL;
        }
        $u = $r['urls'] ?? [];
        if (!empty($u['total'])) {
            echo '  ' . __('Adresses : {t} publiques, {i} identiques, {r} redirigées (301).', ['t' => $u['total'], 'i' => $u['identical'], 'r' => $u['redirected']]) . PHP_EOL;
            foreach (array_slice($u['redirected_list'] ?? [], 0, 20) as $x) {
                echo '    ' . $x['from'] . ' -> ' . $x['to'] . PHP_EOL;
            }
            foreach ($u['conflicts'] ?? [] as $x) {
                echo '    ! ' . $x['path'] . ' : ' . $x['reason'] . PHP_EOL;
            }
        }
        if (!empty($r['media']['failed'])) {
            echo '  ' . __('Fichiers non téléchargés :') . PHP_EOL;
            foreach (array_slice($r['media']['failed'], 0, 30) as $f) {
                echo '    - ' . $f['url'] . ' (' . $f['error'] . ')' . PHP_EOL;
            }
        }
        if (!empty($r['warnings'])) {
            echo '  ' . __('Avertissements :') . PHP_EOL;
            foreach ($r['warnings'] as $w) {
                echo '    - ' . $w['message'] . ' (' . $w['count'] . ')' . ($w['examples'] ? ' : ' . implode(', ', $w['examples']) : '') . PHP_EOL;
            }
        }
        if (!empty($r['review'])) {
            echo '  ' . __('À revoir :') . PHP_EOL;
            foreach ($r['review'] as $x) {
                echo '    - ' . $x['title'] . ' (' . $x['path'] . ')' . PHP_EOL;
                foreach ($x['issues'] as $issue) {
                    echo '        · ' . $issue . PHP_EOL;
                }
            }
        }
        foreach ($r['notices'] ?? [] as $n) {
            echo '  * ' . $n . PHP_EOL;
        }
        if (!empty($r['next_steps'])) {
            echo '  ' . __('Prochaines étapes :') . PHP_EOL;
            foreach ($r['next_steps'] as $i => $s) {
                echo '    ' . ($i + 1) . '. ' . $s . PHP_EOL;
            }
        }
    }
}
