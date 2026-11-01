<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Cache;
use Wordless\Config;
use Wordless\Content;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Http;
use Wordless\I18n;
use Wordless\Mailer;
use Wordless\Media;
use Wordless\Redirects;
use Wordless\Search;
use Wordless\Settings;
use Wordless\Theme;

/** Réglages du site, centre SEO & GEO, redirections. */
final class SettingsController
{
    public const SECTIONS = ['general', 'reading', 'comments', 'email', 'performance', 'security', 'advanced'];

    public function general(): string
    {
        return $this->section('general');
    }

    public function section(string $section): string
    {
        Kernel::need('manage_settings');
        if (!in_array($section, self::SECTIONS, true)) {
            redirect(admin_url('settings'));
        }
        if (Http::method() === 'POST') {
            $flashed = $this->save($section);
            Activity::log('settings', 'settings', null, $section);
            Theme::resetCache();
            Cache::purge();
            if (!$flashed) {
                Kernel::flash(__('Réglages enregistrés.'));
            }
            redirect(admin_url('settings' . ($section === 'general' ? '' : '/' . $section)));
        }
        $pages = Db::all("SELECT id, title FROM content WHERE type = 'page' AND status = 'published' ORDER BY title");
        return Kernel::page('settings', [
            'section' => $section, 'pages' => $pages, 'url' => Config::baseUrl(),
            'logo' => Media::find(Settings::get('site.logo_id') ? (int) Settings::get('site.logo_id') : null),
            'icon' => Media::find(Settings::get('site.icon_id') ? (int) Settings::get('site.icon_id') : null),
            'smtp' => (array) Settings::get('mail.smtp', []),
        ], __('Réglages'), 'settings', '', ['scripts' => ['media.js']]);
    }

