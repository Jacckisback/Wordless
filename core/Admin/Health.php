<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Auth;
use Wordless\Cache;
use Wordless\Config;
use Wordless\Db;
use Wordless\Settings;

/**
 * Bilan 360° du site : cinq piliers notés sur 100 (performance, SEO, GEO,
 * sécurité, contenu) et une liste d'actions classées par priorité (P0 à P3).
 */
final class Health
{
    public static function status(int $score): string
    {
        return $score >= 80 ? 'good' : ($score >= 50 ? 'warn' : 'bad');
    }

    /** @return array<string, array{label: string, icon: string, score: int, status: string, note: string, href: string, issues: list<array>}> */
    public static function pillars(): array
    {
        $p = [
            'performance' => self::performance(),
            'seo' => self::seo(),
            'geo' => self::geo(),
            'security' => self::security(),
            'content' => self::content(),
        ];
        foreach ($p as &$x) {
            $x['score'] = max(0, min(100, (int) round($x['score'])));
            $x['status'] = self::status($x['score']);
        }
        return $p;
    }

    /** Toutes les actions, triées par priorité. */
    public static function todo(array $pillars, int $limit = 6): array
    {
        $all = [];
        foreach ($pillars as $key => $p) {
            foreach ($p['issues'] as $i) {
                $all[] = $i + ['pillar' => $key];
            }
        }
        usort($all, static fn ($a, $b) => $a['prio'] <=> $b['prio']);
        return array_slice($all, 0, $limit);
    }

    private static function issue(int $prio, string $title, string $text, string $href, string $action): array
    {
        return compact('prio', 'title', 'text', 'href', 'action');
    }

    private static function performance(): array
    {
        $score = 5; // aucun JavaScript imposé sur le site public
        $issues = [];
        if (Settings::get('cache.enabled', true)) {
            $score += 30;
        } else {
            $issues[] = self::issue(1, __('Le cache de pages est désactivé'), __('Chaque visite recalcule la page. Réactivez-le : les pages se servent alors en moins d’une milliseconde.'), admin_url('settings/performance'), __('Activer'));
        }
        $codeSize = strlen((string) Settings::get('code.head', '')) + strlen((string) Settings::get('code.footer', ''));
        if ($codeSize === 0) {
            $score += 15;
        } else {
            $score += 5;
            $issues[] = self::issue(3, __('Scripts tiers ajoutés au site'), __('Le code personnalisé (analytics, pixels…) ralentit l’affichage et assouplit la protection CSP. Gardez seulement l’indispensable.'), admin_url('settings/advanced'), __('Vérifier'));
        }
        $images = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime IN ('image/jpeg','image/png','image/webp')");
        $optimized = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime IN ('image/jpeg','image/png','image/webp') AND (variants <> '{}' AND variants <> '[]' OR width <= 480)");
        $score += $images > 0 ? 25 * $optimized / $images : 25;
        if (!function_exists('imagewebp')) {
            $issues[] = self::issue(2, __('Images non converties en WebP'), __('L’extension GD avec WebP manque sur l’hébergement : demandez son activation à votre hébergeur.'), admin_url('system'), __('Voir'));
        }
        $heavy = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime LIKE 'image/%' AND size > 600000");
        if ($heavy === 0) {
            $score += 10;
        } else {
            $issues[] = self::issue(3, _n('{n} image lourde (plus de 600 Ko)', '{n} images lourdes (plus de 600 Ko)', $heavy), __('Elles ralentissent les pages qui les affichent en grand. Remplacez-les par des versions plus légères.'), admin_url('media'), __('Voir'));
        }
        $home = Cache::file('/');
        $bytes = is_file($home) ? (int) filesize($home) : 0;
        if ($bytes === 0 || $bytes < 70000) {
            $score += 15;
        } elseif ($bytes < 140000) {
            $score += 8;
        } else {
            $issues[] = self::issue(2, __('Page d’accueil lourde ({k} Ko de HTML)', ['k' => (int) round($bytes / 1024)]), __('Réduisez le nombre de blocs ou le code intégré de l’accueil.'), admin_url('content?type=page'), __('Ouvrir'));
        }
        $note = Settings::get('cache.enabled', true) ? __('Cache actif · {n} pages prêtes', ['n' => Cache::stats()['pages']]) : __('Cache désactivé');
        return ['label' => __('Performance'), 'icon' => 'zap', 'score' => $score, 'note' => $note, 'href' => admin_url('settings/performance'), 'issues' => $issues];
    }

