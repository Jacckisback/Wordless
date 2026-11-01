<?php
declare(strict_types=1);

namespace Wordless;

/** Catégories (hiérarchiques) et étiquettes. */
final class Terms
{
    public const TAXONOMIES = ['category', 'tag'];

    public static function find(?int $id): ?array
    {
        return $id ? Db::one('SELECT * FROM terms WHERE id = ?', [$id]) : null;
    }

    public static function bySlug(string $taxonomy, string $slug): ?array
    {
        return Db::one('SELECT * FROM terms WHERE taxonomy = ? AND slug = ?', [$taxonomy, $slug]);
    }

    public static function all(string $taxonomy): array
    {
        return Db::all('SELECT t.*, (SELECT COUNT(*) FROM content_terms ct JOIN content c ON c.id = ct.content_id WHERE ct.term_id = t.id AND c.status = \'published\') AS count FROM terms t WHERE taxonomy = ? ORDER BY name COLLATE NOCASE', [$taxonomy]);
    }

    /** Trouve ou crée un terme par son nom. */
    public static function ensure(string $taxonomy, string $name, ?string $slug = null, ?int $parentId = null, string $description = ''): int
    {
        $slug = slugify($slug ?? $name) ?: 'terme';
        $existing = self::bySlug($taxonomy, $slug);
        if ($existing) {
            return (int) $existing['id'];
        }
        return Db::insert('terms', [
            'taxonomy' => in_array($taxonomy, self::TAXONOMIES, true) ? $taxonomy : 'tag',
            'name' => mb_substr(trim($name), 0, 120),
            'slug' => $slug,
            'parent_id' => $parentId,
            'description' => $description,
        ]);
    }

    public static function save(array $in, ?int $id = null): int
    {
        $row = [
            'name' => mb_substr(trim(strip_tags((string) ($in['name'] ?? ''))), 0, 120),
            'slug' => slugify((string) (($in['slug'] ?? '') !== '' ? $in['slug'] : ($in['name'] ?? ''))),
            'description' => trim(strip_tags((string) ($in['description'] ?? ''))),
            'parent_id' => !empty($in['parent_id']) && (int) $in['parent_id'] !== $id ? (int) $in['parent_id'] : null,
        ];
        if ($row['name'] === '' || $row['slug'] === '') {
            throw new \RuntimeException(__('Le nom est obligatoire.'));
        }
        if ($id) {
            $old = self::find($id);
            Db::update('terms', $row, ['id' => $id]);
            if ($old && $old['slug'] !== $row['slug']) {
                $base = $old['taxonomy'] === 'category' ? '/category/' : '/tag/';
                Redirects::add($base . $old['slug'], $base . $row['slug'], 301, true);
            }
        } else {
            $row['taxonomy'] = in_array($in['taxonomy'] ?? '', self::TAXONOMIES, true) ? $in['taxonomy'] : 'category';
            $id = Db::insert('terms', $row);
        }
        Cache::purge();
        return $id;
    }

    public static function delete(int $id): void
    {
        Db::delete('terms', ['id' => $id]);
        Db::run('UPDATE terms SET parent_id = NULL WHERE parent_id = ?', [$id]);
        Cache::purge();
    }

    /** Remplace les termes d'une taxonomie pour un contenu. */
    public static function assign(int $contentId, string $taxonomy, array $termIds): void
    {
        Db::run('DELETE FROM content_terms WHERE content_id = ? AND term_id IN (SELECT id FROM terms WHERE taxonomy = ?)', [$contentId, $taxonomy]);
        foreach (array_unique(array_map('intval', $termIds)) as $tid) {
            if ($tid > 0 && Db::val('SELECT 1 FROM terms WHERE id = ? AND taxonomy = ?', [$tid, $taxonomy])) {
                Db::run('INSERT OR IGNORE INTO content_terms (content_id, term_id) VALUES (?, ?)', [$contentId, $tid]);
            }
        }
    }

    public static function forContent(int $contentId, ?string $taxonomy = null): array
    {
        $sql = 'SELECT t.* FROM terms t JOIN content_terms ct ON ct.term_id = t.id WHERE ct.content_id = ?';
        $p = [$contentId];
        if ($taxonomy) {
            $sql .= ' AND t.taxonomy = ?';
            $p[] = $taxonomy;
        }
        return Db::all($sql . ' ORDER BY t.name COLLATE NOCASE', $p);
    }

    public static function href(array $t): string
    {
        return href(($t['taxonomy'] === 'category' ? '/category/' : '/tag/') . $t['slug']);
    }

    public static function url(array $t): string
    {
        return url(($t['taxonomy'] === 'category' ? '/category/' : '/tag/') . $t['slug']);
    }
}