    /** Enregistre une section ; renvoie true si un message a déjà été préparé. */
    private function save(string $s): bool
    {
        $p = $_POST;
        $str = static fn (string $k, int $max = 300): string => mb_substr(trim(strip_tags((string) ($p[$k] ?? ''))), 0, $max);
        switch ($s) {
            case 'general':
                $social = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($p['social'] ?? '')) ?: []), static fn ($u) => (bool) preg_match('#^https://\S+$#', $u)));
                Settings::setMany([
                    'site.name' => $str('site_name', 120) ?: (string) Settings::get('site.name'),
                    'site.tagline' => $str('tagline', 200),
                    'site.org_name' => $str('org_name', 120),
                    'site.org_type' => ($p['org_type'] ?? '') === 'Person' ? 'Person' : 'Organization',
                    'site.social' => array_slice($social, 0, 12),
                    'site.logo_id' => !empty($p['logo_id']) ? (int) $p['logo_id'] : null,
                    'site.icon_id' => !empty($p['icon_id']) ? (int) $p['icon_id'] : null,
                    'language' => isset(I18n::LANGUAGES[$p['language'] ?? '']) ? $p['language'] : 'fr',
                    'timezone' => in_array($p['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true) ? $p['timezone'] : 'Europe/Paris',
                ]);
                $url = rtrim(trim((string) ($p['site_url'] ?? '')), '/');
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $url) && $url !== Config::baseUrl()) {
                    Config::set('url', $url);
                }
                break;
            case 'reading':
                $pattern = (string) ($p['permalink'] ?? '/{slug}');
                if ($pattern === 'custom') {
                    $pattern = '/' . trim(preg_replace('/[^a-z0-9{}\/_-]/i', '', (string) ($p['permalink_custom'] ?? '')) ?? '', '/');
                }
                if (!str_contains($pattern, '{slug}') && !str_contains($pattern, '{id}')) {
                    $pattern = '/{slug}';
                }
                Settings::setMany([
                    'home.type' => ($p['home_type'] ?? 'page') === 'posts' ? 'posts' : 'page',
                    'home.page_id' => !empty($p['home_page_id']) ? (int) $p['home_page_id'] : null,
                    'blog.page_id' => !empty($p['blog_page_id']) ? (int) $p['blog_page_id'] : null,
                    'blog.per_page' => max(1, min(50, (int) ($p['per_page'] ?? 10))),
                    'permalink' => $pattern,
                    'trailing_slash' => !empty($p['trailing_slash']),
                ]);
                break;
            case 'comments':
                Settings::setMany([
                    'comments.enabled' => !empty($p['enabled']),
                    'comments.moderation' => in_array($p['moderation'] ?? '', ['all', 'first', 'none'], true) ? $p['moderation'] : 'first',
                    'comments.notify' => !empty($p['notify']),
                ]);
                break;
            case 'email':
                $smtp = (array) Settings::get('mail.smtp', []);
                $smtp['host'] = $str('smtp_host', 200);
                $smtp['port'] = (int) ($p['smtp_port'] ?? 587) ?: 587;
                $smtp['secure'] = in_array($p['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $p['smtp_secure'] : 'tls';
                $smtp['user'] = $str('smtp_user', 200);
                if (($p['smtp_pass'] ?? '') !== '' && !str_contains((string) $p['smtp_pass'], '…')) {
                    $smtp['pass'] = Crypto::encrypt((string) $p['smtp_pass']);
                }
                Settings::setMany(['mail.from' => filter_var($p['from'] ?? '', FILTER_VALIDATE_EMAIL) ? $p['from'] : '', 'mail.smtp' => $smtp]);
                if (!empty($p['test'])) {
                    $me = (string) (\Wordless\Auth::user()['email'] ?? '');
                    $ok = Mailer::send($me, __('Test d’envoi Wordless'), __('Si vous lisez ceci, l’envoi d’e-mails fonctionne.'));
                    Kernel::flash($ok ? __('E-mail de test envoyé à {e}.', ['e' => $me]) : __('Échec de l’envoi : vérifiez les réglages SMTP (journal : data/logs/mail.log).'), $ok ? 'good' : 'bad');
                    return true;
                }
                break;
            case 'performance':
                Settings::setMany(['cache.enabled' => !empty($p['cache']), 'stats.enabled' => !empty($p['stats'])]);
                if (!empty($p['purge'])) {
                    Search::rebuild();
                }
                break;
            case 'security':
                Settings::setMany(['security.hsts' => !empty($p['hsts']), 'security.require_2fa' => !empty($p['require_2fa'])]);
                break;
            case 'advanced':
                Kernel::need('*');
                Settings::setMany(['code.head' => mb_substr((string) ($p['code_head'] ?? ''), 0, 20000), 'code.footer' => mb_substr((string) ($p['code_footer'] ?? ''), 0, 20000)]);
                break;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Centre SEO & GEO
    // ------------------------------------------------------------------

    public function seo(): string
    {
        Kernel::need('manage_seo');
        if (Http::method() === 'POST') {
            $p = $_POST;
            $crawlers = [];
            foreach (array_keys((array) Settings::get('geo.ai_crawlers', [])) as $bot) {
                $crawlers[$bot] = !empty($p['bot'][$bot]);
            }
            $verif = [];
            foreach (['google-site-verification', 'msvalidate.01', 'yandex-verification', 'facebook-domain-verification'] as $k) {
                $v = preg_replace('/[^A-Za-z0-9_\-=.]/', '', (string) ($p['verif'][$k] ?? '')) ?? '';
                if ($v !== '') {
                    $verif[$k] = $v;
                }
            }
            Settings::setMany([
                'seo.home_title' => mb_substr(trim(strip_tags((string) ($p['home_title'] ?? ''))), 0, 160),
                'seo.home_description' => mb_substr(trim(strip_tags((string) ($p['home_description'] ?? ''))), 0, 400),
                'seo.title_template' => str_contains((string) ($p['title_template'] ?? ''), '{title}') ? mb_substr((string) $p['title_template'], 0, 100) : '{title} {sep} {site}',
                'seo.separator' => mb_substr(trim((string) ($p['separator'] ?? '–')), 0, 3) ?: '–',
                'seo.og_image_id' => !empty($p['og_image_id']) ? (int) $p['og_image_id'] : null,
                'seo.noindex_site' => !empty($p['noindex_site']),
                'seo.indexnow' => !empty($p['indexnow']),
                'seo.verification' => $verif,
                'geo.llms_txt' => !empty($p['llms']),
                'geo.markdown' => !empty($p['markdown']),
                'geo.ai_crawlers' => $crawlers,
            ]);
            Activity::log('settings', 'seo', null, 'SEO & GEO');
            Cache::purge();
            Kernel::flash(__('Réglages SEO & GEO enregistrés.'));
            redirect(admin_url('seo'));
        }
        $weak = Content::query(['visible' => true, 'order' => 'seo', 'limit' => 12]);
        $nf = Db::all('SELECT * FROM not_found ORDER BY hits DESC, last_at DESC LIMIT 15');
        return Kernel::page('seo', [
            'report' => \Wordless\Seo::siteReport(), 'weak' => $weak, 'notFound' => $nf,
            'ogImage' => Media::find(Settings::get('seo.og_image_id') ? (int) Settings::get('seo.og_image_id') : null),
            'indexnow' => (array) Settings::get('indexnow.last', []),
        ], __('SEO & GEO'), 'seo', '<a class="btn" href="' . e(admin_url('seo/redirects')) . '">' . icon('redirect') . e(__('Redirections')) . '</a>', ['scripts' => ['media.js']]);
    }

    public function redirects(): string
    {
        Kernel::need('manage_seo');
        if (Http::method() === 'POST') {
            $a = (string) ($_POST['action'] ?? '');
            if ($a === 'add') {
                $from = trim((string) ($_POST['source'] ?? ''));
                $to = trim((string) ($_POST['target'] ?? ''));
                $code = (int) ($_POST['code'] ?? 301);
                if ($from !== '' && ($to !== '' || $code === 410)) {
                    Redirects::add($from, $to !== '' ? $to : '/', $code);
                    Kernel::flash(__('Redirection ajoutée.'));
                }
            } elseif ($a === 'delete') {
                Db::delete('redirects', ['id' => (int) ($_POST['id'] ?? 0)]);
                Kernel::flash(__('Redirection supprimée.'));
            } elseif ($a === 'import' && !empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
                $n = Redirects::importCsv((string) file_get_contents($_FILES['csv']['tmp_name'], false, null, 0, 2_000_000));
                Kernel::flash(_n('{n} redirection importée.', '{n} redirections importées.', $n));
            } elseif ($a === 'ignore404') {
                Db::delete('not_found', ['path' => (string) ($_POST['path'] ?? '')]);
            }
            redirect(admin_url('seo/redirects'));
        }
        $rows = Db::all('SELECT * FROM redirects ORDER BY created_at DESC LIMIT 500');
        $nf = Db::all('SELECT * FROM not_found ORDER BY hits DESC, last_at DESC LIMIT 50');
        foreach ($nf as &$n) {
            $s = Redirects::suggest($n['path']);
            $n['suggest'] = $s ? link_path((string) $s['path']) : '';
        }
        return Kernel::page('redirects', ['rows' => $rows, 'notFound' => $nf, 'prefill' => (string) ($_GET['from'] ?? '')], __('Redirections et pages introuvables'), 'seo');
    }
}

