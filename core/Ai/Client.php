<?php
declare(strict_types=1);

namespace Wordless\Ai;

use Wordless\Auth;
use Wordless\Db;
use Wordless\Settings;

/**
 * Client IA : Claude (API Messages d'Anthropic) ou OpenAI (Chat Completions).
 * Appels HTTP directs en cURL : Wordless n'a aucune dépendance Composer et
 * doit pouvoir s'installer par simple FTP sur un hébergement mutualisé.
 * Réponses en streaming (évite les délais d'attente sur les longues rédactions),
 * sorties JSON contraintes par schéma, images (texte alternatif).
 */
final class Client
{
    public const ANTHROPIC_VERSION = '2026-10-21';

    /** Modèles Claude proposés (le premier est le défaut recommandé). */
    public const ANTHROPIC_MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 — recommandé',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — rapide',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 — économique',
        'claude-fable-5-1' => 'Claude Fable 5.1 — le plus puissant',
    ];

    /** Libellés traduits des modèles Claude (pour les listes de choix). */
    public static function anthropicModels(): array
    {
        return [
            'claude-opus-5-5' => 'Claude Opus 5.5 — ' . __('recommandé'),
            'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — ' . __('rapide'),
            'claude-haiku-5-5' => 'Claude Haiku 5.5 — ' . __('économique'),
            'claude-fable-5-1' => 'Claude Fable 5.1 — ' . __('le plus puissant'),
        ];
    }

    /** Tarifs Claude en $ par million de jetons (entrée, sortie), pour l'estimation des coûts. */
    public const ANTHROPIC_PRICES = [
        'claude-opus-5-5' => [4.0, 20.0], 'claude-sonnet-5-5' => [2.0, 10.0], 'claude-haiku-5-5' => [0.10, 0.50], 'claude-fable-5-1' => [10.0, 50.0],
    ];

    /** Modèles qui acceptent le repli automatique en cas de refus (paramètre fallbacks: "default"). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-opus-5'];

    /** Adresse de l'API (surcharge possible par variable d'environnement pour les tests). */
    private static function base(string $provider): string
    {
        $env = getenv($provider === 'openai' ? 'WL_OPENAI_BASE' : 'WL_ANTHROPIC_BASE');
        return is_string($env) && $env !== '' ? rtrim($env, '/') : ($provider === 'openai' ? 'https://api.openai.com' : 'https://api.anthropic.com');
    }

    public static function key(string $provider): string
    {
        return Settings::secret($provider === 'openai' ? 'ai.openai_key' : 'ai.anthropic_key');
    }

    public static function provider(): string
    {
        $p = Settings::get('ai.provider', 'anthropic') === 'openai' ? 'openai' : 'anthropic';
        if (self::key($p) === '') {
            $other = $p === 'openai' ? 'anthropic' : 'openai';
            if (self::key($other) !== '') {
                return $other;
            }
        }
        return $p;
    }

    public static function configured(): bool
    {
        return \Wordless\Config::installed() && (Settings::get('ai.anthropic_key', '') !== '' || Settings::get('ai.openai_key', '') !== '');
    }

    public static function model(?string $provider = null): string
    {
        $provider ??= self::provider();
        if ($provider === 'openai') {
            return (string) Settings::get('ai.openai_model', '');
        }
        $m = (string) Settings::get('ai.anthropic_model', 'claude-opus-5-5');
        return $m !== '' ? $m : 'claude-opus-5-5';
    }

    /**
     * Demande une réponse au modèle.
     * Options : system, messages (liste de [role, content] où content est un texte ou des blocs),
     * prompt (raccourci pour un seul message utilisateur), images ([[mime, base64]], jointes au dernier message),
     * schema (JSON Schema de la réponse : objets avec additionalProperties false), effort (low|medium|high),
     * max_tokens, task (journal), on_delta (callable(int $chars)).
     * @return array{text: string, json: ?array, model: string, usage: array{input: int, output: int}}
     */
    public static function complete(array $o): array
    {
        if (!self::configured()) {
            throw new \RuntimeException(__('Aucune clé IA configurée. Ajoutez votre clé Claude ou OpenAI dans Assistant IA.'));
        }
        @set_time_limit(600);
        $provider = self::provider();
        if (!isset($o['messages'])) {
            $o['messages'] = [['user', (string) ($o['prompt'] ?? '')]];
        }
        $res = $provider === 'openai' ? self::openai($o) : self::anthropic($o);
        if (!empty($o['schema'])) {
            $json = json_decode(self::stripFences($res['text']), true);
            if (!is_array($json)) {
                throw new \RuntimeException(__('La réponse de l’IA est incomplète. Réessayez (ou raccourcissez la demande).'));
            }
            $res['json'] = $json;
        } else {
            $res['json'] = null;
        }
        try {
            Db::insert('ai_log', [
                'user_id' => Auth::id(), 'provider' => $provider, 'model' => $res['model'], 'task' => (string) ($o['task'] ?? ''),
                'input_tokens' => $res['usage']['input'], 'output_tokens' => $res['usage']['output'], 'created_at' => time(),
            ]);
        } catch (\Throwable) {
        }
        return $res;
    }

    private static function stripFences(string $t): string
    {
        $t = trim($t);
        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $t) ?? $t;
        }
        return $t;
    }

    // ------------------------------------------------------------------
    // Anthropic (Claude)
    // ------------------------------------------------------------------

    private static function anthropic(array $o): array
    {
        $model = self::model('anthropic');
        $messages = [];
        $last = count($o['messages']) - 1;
        foreach ($o['messages'] as $i => [$role, $content]) {
            if ($i === $last && $role === 'user' && !empty($o['images'])) {
                $blocks = [];
                foreach ($o['images'] as [$mime, $b64]) {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
                }
                $blocks[] = ['type' => 'text', 'text' => (string) $content];
                $content = $blocks;
            }
            $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }
        $body = [
            'model' => $model,
            'max_tokens' => (int) ($o['max_tokens'] ?? 16000),
            'stream' => true,
            'messages' => $messages,
            'output_config' => ['effort' => in_array($o['effort'] ?? '', ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $o['effort'] : 'medium'],
        ];
        if (!empty($o['system'])) {
            // Consignes longues et stables : mises en cache (moins cher et plus rapide aux appels suivants).
            $body['system'] = [['type' => 'text', 'text' => (string) $o['system'], 'cache_control' => ['type' => 'ephemeral']]];
        }
        if (!empty($o['schema'])) {
            $body['output_config']['format'] = ['type' => 'json_schema', 'schema' => $o['schema']];
        }
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . self::key('anthropic'),
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];
        if (in_array($model, self::FALLBACK_MODELS, true)) {
            // Si les garde-fous refusent une demande légitime, l'API la relance sur un autre modèle.
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
        $text = '';
        $usage = ['input' => 0, 'output' => 0];
        $stop = '';
        $error = null;
        $served = $model;
        $onDelta = $o['on_delta'] ?? null;
        self::sse(self::base('anthropic') . '/v1/messages', $headers, $body, static function (array $ev) use (&$text, &$usage, &$stop, &$error, &$served, $onDelta): void {
            switch ($ev['type'] ?? '') {
                case 'message_start':
                    $usage['input'] = (int) ($ev['message']['usage']['input_tokens'] ?? 0) + (int) ($ev['message']['usage']['cache_read_input_tokens'] ?? 0) + (int) ($ev['message']['usage']['cache_creation_input_tokens'] ?? 0);
                    $served = (string) ($ev['message']['model'] ?? $served);
                    break;
                case 'content_block_delta':
                    if (($ev['delta']['type'] ?? '') === 'text_delta') {
                        $text .= (string) $ev['delta']['text'];
                        if ($onDelta) {
                            $onDelta(mb_strlen($text));
                        }
                    }
                    break;
                case 'message_delta':
                    $stop = (string) ($ev['delta']['stop_reason'] ?? $stop);
                    $usage['output'] = (int) ($ev['usage']['output_tokens'] ?? $usage['output']);
                    break;
                case 'error':
                    $error = (string) ($ev['error']['message'] ?? 'Erreur');
                    break;
            }
        });
        if ($error !== null) {
            throw new \RuntimeException(__('Claude a renvoyé une erreur : {e}', ['e' => $error]));
        }
        if ($stop === 'refusal') {
            throw new \RuntimeException(__('Claude a refusé cette demande. Reformulez-la ou précisez le contexte professionnel.'));
        }
        if ($stop === 'max_tokens' && !empty($o['schema'])) {
            throw new \RuntimeException(__('La réponse dépasse la longueur maximale. Demandez un contenu plus court.'));
        }
        return ['text' => $text, 'model' => $served, 'usage' => $usage];
    }

    // ------------------------------------------------------------------
    // OpenAI
    // ------------------------------------------------------------------

    private static function openai(array $o): array
    {
        $model = self::model('openai');
        if ($model === '') {
            $model = self::pickOpenAiModel(self::listModels('openai', self::key('openai')));
            if ($model === '') {
                throw new \RuntimeException(__('Choisissez un modèle OpenAI dans Assistant IA.'));
            }
            Settings::set('ai.openai_model', $model);
        }
        $messages = [];
        if (!empty($o['system'])) {
            $messages[] = ['role' => 'system', 'content' => (string) $o['system']];
        }
        $last = count($o['messages']) - 1;
        foreach ($o['messages'] as $i => [$role, $content]) {
            if ($i === $last && $role === 'user' && !empty($o['images'])) {
                $parts = [['type' => 'text', 'text' => (string) $content]];
                foreach ($o['images'] as [$mime, $b64]) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]];
                }
                $content = $parts;
            }
            $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_completion_tokens' => (int) ($o['max_tokens'] ?? 16000),
        ];
        if (!empty($o['schema'])) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'reponse', 'strict' => true, 'schema' => $o['schema']]];
        }
        $text = '';
        $usage = ['input' => 0, 'output' => 0];
        $error = null;
        $onDelta = $o['on_delta'] ?? null;
        self::sse(self::base('openai') . '/v1/chat/completions', [
            'content-type: application/json', 'authorization: Bearer ' . self::key('openai'),
        ], $body, static function (array $ev) use (&$text, &$usage, &$error, $onDelta): void {
            if (isset($ev['error'])) {
                $error = (string) ($ev['error']['message'] ?? 'Erreur');
                return;
            }
            $delta = $ev['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $text .= $delta;
                if ($onDelta) {
                    $onDelta(mb_strlen($text));
                }
            }
            if (isset($ev['usage'])) {
                $usage = ['input' => (int) ($ev['usage']['prompt_tokens'] ?? 0), 'output' => (int) ($ev['usage']['completion_tokens'] ?? 0)];
            }
        });
        if ($error !== null) {
            throw new \RuntimeException(__('OpenAI a renvoyé une erreur : {e}', ['e' => $error]));
        }
        return ['text' => $text, 'model' => $model, 'usage' => $usage];
    }

    /** Choisit le modèle de conversation le plus récent dans la liste du compte. */
    public static function pickOpenAiModel(array $models): string
    {
        $chat = array_filter($models, static fn ($m) => preg_match('/^(gpt-|o\d|chatgpt-)/', $m['id'])
            && !preg_match('/(audio|realtime|transcribe|tts|search|image|embedding|instruct|codex|mini-tts|moderation|dall|whisper|preview)/', $m['id']));
        usort($chat, static fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        foreach ($chat as $m) {
            if (!preg_match('/(mini|nano)/', $m['id'])) {
                return $m['id'];
            }
        }
        return $chat ? (string) array_values($chat)[0]['id'] : '';
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /** Requête POST en streaming (Server-Sent Events) ; $onEvent reçoit chaque objet JSON. */
    private static function sse(string $url, array $headers, array $body, callable $onEvent): void
    {
        if (!function_exists('curl_init')) {
            // Repli sans cURL : requête complète non diffusée.
            $body['stream'] = false;
            unset($body['stream_options']);
            $res = \Wordless\HttpClient::request('POST', $url, ['headers' => $headers, 'body' => json_enc($body), 'timeout' => 300, 'trusted' => true]);
            $data = json_decode($res['body'], true) ?: [];
            self::emulateEvents($data, $res['status'], $onEvent);
            return;
        }
        $buffer = '';
        $raw = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_enc($body),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$raw, $onEvent): int {
                $raw .= strlen($raw) < 20000 ? $chunk : '';
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    foreach (explode("\n", $frame) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $payload = trim(substr($line, 5));
                            if ($payload === '[DONE]' || $payload === '') {
                                continue;
                            }
                            $ev = json_decode($payload, true);
                            if (is_array($ev)) {
                                $onEvent($ev);
                            }
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new \RuntimeException(__('Impossible de joindre le service IA ({e}).', ['e' => $err]));
        }
        if ($status >= 400) {
            $data = json_decode($raw, true);
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? $raw) : $raw;
            throw new \RuntimeException(match (true) {
                $status === 401 => __('Clé API refusée : vérifiez-la dans Assistant IA.'),
                $status === 429 => __('Limite d’utilisation atteinte chez le fournisseur IA. Réessayez dans une minute.'),
                $status === 529 || $status === 503 => __('Le service IA est surchargé. Réessayez dans un instant.'),
                default => __('Erreur du service IA ({s}) : {m}', ['s' => $status, 'm' => mb_substr($msg, 0, 300)]),
            });
        }
    }

    private static function emulateEvents(array $data, int $status, callable $onEvent): void
    {
        if ($status >= 400) {
            throw new \RuntimeException(__('Erreur du service IA ({s}) : {m}', ['s' => $status, 'm' => (string) ($data['error']['message'] ?? '')]));
        }
        if (isset($data['content'])) {
            $onEvent(['type' => 'message_start', 'message' => ['model' => $data['model'] ?? '', 'usage' => $data['usage'] ?? []]]);
            foreach ($data['content'] as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $onEvent(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $b['text']]]);
                }
            }
            $onEvent(['type' => 'message_delta', 'delta' => ['stop_reason' => $data['stop_reason'] ?? ''], 'usage' => $data['usage'] ?? []]);
        } elseif (isset($data['choices'])) {
            $onEvent(['choices' => [['delta' => ['content' => $data['choices'][0]['message']['content'] ?? '']]], 'usage' => $data['usage'] ?? null]);
        }
    }

    /** Liste des modèles accessibles avec une clé (sert aussi à la vérifier). */
    public static function listModels(string $provider, string $key): array
    {
        $headers = $provider === 'openai'
            ? ['authorization: Bearer ' . $key]
            : ['x-api-key: ' . $key, 'anthropic-version: ' . self::ANTHROPIC_VERSION];
        $url = self::base($provider) . ($provider === 'openai' ? '/v1/models' : '/v1/models?limit=100');
        $res = \Wordless\HttpClient::request('GET', $url, ['headers' => $headers, 'timeout' => 20, 'trusted' => true]);
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new \RuntimeException(__('Clé refusée par le fournisseur.'));
        }
        if ($res['status'] !== 200) {
            throw new \RuntimeException(__('Vérification impossible (code {c}).', ['c' => $res['status']]));
        }
        $data = json_decode($res['body'], true);
        $out = [];
        foreach ((array) ($data['data'] ?? []) as $m) {
            $out[] = ['id' => (string) ($m['id'] ?? ''), 'name' => (string) ($m['display_name'] ?? $m['id'] ?? ''), 'created' => is_numeric($m['created'] ?? null) ? (int) $m['created'] : (int) strtotime((string) ($m['created_at'] ?? ''))];
        }
        return $out;
    }

    /** Consommation des 30 derniers jours (jetons et coût estimé pour Claude). */
    public static function usage(int $days = 30): array
    {
        $rows = Db::all('SELECT provider, model, SUM(input_tokens) AS i, SUM(output_tokens) AS o, COUNT(*) AS n FROM ai_log WHERE created_at > ? GROUP BY provider, model', [time() - $days * 86400]);
        $cost = 0.0;
        $calls = 0;
        $tokens = 0;
        foreach ($rows as $r) {
            $calls += (int) $r['n'];
            $tokens += (int) $r['i'] + (int) $r['o'];
            if (isset(self::ANTHROPIC_PRICES[$r['model']])) {
                [$in, $out] = self::ANTHROPIC_PRICES[$r['model']];
                $cost += (int) $r['i'] / 1e6 * $in + (int) $r['o'] / 1e6 * $out;
            }
        }
        return ['calls' => $calls, 'tokens' => $tokens, 'cost' => round($cost, 2), 'rows' => $rows];
    }
}
