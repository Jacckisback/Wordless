<?php
declare(strict_types=1);

namespace Wordless;

use PDO;
use PDOStatement;

/**
 * Accès SQLite : un seul fichier, aucune configuration, requêtes préparées
 * uniquement. Mode WAL pour des lectures concurrentes sans blocage.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $queries = 0;

    public static function connect(?string $file = null): PDO
    {
        $file ??= Config::dbPath();
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA cache_size = -16000');
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    public static function use(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }

    public static function queryCount(): int
    {
        return self::$queries;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        self::$queries++;
        $st = self::pdo()->prepare($sql);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $st->bindValue($i + 1, $v, self::type($v));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $st->bindValue(is_int($k) ? $k + 1 : ':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $st->execute();
        return $st;
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v), is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public static function col(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $row): int
    {
        self::ident($table);
        $cols = array_keys($row);
        array_map([self::class, 'ident'], $cols);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values(array_map([self::class, 'scalar'], $row)));
        return (int) self::pdo()->lastInsertId();
    }

    public static function upsert(string $table, array $row, array $conflict, array $update = []): void
    {
        self::ident($table);
        $cols = array_keys($row);
        array_map([self::class, 'ident'], $cols);
        $update = $update ?: array_diff($cols, $conflict);
        $sets = implode(', ', array_map(static fn ($c) => self::ident($c) . ' = excluded.' . $c, $update));
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON CONFLICT(' . implode(', ', array_map([self::class, 'ident'], $conflict)) . ') DO ' . ($sets !== '' ? 'UPDATE SET ' . $sets : 'NOTHING');
        self::run($sql, array_values(array_map([self::class, 'scalar'], $row)));
    }

    public static function update(string $table, array $row, array $where): int
    {
        if (!$row) {
            return 0;
        }
        self::ident($table);
        $sets = [];
        foreach (array_keys($row) as $c) {
            $sets[] = self::ident($c) . ' = ?';
        }
        [$w, $wp] = self::where($where);
        return self::run('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $w, [...array_values(array_map([self::class, 'scalar'], $row)), ...$wp])->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        self::ident($table);
        [$w, $wp] = self::where($where);
        return self::run('DELETE FROM ' . $table . ' WHERE ' . $w, $wp)->rowCount();
    }

    /** Exécute $fn dans une transaction (imbrications tolérées). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** « ?, ?, ? » pour une clause IN. */
    public static function in(array $values): string
    {
        return $values ? implode(', ', array_fill(0, count($values), '?')) : 'NULL';
    }

    private static function where(array $where): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $c => $v) {
            if ($v === null) {
                $parts[] = self::ident($c) . ' IS NULL';
            } else {
                $parts[] = self::ident($c) . ' = ?';
                $params[] = self::scalar($v);
            }
        }
        if (!$parts) {
            throw new \InvalidArgumentException('Clause WHERE vide refusée');
        }
        return [implode(' AND ', $parts), $params];
    }

    private static function scalar(mixed $v): mixed
    {
        if (is_array($v) || is_object($v)) {
            return json_enc($v);
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $v;
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide : ' . $name);
        }
        return $name;
    }

    public static function hasFts(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool) self::val("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'search'");
            } catch (\Throwable) {
                $has = false;
            }
        }
        return $has;
    }
}
