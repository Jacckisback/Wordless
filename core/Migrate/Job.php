<?php
declare(strict_types=1);

namespace Wordless\Migrate;

/**
 * Tâche de migration WordPress persistée dans data/import/<id>.json.
 *
 * Le fichier contient l'état public (renvoyé à l'interface) et un état privé
 * (curseurs, informations sur le site source, mot de passe d'application
 * CHIFFRÉ). Chaque appel de step() recharge la tâche, avance un peu, puis
 * l'enregistre de façon atomique : la migration survit à autant de requêtes
 * que nécessaire. Un verrou (flock) empêche deux requêtes simultanées de
 * traiter la même tâche.
 */
final class Job
{
    public const PHASES = ['terms', 'users', 'media', 'content', 'comments', 'menus', 'finalize'];

    /** Nombre maximal d'éléments conservés dans chaque liste du rapport. */
    private const LIST_MAX = 300;

    /** @var array<string, mixed> Données complètes (publiques + privées). */
    public array $d;

    private function __construct(array $data)
    {
        $this->d = $data;
    }

    public static function dir(): string
    {
        return WL_DATA . '/import';
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
    }

    public static function file(string $id): string
    {
        return self::dir() . '/' . $id . '.json';
    }

    /** Crée une nouvelle tâche (état initial, toutes les phases « en attente »). */
    public static function create(array $publicSource, array $options): self
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        do {
            $id = bin2hex(random_bytes(8));
        } while (is_file(self::file($id)));
        $phases = [];
        foreach (self::PHASES as $p) {
            $phases[$p] = ['label' => self::label($p), 'done' => 0, 'total' => 0, 'status' => 'pending'];
        }
        $job = new self([
            'id' => $id,
            'source' => $publicSource,
            'options' => $options,
            'phase' => 'terms',
            'phases' => $phases,
            'log' => [],
            'finished' => false,
            'error' => null,
            'cancelled' => false,
            'report' => self::emptyReport(),
            'created_at' => time(),
            'updated_at' => time(),
            // --- privé : jamais renvoyé par publicState() ---
            'secret' => '',
            'state' => [],
        ]);
        $job->save();
        return $job;
    }

    public static function load(string $id): ?self
    {
        if (!self::validId($id) || !is_file(self::file($id))) {
            return null;
        }
        $data = json_decode((string) file_get_contents(self::file($id)), true);
        return is_array($data) && ($data['id'] ?? '') === $id ? new self($data) : null;
    }

    /** Tâche la plus récente (null s'il n'y en a pas) : sert à reprendre une migration interrompue. */
    public static function latest(): ?self
    {
        $latest = null;
        $rank = static fn (self $j): array => [(int) $j->d['updated_at'], $j->d['finished'] ? 0 : 1, (int) $j->d['created_at']];
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $job = self::load(basename($file, '.json'));
            // À la même seconde, une tâche inachevée l'emporte : c'est elle qu'il faut pouvoir reprendre.
            if ($job && (!$latest || $rank($job) > $rank($latest))) {
                $latest = $job;
            }
        }
        return $latest;
    }

    /** Écriture atomique (fichier temporaire puis renommage). */
    public function save(): void
    {
        $this->d['updated_at'] = time();
        $file = self::file($this->d['id']);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($this->d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException(__('Impossible d’enregistrer l’état de la migration (dossier data/import).'));
        }
        @chmod($tmp, 0640);
        rename($tmp, $file);
    }

    /**
     * Verrou exclusif non bloquant (flock) : null si une autre requête traite
     * déjà la tâche. Libéré automatiquement si le processus s'arrête.
     * @return resource|null
     */
    public static function acquire(string $id)
    {
        if ((!self::validId($id) && !preg_match('/^src-[a-f0-9]{40}$/', $id)) || !is_dir(self::dir())) {
            return null;
        }
        $h = @fopen(self::dir() . '/' . $id . '.lock', 'c');
        if (!$h) {
            return null;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /** @param resource $handle */
    public static function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Demande d'annulation (lue par l'étape en cours, même dans une autre requête). */
    public static function requestCancel(string $id): void
    {
        if (self::validId($id)) {
            @touch(self::dir() . '/' . $id . '.cancel');
        }
    }

    public static function cancelRequested(string $id): bool
    {
        return self::validId($id) && is_file(self::dir() . '/' . $id . '.cancel');
    }

    /** Fichiers de verrou et d'annulation d'une tâche terminée. */
    public static function removeMarkers(string $id): void
    {
        if (self::validId($id)) {
            @unlink(self::dir() . '/' . $id . '.lock');
            @unlink(self::dir() . '/' . $id . '.cancel');
        }
    }

    /** Dossier de travail (fichiers intermédiaires, cache des réponses de l'API). */
    public function workDir(): string
    {
        $dir = self::dir() . '/' . $this->d['id'];
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Supprime les fichiers intermédiaires (le rapport reste dans le fichier JSON). */
    public function cleanup(): void
    {
        $dir = self::dir() . '/' . $this->d['id'];
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // État public
    // ------------------------------------------------------------------

    public static function label(string $phase): string
    {
        return match ($phase) {
            'terms' => __('Catégories et étiquettes'),
            'users' => __('Auteurs'),
            'media' => __('Images et fichiers'),
            'content' => __('Pages et articles'),
            'comments' => __('Commentaires'),
            'menus' => __('Menus'),
            default => __('Finalisation'),
        };
    }

    /** État renvoyé à l'interface : sans mot de passe ni données internes. */
    public function publicState(): array
    {
        $phases = $this->d['phases'];
        foreach ($phases as $k => $p) {
            $phases[$k]['label'] = self::label((string) $k);
            $phases[$k]['total'] = max((int) $p['total'], (int) $p['done']);
        }
        $source = $this->d['source'];
        unset($source['password']);
        $report = $this->d['report'];
        $report['warnings'] = array_values((array) ($report['warnings'] ?? []));
        return [
            'id' => $this->d['id'],
            'source' => $source,
            'options' => $this->d['options'],
            'phase' => $this->d['phase'],
            'phases' => $phases,
            'log' => array_values($this->d['log']),
            'finished' => (bool) $this->d['finished'],
            'error' => $this->d['error'],
            'cancelled' => (bool) ($this->d['cancelled'] ?? false),
            'report' => $report,
            'created_at' => (int) $this->d['created_at'],
            'updated_at' => (int) $this->d['updated_at'],
        ];
    }

    /** Ajoute un message lisible au journal (50 derniers conservés). */
    public function log(string $message): void
    {
        $this->d['log'][] = $message;
        if (count($this->d['log']) > 50) {
            $this->d['log'] = array_slice($this->d['log'], -50);
        }
    }

    /** @return array<string, mixed> */
    public function &phase(string $key): array
    {
        return $this->d['phases'][$key];
    }

    /** @return array<string, mixed> */
    public function &state(): array
    {
        return $this->d['state'];
    }

    // ------------------------------------------------------------------
    // Rapport
    // ------------------------------------------------------------------

    public static function emptyReport(): array
    {
        $c = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'reasons' => []];
        return [
            'counts' => [
                'categories' => $c, 'tags' => $c, 'users' => $c, 'media' => $c, 'pages' => $c, 'posts' => $c,
                'other' => $c, 'comments' => $c, 'menus' => $c, 'redirects' => $c,
            ],
            'media' => ['downloaded' => 0, 'failed' => []],
            'warnings' => [],
            'review' => [],
            'skipped' => [],
            'urls' => ['total' => 0, 'identical' => 0, 'redirected' => 0, 'identical_list' => [], 'redirected_list' => [], 'conflicts' => []],
            'notices' => [],
            'next_steps' => [],
        ];
    }

    /** Compteur du rapport : $what = imported | updated | skipped (avec raison facultative). */
    public function count(string $type, string $what, int $n = 1, ?string $reason = null): void
    {
        $r = &$this->d['report']['counts'];
        if (!isset($r[$type])) {
            $r[$type] = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'reasons' => []];
        }
        $r[$type][$what] = (int) ($r[$type][$what] ?? 0) + $n;
        if ($reason !== null && $reason !== '') {
            $r[$type]['reasons'][$reason] = (int) ($r[$type]['reasons'][$reason] ?? 0) + $n;
        }
    }

    /** Élément non importé, avec la raison (liste limitée). */
    public function skipped(string $type, int|string $wpId, string $title, string $reason): void
    {
        $list = &$this->d['report']['skipped'];
        if (count($list) < self::LIST_MAX) {
            $list[] = ['type' => $type, 'wp_id' => $wpId, 'title' => $title, 'reason' => $reason];
        }
    }

    /** Avertissement regroupé par code (codes courts, blocs inconnus, types ignorés…). */
    public function warn(string $code, string $message, string $example = ''): void
    {
        $key = $code . '|' . $message;
        $list = &$this->d['report']['warnings'];
        if (!isset($list[$key])) {
            if (count($list) >= self::LIST_MAX) {
                return;
            }
            $list[$key] = ['code' => $code, 'message' => $message, 'count' => 0, 'examples' => []];
        }
        $list[$key]['count']++;
        if ($example !== '' && count($list[$key]['examples']) < 10 && !in_array($example, $list[$key]['examples'], true)) {
            $list[$key]['examples'][] = $example;
        }
    }

    /** Contenu importé « à revoir » (code court, formulaire, code HTML nettoyé…). */
    public function review(int $contentId, string $title, string $path, array $issues): void
    {
        $list = &$this->d['report']['review'];
        $issues = array_values(array_unique(array_filter($issues)));
        if (!$issues) {
            return;
        }
        foreach ($list as &$entry) {
            if ((int) $entry['id'] === $contentId) {
                $entry['issues'] = $issues;
                return;
            }
        }
        unset($entry);
        if (count($list) < self::LIST_MAX) {
            $list[] = ['id' => $contentId, 'title' => $title, 'path' => $path, 'issues' => $issues];
        }
    }

    public function mediaFailed(string $url, string $error): void
    {
        $list = &$this->d['report']['media']['failed'];
        foreach ($list as $f) {
            if ($f['url'] === $url) {
                return;
            }
        }
        if (count($list) < self::LIST_MAX) {
            $list[] = ['url' => $url, 'error' => $error];
        }
    }

    public function notice(string $message): void
    {
        if (!in_array($message, $this->d['report']['notices'], true)) {
            $this->d['report']['notices'][] = $message;
        }
    }
}
