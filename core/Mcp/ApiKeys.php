<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Activity;
use Wordless\Auth;
use Wordless\Db;

/**
 * Clés API du connecteur IA, pour les clients sans OAuth : Claude Code, Claude
 * Desktop (configuration manuelle), l'API Anthropic ou OpenAI, Cursor…
 * La clé (préfixe « wl_ ») n'est affichée qu'une fois ; seule son empreinte
 * SHA-256 est conservée (table tokens, kind « api_key », validité 10 ans).
 */
final class ApiKeys
{
    public const PREFIX = 'wl_';

    /** Crée une clé et renvoie sa valeur en clair (à montrer une seule fois). */
    public static function create(int $userId, string $label): string
    {
        if (!Db::val("SELECT 1 FROM users WHERE id = ? AND status = 'active'", [$userId])) {
            throw new \RuntimeException(__('Utilisateur introuvable ou désactivé.'));
        }
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($label)) ?? ''), 0, 80);
        if ($label === '') {
            $label = __('Clé API');
        }
        $key = Tokens::issue('api_key', self::PREFIX, $userId, null, Tokens::API_KEY_TTL, [], $label);
        Activity::log('mcp', 'api_key', null, __('Clé API créée : {l}', ['l' => $label]), Auth::id() ?? $userId);
        return $key;
    }

    /**
     * Clés d'un utilisateur, sans jamais la valeur de la clé.
     * @return list<array{id: string, label: string, created_at: int, last_used_at: ?int, expires_at: int, client: string}>
     */
    public static function list(int $userId): array
    {
        $rows = Db::all("SELECT id, label, data, created_at, last_used_at, expires_at FROM tokens WHERE kind = 'api_key' AND user_id = ? ORDER BY created_at DESC", [$userId]);
        return array_map(static fn (array $r): array => [
            'id' => substr((string) $r['id'], 0, 12),
            'label' => (string) $r['label'],
            'created_at' => (int) $r['created_at'],
            'last_used_at' => $r['last_used_at'] !== null ? (int) $r['last_used_at'] : null,
            'expires_at' => (int) $r['expires_at'],
            'client' => (string) (json_dec((string) $r['data'], [])['client'] ?? ''),
        ], $rows);
    }

    /** Révoque une clé de cet utilisateur (identifiant = 12 premiers caractères de l'empreinte). */
    public static function revoke(string $id, int $userId): void
    {
        if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
            return;
        }
        foreach (Db::all("SELECT id, label FROM tokens WHERE kind = 'api_key' AND user_id = ? AND substr(id, 1, 12) = ?", [$userId, $id]) as $row) {
            Db::delete('tokens', ['id' => $row['id']]);
            Activity::log('mcp', 'api_key', null, __('Clé API révoquée : {l}', ['l' => (string) $row['label']]), Auth::id() ?? $userId);
        }
    }
}