    private static function seo(): array
    {
        $issues = [];
        $published = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published'");
        $avg = $published ? (float) Db::val("SELECT AVG(COALESCE(seo_score, 0)) FROM content WHERE status = 'published'") : 0;
        $score = $avg * 0.6;
        if (Settings::get('seo.noindex_site', false)) {
            $issues[] = self::issue(0, __('Votre site est masqué aux moteurs de recherche'), __('L’option « Décourager l’indexation » est active : Google et les IA ignorent votre site.'), admin_url('seo'), __('Corriger'));
        } else {
            $score += 15;
        }
        $homeDesc = (string) Settings::get('seo.home_description', '');
        if ($homeDesc === '' && Settings::get('home.type') === 'page') {
            $homeDesc = (string) Db::val("SELECT json_extract(seo, '$.description') FROM content WHERE id = ?", [(int) Settings::get('home.page_id')]);
        }
        $score += $homeDesc !== '' ? 10 : 0;
        if ($homeDesc === '') {
            $issues[] = self::issue(1, __('Pas de description pour la page d’accueil'), __('C’est le texte affiché sous votre nom dans Google. Écrivez 150 caractères qui donnent envie de cliquer.'), admin_url('seo'), __('Écrire'));
        }
        $notFound = (int) Db::val('SELECT COUNT(*) FROM not_found WHERE hits >= 3 AND last_at > ?', [time() - 30 * 86400]);
        $score += $notFound === 0 ? 10 : 4;
        if ($notFound > 0) {
            $issues[] = self::issue(2, _n('{n} adresse introuvable visitée plusieurs fois', '{n} adresses introuvables visitées plusieurs fois', $notFound), __('Des visiteurs (ou Google) tombent sur des erreurs 404. Redirigez-les vers la bonne page en un clic.'), admin_url('seo/redirects'), __('Rediriger'));
        }
        $score += Settings::get('site.logo_id') ? 5 : 0;
        $noDesc = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND COALESCE(json_extract(seo, '$.description'), '') = ''");
        if ($noDesc > 0) {
            $issues[] = self::issue(2, _n('{n} page sans méta-description', '{n} pages sans méta-description', $noDesc), __('Un résumé automatique est utilisé, mais un texte écrit pour donner envie de cliquer fait mieux. L’IA peut les rédiger.'), admin_url('content?type=post&missing=description'), __('Voir les pages'));
        }
        $weak = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND COALESCE(seo_score, 0) < 60");
        if ($weak > 0) {
            $issues[] = self::issue(2, _n('{n} page avec un score SEO faible', '{n} pages avec un score SEO faible', $weak), __('Ouvrez-les : l’onglet « SEO & GEO » de l’éditeur liste quoi corriger.'), admin_url('content?type=post&order=seo'), __('Améliorer'));
        }
        $alt = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime LIKE 'image/%' AND alt = ''");
        if ($alt > 0) {
            $issues[] = self::issue(3, _n('{n} image sans texte alternatif', '{n} images sans texte alternatif', $alt), __('Le texte alternatif aide Google Images et les personnes malvoyantes.'), admin_url('media?missing_alt=1'), __('Compléter'));
        }
        $score += 10; // sitemap, robots.txt, canonical, JSON-LD : automatiques
        $note = $published ? __('Score moyen {s}/100 sur {n} pages', ['s' => (int) round($avg), 'n' => $published]) : __('Aucune page publiée');
        return ['label' => __('SEO'), 'icon' => 'seo', 'score' => $score, 'note' => $note, 'href' => admin_url('seo'), 'issues' => $issues];
    }

