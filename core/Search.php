<?php
declare(strict_types=1);

namespace Wordless;

/** Recherche interne plein texte (SQLite FTS5, sans accents, classement BM25). */
final class Search
{
    public static function index(?array $c): void
    {
        if (!$c || !Db::hasFts()) {
            return;
        }
        Db::run('DELETE FROM search WHERE content_id = ?', [(int) $c['id']]);
        if (Content::isVisible($c) && empty($c['seo']['noindex'])) {
            Db::run('INSERT INTO search (title, body, content_id) VALUES (?, ?, ?)', [$c['title'], Blocks::text($c['blocks']), (int) $c['id']]);
        }
    }

    public static function remove(int $id): void
    {
        if (Db::hasFts()) {
            Db::run('DELETE FROM search WHERE content_id = ?', [$id]);
        }
    }

    public static function rebuild(): int
    {
        if (!Db::hasFts()) {
            return 0;
        }
        Db::run('DELETE FROM search');
        $n = 0;
        foreach (Db::col("SELECT id FROM content WHERE status = 'published'") as $id) {
            self::index(Content::find((int) $id));
            $n++;
        }
        return $n;
    }

    /** @return array{total: int, items: list<array>} */
    public static function query(string $q, int $limit = 10, int $offset = 0): array
    {
        $q = trim(mb_substr($q, 0, 200));
        if ($q === '') {
            return ['total' => 0, 'items' => []];
        }
        if (Db::hasFts()) {
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_slice(array_filter($tokens, static fn ($t) => mb_strlen($t) > 1), 0, 8);
            if (!$tokens) {
                return ['total' => 0, 'items' => []];
            }
            $match = implode(' ', array_map(static fn ($t) => '"' . str_replace('"', '""', $t) . '"*', $tokens));
            $total = (int) Db::val('SELECT COUNT(*) FROM search WHERE search MATCH ?', [$match]);
            $rows = Db::all("SELECT content_id, snippet(search, 1, char(2), char(3), '…', 24) AS snip FROM search WHERE search MATCH ? ORDER BY bm25(search, 6.0, 1.0) LIMIT ? OFFSET ?", [$match, $limit, $offset]);
            $items = [];
            foreach ($rows as $r) {
                $c = Content::find((int) $r['content_id']);
                if ($c && Content::isVisible($c)) {
                    $c['snippet'] = str_replace(["\x02", "\x03"], ['<mark>', '</mark>'], e((string) $r['snip']));
                    $items[] = $c;
                }
            }
            return ['total' => $total, 'items' => $items];
        }
        $like = '%' . $q . '%';
        $total = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND published_at <= ? AND (title LIKE ? OR blocks LIKE ?)", [time(), $like, $like]);
        $rows = Db::all("SELECT * FROM content WHERE status = 'published' AND published_at <= ? AND (title LIKE ? OR blocks LIKE ?) ORDER BY (title LIKE ?) DESC, published_at DESC LIMIT ? OFFSET ?", [time(), $like, $like, $like, $limit, $offset]);
        return ['total' => $total, 'items' => array_map(static function ($r) {
            $c = Content::decode($r);
            $c['snippet'] = e(Content::summary($c, 180));
            return $c;
        }, $rows)];
    }
}
