<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Réglages du site (clé => valeur JSON), chargés en une requête.
 * Les clés sont « pointées » : seo.title_template, ai.provider…
 */
final class Settings
{
    private static ?array $values = null;

    public static function defaults(): array
    {
        return [
            'site.name' => 'Mon site',
            'site.tagline' => '',
            'site.logo_id' => null,
            'site.icon_id' => null,
            'site.org_type' => 'Organization', // ou Person
            'site.org_name' => '',
            'site.social' => [],
            'language' => 'fr',
            'timezone' => 'Europe/Paris',
            'home.type' => 'page', // page | posts
            'home.page_id' => null,
            'blog.page_id' => null,
            'blog.per_page' => 10,
            'permalink' => '/{slug}',
            'trailing_slash' => true,
            'comments.enabled' => true,
            'comments.moderation' => 'first', // all | first | none
            'comments.notify' => true,
            'seo.separator' => '–',
            'seo.title_template' => '{title} {sep} {site}',
            'seo.home_title' => '',
            'seo.home_description' => '',
            'seo.og_image_id' => null,
            'seo.noindex_site' => false,
            'seo.indexnow_key' => '',
            'seo.verification' => [],
            'geo.llms_txt' => true,
            'geo.markdown' => true,
            'geo.ai_crawlers' => [
                'GPTBot' => true, 'OAI-SearchBot' => true, 'ChatGPT-User' => true,
                'ClaudeBot' => true, 'Claude-SearchBot' => true, 'Claude-User' => true,
                'PerplexityBot' => true, 'Perplexity-User' => true, 'Google-Extended' => true,
                'Applebot-Extended' => true, 'Meta-ExternalAgent' => true, 'CCBot' => false, 'Bytespider' => false,
            ],
            'cache.enabled' => true,
            'stats.enabled' => true,
            'security.hsts' => false,
            'security.require_2fa' => false,
            'code.head' => '',
            'code.footer' => '',
            'design' => [],
            'menus.locations' => ['primary' => null, 'footer' => null],
            'ai.provider' => 'anthropic',
            'ai.anthropic_key' => '',
            'ai.openai_key' => '',
            'ai.anthropic_model' => 'claude-opus-5-5',
            'ai.openai_model' => '',
            'ai.brand_voice' => '',
            'mcp.enabled' => true,
            'mail.from' => '',
            'mail.smtp' => [],
            'plugins.active' => [],
            'backup.auto' => true,
            'onboarding.dismissed' => false,
        ];
    }

    private static function load(): array
    {
        if (self::$values === null) {
            self::$values = [];
            foreach (Db::all('SELECT key, value FROM settings') as $row) {
                self::$values[$row['key']] = json_decode($row['value'], true);
            }
        }
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::load();
        if (array_key_exists($key, $v)) {
            return $v[$key];
        }
        $d = self::defaults();
        return array_key_exists($key, $d) ? $d[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Db::upsert('settings', ['key' => $key, 'value' => json_enc($value)], ['key']);
        self::load();
        self::$values[$key] = $value;
    }

    public static function setMany(array $values): void
    {
        Db::tx(static function () use ($values): void {
            foreach ($values as $k => $v) {
                self::set((string) $k, $v);
            }
        });
    }

    public static function forget(string $key): void
    {
        Db::delete('settings', ['key' => $key]);
        self::load();
        unset(self::$values[$key]);
    }

    /** Valeur secrète chiffrée au repos (clés API). */
    public static function secret(string $key): string
    {
        $v = (string) self::get($key, '');
        return $v === '' ? '' : (Crypto::decrypt($v) ?? '');
    }

    public static function setSecret(string $key, string $plain): void
    {
        self::set($key, $plain === '' ? '' : Crypto::encrypt($plain));
    }

    public static function reset(): void
    {
        self::$values = null;
    }
}
