<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Ai\ArtDirector;
use Wordless\Ai\Assistant;
use Wordless\Ai\Client;
use Wordless\Auth;
use Wordless\Content;
use Wordless\Crypto;
use Wordless\Http;
use Wordless\Settings;
use Wordless\Terms;

/** Assistant IA : clés, modèles, voix de marque, rédaction. */
final class AiController
{
    public function index(): string
    {
        Kernel::need('use_ai');
        if (Http::method() === 'POST') {
            Kernel::need('manage_settings');
            foreach (['anthropic', 'openai'] as $p) {
                $key = trim((string) ($_POST[$p . '_key'] ?? ''));
                if ($key !== '' && !str_contains($key, '…')) {
                    Settings::setSecret('ai.' . $p . '_key', $key);
                }
                if (!empty($_POST['remove_' . $p])) {
                    Settings::setSecret('ai.' . $p . '_key', '');
                }
            }
            $provider = ($_POST['provider'] ?? 'anthropic') === 'openai' ? 'openai' : 'anthropic';
            $am = (string) ($_POST['anthropic_model'] ?? 'claude-opus-5-5');
            Settings::setMany([
                'ai.provider' => $provider,
                'ai.anthropic_model' => preg_match('/^claude-[a-z0-9.-]+$/', $am) ? $am : 'claude-opus-5-5',
                'ai.openai_model' => preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($_POST['openai_model'] ?? '')) ?? '',
                'ai.brand_voice' => mb_substr(trim(strip_tags((string) ($_POST['brand_voice'] ?? ''))), 0, 3000),
            ]);
            Activity::log('settings', 'ai', null, __('Assistant IA'));
            Kernel::flash(__('Réglages de l’IA enregistrés.'));
            redirect(admin_url('ai'));
        }
        return Kernel::page('ai', [
            'provider' => Client::provider(),
            'anthropicKey' => Crypto::mask(Settings::secret('ai.anthropic_key')),
            'openaiKey' => Crypto::mask(Settings::secret('ai.openai_key')),
            'anthropicModel' => (string) Settings::get('ai.anthropic_model', 'claude-opus-5-5'),
            'openaiModel' => (string) Settings::get('ai.openai_model', ''),
            'voice' => (string) Settings::get('ai.brand_voice', ''),
            'usage' => Client::usage(30),
            'configured' => Client::configured(),
            'categories' => Terms::all('category'),
            'canSettings' => Auth::can('manage_settings'),
        ], __('Assistant IA'), 'ai', '', ['scripts' => ['ai.js']]);
    }

    /** Vérifie une clé et renvoie les modèles disponibles. */
    public function test(): never
    {
        Kernel::need('manage_settings');
        $in = Http::json();
        $provider = ($in['provider'] ?? '') === 'openai' ? 'openai' : 'anthropic';
        $key = trim((string) ($in['key'] ?? ''));
        if ($key === '' || str_contains($key, '…')) {
            $key = Client::key($provider);
        }
        if ($key === '') {
            json_response(['error' => __('Saisissez une clé.')], 422);
        }
        try {
            $models = Client::listModels($provider, $key);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        $ids = array_column($models, 'id');
        $suggest = $provider === 'openai' ? Client::pickOpenAiModel($models) : (in_array('claude-opus-5-5', $ids, true) || !$ids ? 'claude-opus-5-5' : $ids[0]);
        json_response(['ok' => true, 'models' => $models, 'suggested' => $suggest]);
    }

    /** Actions IA de l'éditeur et de la médiathèque, avec progression diffusée (NDJSON). */
    public function run(): never
    {
        Kernel::need('use_ai');
        $in = Http::json();
        $task = (string) ($in['task'] ?? '');
        self::stream(static function (callable $progress) use ($task, $in) {
            $in['on_delta'] = $progress;
            if ($task === 'critique') {
                $c = Content::find((int) ($in['id'] ?? 0)) ?? Content::find((int) Settings::get('home.page_id'));
                if (!$c) {
                    throw new \InvalidArgumentException(__('Page introuvable.'));
                }
                return ArtDirector::critique($c);
            }
            return Assistant::run($task, $in);
        });
    }

    /** Assistant « Rédiger un article » : crée un brouillon prêt à relire. */
    public function article(): never
    {
        Kernel::need('use_ai');
        Kernel::need('edit_content');
        $in = Http::json();
        self::stream(static function (callable $progress) use ($in) {
            $type = ($in['type'] ?? 'post') === 'page' ? 'page' : 'post';
            $r = Assistant::run('write', ['brief' => (string) ($in['brief'] ?? ''), 'keyword' => (string) ($in['keyword'] ?? ''), 'state' => ['type' => $type, 'title' => (string) ($in['title'] ?? '')], 'on_delta' => $progress]);
            $id = Content::save([
                'type' => $type, 'status' => 'draft', 'title' => $r['title'], 'slug' => $r['slug'], 'blocks' => $r['blocks'], 'excerpt' => $r['excerpt'],
                'seo' => $r['seo'], 'categories' => !empty($in['category']) ? [(int) $in['category']] : [], 'author_id' => Auth::id(),
            ], null, ['revision' => true]);
            return ['id' => $id, 'edit' => admin_url('content/' . $id)];
        });
    }

    /** Exécute $work en diffusant des lignes JSON (progression, résultat ou erreur). */
    private static function stream(callable $work): never
    {
        @set_time_limit(600);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        $send = static function (array $d): void {
            echo json_enc($d) . "\n";
            @flush();
        };
        $send(['type' => 'start']);
        $last = 0;
        $lastAt = microtime(true);
        $progress = static function (int $chars) use ($send, &$last, &$lastAt): void {
            if ($chars - $last >= 400 || microtime(true) - $lastAt > 2) {
                $last = $chars;
                $lastAt = microtime(true);
                $send(['type' => 'progress', 'chars' => $chars]);
            }
        };
        try {
            $send(['type' => 'result', 'data' => $work($progress)]);
        } catch (\Throwable $e) {
            \Wordless\App::logError($e);
            $send(['type' => 'error', 'error' => $e->getMessage()]);
        }
        exit;
    }
}