    private static function geo(): array
    {
        $issues = [];
        $published = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published'");
        $avg = $published ? (float) Db::val("SELECT AVG(COALESCE(geo_score, 0)) FROM content WHERE status = 'published'") : 0;
        $score = $avg * 0.6;
        $score += Settings::get('geo.llms_txt', true) ? 10 : 0;
        $score += Settings::get('geo.markdown', true) ? 10 : 0;
        $crawlers = (array) Settings::get('geo.ai_crawlers', []);
        $blocked = array_keys(array_filter(array_intersect_key($crawlers, array_flip(['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended'])), static fn ($v) => !$v));
        if ($blocked) {
            $issues[] = self::issue(1, __('Des moteurs IA ne peuvent pas lire votre site'), __('Bloqués : {l}. Vous ne pourrez pas être cité dans leurs réponses.', ['l' => implode(', ', $blocked)]), admin_url('seo'), __('Débloquer'));
        } else {
            $score += 10;
        }
        $faq = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND blocks LIKE '%\"type\":\"faq\"%'");
        $score += $faq > 0 ? 5 : 0;
        if ($faq === 0 && $published > 0) {
            $issues[] = self::issue(2, __('Aucune FAQ sur le site'), __('Les questions/réponses sont le format le plus repris par ChatGPT, Perplexity et les AI Overviews de Google.'), admin_url('content?type=page'), __('Ajouter une FAQ'));
        }
        if (!array_filter((array) Settings::get('site.social', []))) {
            $issues[] = self::issue(3, __('Profils officiels non renseignés'), __('Indiquez vos profils (LinkedIn, Google Business, Instagram…) : les IA relient ainsi votre marque à des sources fiables.'), admin_url('settings'), __('Compléter'));
        } else {
            $score += 5;
        }
        $lowGeo = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND type = 'post' AND COALESCE(geo_score, 0) < 50");
        if ($lowGeo > 0) {
            $issues[] = self::issue(2, _n('{n} article peu « citable » par les IA', '{n} articles peu « citables » par les IA', $lowGeo), __('Réponse directe en introduction, titres en questions, sources et chiffres : l’onglet SEO & GEO vous guide.'), admin_url('content?type=post&order=seo'), __('Améliorer'));
        }
        return ['label' => __('GEO (IA)'), 'icon' => 'sparkles', 'score' => $score, 'note' => $published ? __('Score moyen {s}/100', ['s' => (int) round($avg)]) : __('Aucune page publiée'), 'href' => admin_url('seo'), 'issues' => $issues];
    }

