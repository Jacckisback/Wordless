<?php
declare(strict_types=1);

namespace Wordless\Mcp;

/**
 * Validation des arguments d'un outil selon son inputSchema (sous-ensemble de
 * JSON Schema : type, enum, longueurs, bornes, motif, format, objets, listes).
 * Les objets JSON deviennent des tableaux PHP associatifs. Les nombres et
 * booléens envoyés sous forme de texte (« 12 », « true ») sont acceptés, et une
 * propriété facultative à null est considérée comme absente.
 * Les messages d'erreur sont en anglais : ils sont lus par le modèle.
 */
final class Validator
{
    /** @return array{0: mixed, 1: list<string>} [valeur nettoyée, erreurs] */
    public static function validate(mixed $value, array $schema, string $path = 'arguments'): array
    {
        $errors = [];
        $clean = self::check($value, $schema, $path, $errors);
        return [$clean, $errors];
    }

    private static function check(mixed $v, array $s, string $path, array &$errors): mixed
    {
        $types = isset($s['type']) ? (array) $s['type'] : [];
        $type = null;
        if ($types) {
            foreach ($types as $t) {
                [$ok, $coerced] = self::matchType($v, (string) $t);
                if ($ok) {
                    $type = (string) $t;
                    $v = $coerced;
                    break;
                }
            }
            if ($type === null) {
                $errors[] = $path . ': expected ' . implode(' or ', $types) . ', got ' . self::describe($v);
                return null;
            }
        } else {
            $type = self::typeOf($v);
        }
        if (isset($s['enum']) && !in_array($v, (array) $s['enum'], true)) {
            $errors[] = $path . ': must be one of ' . implode(', ', array_map(static fn ($x) => (string) json_encode($x, JSON_UNESCAPED_UNICODE), (array) $s['enum']));
            return null;
        }
        return match ($type) {
            'string' => self::checkString($v, $s, $path, $errors),
            'integer', 'number' => self::checkNumber($v, $s, $path, $errors),
            'array' => self::checkArray($v, $s, $path, $errors),
            'object' => self::checkObject($v, $s, $path, $errors),
            default => $v,
        };
    }

    /** @return array{0: bool, 1: mixed} */
    private static function matchType(mixed $v, string $t): array
    {
        switch ($t) {
            case 'string':
                if (is_string($v)) {
                    return [true, $v];
                }
                return is_int($v) || is_float($v) ? [true, (string) $v] : [false, null];
            case 'integer':
                if (is_int($v)) {
                    return [true, $v];
                }
                if (is_float($v) && floor($v) === $v && abs($v) < 2 ** 53) {
                    return [true, (int) $v];
                }
                return is_string($v) && preg_match('/^\s*-?\d{1,15}\s*$/', $v) ? [true, (int) trim($v)] : [false, null];
            case 'number':
                if (is_int($v) || is_float($v)) {
                    return [true, $v];
                }
                return is_string($v) && is_numeric(trim($v)) ? [true, trim($v) + 0] : [false, null];
            case 'boolean':
                if (is_bool($v)) {
                    return [true, $v];
                }
                return is_string($v) && in_array(strtolower(trim($v)), ['true', 'false'], true) ? [true, strtolower(trim($v)) === 'true'] : [false, null];
            case 'null':
                return [$v === null, null];
            case 'array':
                return is_array($v) && array_is_list($v) ? [true, $v] : [false, null];
            case 'object':
                if ($v instanceof \stdClass) {
                    return [true, $v];
                }
                return is_array($v) && ($v === [] || !array_is_list($v)) ? [true, $v] : [false, null];
        }
        return [false, null];
    }

