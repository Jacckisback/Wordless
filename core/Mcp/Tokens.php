<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Crypto;
use Wordless\Db;

/**
 * Jetons du connecteur IA, table `tokens`. Seule l'empreinte SHA-256 du jeton
 * est stockée (colonne id) : une fuite de la base ne donne aucun accès.
 * Types : oauth_code, oauth_access, oauth_refresh, api_key.
 * Les jetons OAuth d'une même autorisation partagent un identifiant « grant »
 * (data.grant) pour pouvoir tout révoquer d'un coup.
 */
final class Tokens
{
    public const CODE_TTL = 600;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 60 * 86400;
    public const API_KEY_TTL = 10 * 365 * 86400;

    /** Crée un jeton et renvoie sa valeur en clair (elle n'est plus jamais lisible ensuite). */
    public static function issue(string $kind, string $prefix, ?int $userId, ?string $clientId, int $ttl, array $data = [], string $label = ''): string
    {
        $plain = $prefix . Crypto::token(32);
        $now = time();
        Db::insert('tokens', [
            'id' => Crypto::hash($plain),
            'kind' => $kind,
            'user_id' => $userId,
            'client_id' => $clientId,
            'label' => $label,
            'data' => json_enc((object) $data),
            'expires_at' => $now + $ttl,
            'created_at' => $now,
        ]);
        return $plain;
    }

    /**
     * Retrouve un jeton (expiré ou non) par sa valeur en clair.
     * @param list<string> $kinds
     * @return array|null ligne avec data décodé
     */
    public static function find(string $plain, array $kinds): ?array
    {
        if ($plain === '' || strlen($plain) > 256 || !preg_match('#^[A-Za-z0-9._~+/=-]+$#', $plain) || !$kinds) {
            return null;
        }
        $row = Db::one('SELECT * FROM tokens WHERE id = ? AND kind IN (' . Db::in($kinds) . ')', [Crypto::hash($plain), ...$kinds]);
        if (!$row) {
            return null;
        }
        $row['data'] = (array) json_dec((string) $row['data'], []);
        return $row;
    }

    public static function expired(array $row): bool
    {
        return (int) $row['expires_at'] <= time();
    }

    /** Date de dernière utilisation, écrite au plus une fois par minute. */
    public static function touch(array $row): void
    {
        if (time() - (int) ($row['last_used_at'] ?? 0) >= 60) {
            Db::update('tokens', ['last_used_at' => time()], ['id' => $row['id']]);
        }
    }

    /** Fusionne des valeurs dans data (ex. nom du client MCP qui utilise une clé). */
    public static function mergeData(array $row, array $values): void
    {
        Db::update('tokens', ['data' => json_enc((object) array_merge((array) $row['data'], $values))], ['id' => $row['id']]);
    }

    /** Révoque tout ce qui découle d'une autorisation : code, jetons d'accès et de rafraîchissement. */
    public static function revokeGrant(string $clientId, string $grant): int
    {
        if ($grant === '') {
            return 0;
        }
        return Db::run(
            "DELETE FROM tokens WHERE client_id = ? AND kind IN ('oauth_code', 'oauth_access', 'oauth_refresh') AND json_extract(data, '$.grant') = ?",
            [$clientId, $grant]
        )->rowCount();
    }
}