    private static function security(): array
    {
        $issues = [];
        $score = 0;
        $host = (string) parse_url(Config::baseUrl(), PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.test');
        if (str_starts_with(Config::baseUrl(), 'https://')) {
            $score += 20;
            if (Settings::get('security.hsts', false)) {
                $score += 5;
            } else {
                $issues[] = self::issue(3, __('HSTS non activé'), __('Impose le HTTPS aux navigateurs, même si quelqu’un tape « http:// ».'), admin_url('settings/security'), __('Activer'));
            }
        } elseif ($local) {
            $score += 20;
        } else {
            $issues[] = self::issue(0, __('Site servi sans HTTPS'), __('Les mots de passe circulent en clair et Google pénalise le site. Activez le certificat SSL gratuit (Let’s Encrypt) chez votre hébergeur, puis mettez à jour l’adresse du site.'), admin_url('settings'), __('Mettre à jour'));
        }
        $admins = Db::all("SELECT id, totp_enabled FROM users WHERE role = 'admin' AND status = 'active'");
        $with2fa = count(array_filter($admins, static fn ($a) => (int) $a['totp_enabled'] === 1));
        $score += $admins ? 20 * $with2fa / count($admins) : 0;
        if ($with2fa < count($admins)) {
            $mine = !(int) (Auth::user()['totp_enabled'] ?? 1);
            $issues[] = self::issue(1, $mine ? __('Activez la double authentification') : __('Des administrateurs n’ont pas la double authentification'), __('C’est la protection la plus efficace contre le vol de compte.'), $mine ? admin_url('profile/2fa') : admin_url('users'), __('Activer'));
        }
        $last = (int) Settings::get('backup.last', 0);
        if (time() - $last < 2 * 86400) {
            $score += 15;
        } else {
            $issues[] = self::issue(1, $last ? __('Dernière sauvegarde il y a plus de 2 jours') : __('Aucune sauvegarde'), __('Une sauvegarde prend une seconde. Les sauvegardes automatiques quotidiennes se déclenchent lors des visites.'), admin_url('backups'), __('Sauvegarder'));
        }
        $exposed = Settings::get('security.data_exposed', null);
        if ($exposed === true) {
            $issues[] = self::issue(0, __('Le dossier data/ est accessible depuis Internet'), __('Votre serveur ignore le fichier .htaccess (Nginx ?). Ajoutez la règle indiquée dans Outils > Système, sinon votre base de données peut être téléchargée.'), admin_url('system'), __('Corriger'));
        } else {
            $score += 15;
        }
        if (PHP_VERSION_ID >= 80300) {
            $score += 10;
        } elseif (PHP_VERSION_ID >= 80200) {
            $score += 6;
            $issues[] = self::issue(3, __('PHP {v} : fin des correctifs de sécurité fin 2026', ['v' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]), __('Passez à PHP 8.3 ou plus récent depuis le panneau de votre hébergeur.'), admin_url('system'), __('Voir'));
        } else {
            $issues[] = self::issue(1, __('PHP {v} n’est plus maintenu', ['v' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]), __('Passez à PHP 8.3 ou plus récent depuis le panneau de votre hébergeur.'), admin_url('system'), __('Voir'));
        }
        $failed = (int) Db::val("SELECT COUNT(*) FROM activity WHERE action = 'login_failed' AND created_at > ?", [time() - 86400]);
        $score += $failed < 20 ? 10 : 5;
        if ($failed >= 20) {
            $issues[] = self::issue(3, _n('{n} tentative de connexion échouée en 24 h', '{n} tentatives de connexion échouées en 24 h', $failed), __('Elles sont bloquées automatiquement. Vérifiez que vos mots de passe sont solides et la double authentification active.'), admin_url('activity'), __('Voir'));
        }
        if (Config::debug()) {
            $issues[] = self::issue(1, __('Mode débogage actif'), __('Les erreurs détaillées peuvent révéler des informations. Désactivez-le dans data/config.php.'), admin_url('system'), __('Voir'));
        } else {
            $score += 5;
        }
        $note = $with2fa === count($admins) && $admins ? __('2FA active · base protégée') : __('{a}/{b} administrateurs avec 2FA', ['a' => $with2fa, 'b' => count($admins)]);
        return ['label' => __('Sécurité'), 'icon' => 'shield', 'score' => $score, 'note' => $note, 'href' => admin_url('system'), 'issues' => $issues];
    }

    private static function content(): array
    {
        $issues = [];
        $published = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published'");
        $score = min(20, $published * 4);
        $posts = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND type = 'post'");
        $fresh = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND type = 'post' AND updated_at > ?", [time() - 365 * 86400]);
        $score += $posts ? 20 * $fresh / $posts : 10;
        if ($posts - $fresh > 0) {
            $issues[] = self::issue(3, _n('{n} article non mis à jour depuis un an', '{n} articles non mis à jour depuis un an', $posts - $fresh), __('Actualisez chiffres et exemples : Google et les IA privilégient les contenus récents.'), admin_url('content?type=post&order=oldest_update'), __('Rafraîchir'));
        }
        $isFrench = in_array((string) Settings::get('language', 'fr'), ['fr'], true);
        $legal = Db::one("SELECT id, status FROM content WHERE type = 'page' AND slug IN ('mentions-legales', 'legal-notice', 'aviso-legal') AND status <> 'trash' ORDER BY status = 'published' DESC LIMIT 1");
        if ($legal && $legal['status'] === 'published') {
            $score += 15;
        } else {
            $issues[] = self::issue($isFrench ? 1 : 2, __('Mentions légales non publiées'), __('Elles sont obligatoires pour un site professionnel (éditeur, hébergeur, SIRET/NIF).'), $legal ? admin_url('content/' . $legal['id']) : admin_url('content/new?type=page'), __('Compléter'));
        }
        $privacy = Db::one("SELECT id, status FROM content WHERE type = 'page' AND slug IN ('politique-de-confidentialite', 'privacy-policy', 'politica-de-privacidad') AND status <> 'trash' ORDER BY status = 'published' DESC LIMIT 1");
        if ($privacy && $privacy['status'] === 'published') {
            $score += 10;
        } else {
            $issues[] = self::issue(2, __('Politique de confidentialité non publiée'), __('Le RGPD l’impose dès que vous recueillez des données (formulaire, commentaires).'), $privacy ? admin_url('content/' . $privacy['id']) : admin_url('content/new?type=page'), __('Compléter'));
        }
        $home = (int) Settings::get('home.page_id');
        $homeRow = $home ? Db::one('SELECT created_at, updated_at FROM content WHERE id = ?', [$home]) : null;
        if ($homeRow && (int) $homeRow['updated_at'] - (int) $homeRow['created_at'] < 60 && in_array($home, (array) Settings::get('starter.content', []), true)) {
            $issues[] = self::issue(1, __('La page d’accueil contient encore le texte d’exemple'), __('Cliquez sur les textes pour les remplacer, ou demandez à l’assistant IA de les rédiger.'), admin_url('content/' . $home), __('Personnaliser'));
        } else {
            $score += 20;
        }
        $contact = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'published' AND blocks LIKE '%\"type\":\"contact\"%'");
        $score += $contact > 0 ? 15 : 0;
        $drafts = (int) Db::val("SELECT COUNT(*) FROM content WHERE status = 'draft' AND updated_at < ?", [time() - 30 * 86400]);
        if ($drafts > 0) {
            $issues[] = self::issue(3, _n('{n} brouillon oublié depuis plus d’un mois', '{n} brouillons oubliés depuis plus d’un mois', $drafts), __('Publiez-les ou supprimez-les pour garder un site clair.'), admin_url('content?type=post&status=draft'), __('Voir'));
        }
        return ['label' => __('Contenu'), 'icon' => 'post', 'score' => $score, 'note' => _n('{n} page publiée', '{n} pages publiées', $published), 'href' => admin_url('content?type=post'), 'issues' => $issues];
    }

    /** Étapes de démarrage (disparaissent une fois masquées). */
    public static function onboarding(): array
    {
        $u = Auth::user();
        $home = (int) Settings::get('home.page_id');
        $homeRow = $home ? Db::one('SELECT created_at, updated_at FROM content WHERE id = ?', [$home]) : null;
        $legal = Db::val("SELECT 1 FROM content WHERE type = 'page' AND slug IN ('mentions-legales','legal-notice','aviso-legal') AND status = 'published'");
        $steps = [
            [__('Choisir votre direction artistique'), __('6 styles prêts ou une identité créée par l’IA'), admin_url('design'), (bool) Settings::get('design.chosen', false)],
            [__('Personnaliser la page d’accueil'), __('Cliquez sur un texte pour le modifier'), $home ? admin_url('content/' . $home) : admin_url('content?type=page'), $homeRow && (int) $homeRow['updated_at'] - (int) $homeRow['created_at'] > 60],
            [__('Compléter votre profil d’auteur'), __('Une bio crédible renforce votre référencement'), admin_url('profile'), trim((string) ($u['bio'] ?? '')) !== ''],
            [__('Activer la double authentification'), __('2 minutes, protection maximale'), admin_url('profile/2fa'), (int) ($u['totp_enabled'] ?? 0) === 1],
            [__('Ajouter votre clé Claude ou ChatGPT'), __('Pour écrire et optimiser avec l’IA'), admin_url('ai'), Settings::get('ai.anthropic_key', '') !== '' || Settings::get('ai.openai_key', '') !== ''],
            [__('Connecter Claude ou ChatGPT au site'), __('Pilotez votre site depuis la conversation'), admin_url('connect'), (bool) Db::val("SELECT 1 FROM tokens WHERE kind IN ('api_key','oauth_access','oauth_refresh') LIMIT 1")],
            [__('Publier vos mentions légales'), __('Obligatoire pour un site professionnel'), admin_url('content?type=page'), (bool) $legal],
            [__('Importer votre site WordPress'), __('Facultatif : URL, contenus et SEO conservés'), admin_url('import'), (bool) Db::val('SELECT 1 FROM content WHERE wp_id IS NOT NULL LIMIT 1')],
        ];
        return $steps;
    }
}