    private static function checkString(string $v, array $s, string $path, array &$errors): ?string
    {
        $len = mb_strlen($v);
        if (isset($s['minLength']) && $len < (int) $s['minLength']) {
            $errors[] = $path . ($s['minLength'] === 1 ? ': must not be empty' : ': must be at least ' . $s['minLength'] . ' characters');
            return null;
        }
        if (isset($s['maxLength']) && $len > (int) $s['maxLength']) {
            $errors[] = $path . ': must be at most ' . $s['maxLength'] . ' characters (got ' . $len . ')';
            return null;
        }
        if (isset($s['pattern']) && !preg_match('/' . str_replace('/', '\/', (string) $s['pattern']) . '/u', $v)) {
            $errors[] = $path . ': invalid format (expected pattern ' . $s['pattern'] . ')';
            return null;
        }
        $format = (string) ($s['format'] ?? '');
        // Les caractères accentués (« /images/vélo.jpg ») sont acceptés : ils seront encodés avant usage.
        if ($format === 'uri' && (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#iu', $v) || (string) parse_url($v, PHP_URL_HOST) === '')) {
            $errors[] = $path . ': must be an absolute http(s) URL';
            return null;
        }
        if ($format === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $path . ': must be an e-mail address';
            return null;
        }
        return $v;
    }

    private static function checkNumber(int|float $v, array $s, string $path, array &$errors): int|float|null
    {
        if (isset($s['minimum']) && $v < $s['minimum']) {
            $errors[] = $path . ': must be >= ' . $s['minimum'];
            return null;
        }
        if (isset($s['maximum']) && $v > $s['maximum']) {
            $errors[] = $path . ': must be <= ' . $s['maximum'];
            return null;
        }
        return $v;
    }

    private static function checkArray(array $v, array $s, string $path, array &$errors): ?array
    {
        $n = count($v);
        if (isset($s['minItems']) && $n < (int) $s['minItems']) {
            $errors[] = $path . ': must contain at least ' . $s['minItems'] . ' item(s)';
            return null;
        }
        if (isset($s['maxItems']) && $n > (int) $s['maxItems']) {
            $errors[] = $path . ': must contain at most ' . $s['maxItems'] . ' items (got ' . $n . ')';
            return null;
        }
        $out = [];
        foreach ($v as $i => $item) {
            $out[] = isset($s['items']) && is_array($s['items']) ? self::check($item, $s['items'], $path . '[' . $i . ']', $errors) : self::plain($item);
        }
        return $out;
    }

    private static function checkObject(\stdClass|array $v, array $s, string $path, array &$errors): array
    {
        $obj = $v instanceof \stdClass ? get_object_vars($v) : $v;
        $props = (array) ($s['properties'] ?? []);
        $required = (array) ($s['required'] ?? []);
        $additional = $s['additionalProperties'] ?? true;
        $out = [];
        foreach ($required as $req) {
            if (!array_key_exists($req, $obj) || $obj[$req] === null) {
                $errors[] = $path . '.' . $req . ': is required';
            }
        }
        foreach ($obj as $k => $val) {
            $k = (string) $k;
            if (isset($props[$k])) {
                $allowsNull = in_array('null', (array) ($props[$k]['type'] ?? []), true);
                if ($val === null && !$allowsNull) {
                    continue; // facultatif et null : ignoré (déjà signalé s'il est obligatoire)
                }
                $out[$k] = self::check($val, (array) $props[$k], $path . '.' . $k, $errors);
            } elseif ($additional === false) {
                $errors[] = $path . '.' . $k . ': unknown property' . ($props ? ' (allowed: ' . implode(', ', array_keys($props)) . ')' : '');
            } elseif (is_array($additional)) {
                $out[$k] = self::check($val, $additional, $path . '.' . $k, $errors);
            } else {
                $out[$k] = self::plain($val);
            }
        }
        if (isset($s['maxProperties']) && count($obj) > (int) $s['maxProperties']) {
            $errors[] = $path . ': too many properties (max ' . $s['maxProperties'] . ')';
        }
        if (isset($s['minProperties']) && count($obj) < (int) $s['minProperties']) {
            $errors[] = $path . ': provide at least ' . $s['minProperties'] . ' propert' . ((int) $s['minProperties'] > 1 ? 'ies' : 'y');
        }
        return $out;
    }

    /** Objets JSON (stdClass) => tableaux PHP, récursivement. */
    public static function plain(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $v[$k] = self::plain($item);
            }
        }
        return $v;
    }

    private static function typeOf(mixed $v): string
    {
        return match (true) {
            is_string($v) => 'string',
            is_int($v) => 'integer',
            is_float($v) => 'number',
            is_bool($v) => 'boolean',
            $v === null => 'null',
            $v instanceof \stdClass => 'object',
            is_array($v) => array_is_list($v) && $v !== [] ? 'array' : 'object',
            default => 'unknown',
        };
    }

    private static function describe(mixed $v): string
    {
        $t = self::typeOf($v);
        if (in_array($t, ['string', 'integer', 'number', 'boolean'], true)) {
            $s = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            return $t . ' ' . (strlen($s) > 40 ? substr($s, 0, 37) . '…' : $s);
        }
        return $t;
    }

    /**
     * Schéma prêt à être publié dans tools/list : les « properties » vides
     * doivent sortir en objet JSON ({}), pas en liste ([]).
     */
    public static function publish(array $schema): array
    {
        foreach ($schema as $k => $v) {
            if (($k === 'properties' || $k === 'additionalProperties') && $v === []) {
                $schema[$k] = new \stdClass();
            } elseif ($k === 'properties' && is_array($v)) {
                $schema[$k] = array_map(static fn ($p) => is_array($p) ? self::publish($p) : $p, $v);
            } elseif (in_array($k, ['items', 'additionalProperties'], true) && is_array($v)) {
                $schema[$k] = self::publish($v);
            }
        }
        return $schema;
    }
}
