<?php
declare(strict_types=1);

namespace Wordless\Migrate;

/**
 * Lecture d'un export WordPress (WXR 1.0 à 1.2).
 *
 * Le fichier (parfois plus de 100 Mo) est lu UNE seule fois en flux avec
 * XMLReader : seule la donnée d'un élément <item> est en mémoire à un instant
 * donné. Chaque élément est converti en tableau et écrit sur une ligne JSON
 * dans un fichier par type (pages, articles, médias…) du dossier de travail.
 * Les étapes suivantes reprennent ensuite exactement où elles s'étaient
 * arrêtées grâce à la position (octets) dans ces fichiers.
 *
 * Un filtre de flux retire au passage les caractères interdits en XML
 * (caractères de contrôle copiés-collés dans les articles, UTF-8 invalide)
 * qui feraient échouer tout l'import.
 */
final class Wxr
{
    public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    public const NS_DC = 'http://purl.org/dc/elements/1.1/';

    /** Types de contenus internes à WordPress ou à ses extensions : jamais importés. */
    public const INTERNAL = [
        'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_font_family', 'wp_font_face', 'elementor_library', 'elementor_font', 'elementor_icons',
        'elementor_snippet', 'e-landing-page', 'e-floating-buttons', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy',
        'acf-ui-options-page', 'wpcf7_contact_form', 'wpforms', 'wpforms_log', 'frm_form_actions', 'frm_styles', 'nf_sub',
        'mc4wp-form', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'product_variation', 'scheduled-action',
        'et_pb_layout', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_theme_builder', 'et_code_snippet',
        'fl-builder-template', 'fl-theme-layout', 'vc_grid_item', 'templately_library', 'amp_validated_url', 'jetpack_migration',
        'feedback', 'jp_pay_order', 'jp_pay_product', 'rank_math_schema', 'wpcode', 'ct_template', 'kadence_element',
        'kadence_form', 'kadence_conversions', 'spectra-popup', 'uagb-template', 'wp_privacy_policy', 'flamingo_contact',
        'flamingo_inbound', 'shortcoder', 'wp-rest-api-log', 'ns_cloner_log', 'tablepress_table', 'astra-advanced-hook',
        'gp_elements', 'blocksy_cpt', 'ct_content_block', 'brizy_template', 'oxy_user_library', 'bricks_template',
    ];

    /** Types e-commerce : signalés comme non importés (catalogue à recréer). */
    public const SHOP = ['product', 'download', 'give_forms', 'tribe_events', 'tribe_venue', 'tribe_organizer'];

    private static bool $filter = false;

    /** Le type d'élément doit-il être ignoré (interne, technique) ? */
    public static function isInternal(string $type): bool
    {
        return in_array($type, self::INTERNAL, true) || (bool) preg_match('/^(wp_|acf|elementor_|et_|fl-|vc_|wpforms|jetpack|_)/', $type);
    }

    public static function isWpNs(string $ns): bool
    {
        return (bool) preg_match('#^https?://wordpress\.org/export/1\.\d+/$#', $ns);
    }

    /**
     * Lit le fichier WXR et prépare le dossier de travail $dir.
     * @return array analyse : site, auteurs, termes, menus, compteurs, échantillons de liens.
     */
    public static function spool(string $file, string $dir, array $options): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(__('Fichier d’export introuvable ou illisible.'));
        }
        self::registerFilter();
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new \XMLReader();
        $flags = LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT;
        // Lecture à travers le filtre de nettoyage ; à défaut (hébergement restreint), lecture directe.
        if (!@$r->open('php://filter/read=wordless.wxrclean/resource=' . $file, 'UTF-8', $flags) && !@$r->open($file, 'UTF-8', $flags)) {
            libxml_use_internal_errors($prev);
            throw new \RuntimeException(__('Impossible d’ouvrir le fichier d’export.'));
        }

        $a = [
            'site' => ['title' => '', 'link' => '', 'description' => '', 'language' => '', 'base_site_url' => '', 'base_blog_url' => '', 'wxr_version' => ''],
            'authors' => [],
            'terms' => [],
            'menus' => [],
            'other_taxonomies' => [],
            'types' => [],
            'kinds' => array_fill_keys(['attachment', 'page', 'post', 'other', 'nav_menu_item', 'wp_block', 'wp_navigation'], 0),
            'comments' => ['total' => 0, 'pingbacks' => 0, 'approved' => 0, 'pending' => 0, 'spam' => 0],
            'samples' => [],
            'trailing' => ['slash' => 0, 'noslash' => 0],
            'front_page' => null,
            'uploads' => null,
            'dates' => [],
            'media_hosts' => [],
            'shop' => [],
            'cpt' => [],
            'items' => 0,
        ];
        $ns = ['wp' => '', 'excerpt' => ''];
        $handles = [];
        $writer = static function (string $kind, array $row) use (&$handles, $dir): void {
            if (!isset($handles[$kind])) {
                $handles[$kind] = fopen($dir . '/' . $kind . '.jsonl', 'wb');
            }
            fwrite($handles[$kind], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        };
        $isRss = false;
        $otherTypes = ($options['other_types'] ?? 'pages') === 'pages';

        try {
            $ok = @$r->read();
            while ($ok) {
                if ($r->nodeType !== \XMLReader::ELEMENT) {
                    $ok = @$r->read();
                    continue;
                }
                if ($r->depth === 0) {
                    if ($r->localName !== 'rss') {
                        throw new \RuntimeException(__('Ce fichier n’est pas un export WordPress (WXR).'));
                    }
                    $isRss = true;
                    while ($r->moveToNextAttribute()) {
                        if ($r->prefix === 'xmlns' && self::isWpNs($r->value)) {
                            $ns['wp'] = $r->value;
                        } elseif ($r->prefix === 'xmlns' && str_ends_with($r->value, '/excerpt/')) {
                            $ns['excerpt'] = $r->value;
                        }
                    }
                    $r->moveToElement();
                    $ok = @$r->read();
                    continue;
                }
                if ($r->depth !== 2) {
                    $ok = @$r->read();
                    continue;
                }
                $name = $r->localName;
                $uri = (string) $r->namespaceURI;
                if ($uri === '' && $name === 'item') {
                    $xml = $r->readOuterXml();
                    $ok = @$r->next();
                    self::item($xml, $ns, $a, $writer, $otherTypes);
                    continue;
                }
                if ($uri === '' && in_array($name, ['title', 'link', 'description', 'language'], true)) {
                    $a['site'][$name] = trim($r->readString());
                } elseif (self::isWpNs($uri)) {
                    $ns['wp'] = $ns['wp'] !== '' ? $ns['wp'] : $uri;
                    if (in_array($name, ['base_site_url', 'base_blog_url', 'wxr_version'], true)) {
                        $a['site'][$name] = trim($r->readString());
                    } elseif (in_array($name, ['author', 'category', 'tag', 'term'], true)) {
                        self::channelElement($name, $r->readOuterXml(), $uri, $a);
                    }
                }
                $ok = @$r->next();
            }
        } finally {
            $r->close();
            foreach ($handles as $h) {
                fclose($h);
            }
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$isRss || ($ns['wp'] === '' && $a['items'] === 0)) {
            throw new \RuntimeException(__('Ce fichier n’est pas un export WordPress (WXR).'));
        }
        foreach ($errors as $err) {
            if ($err->level === LIBXML_ERR_FATAL) {
                $a['xml_error'] = __('Le fichier d’export est incomplet ou abîmé (ligne {l}) : seuls les éléments lus avant l’erreur sont importés.', ['l' => $err->line]);
                break;
            }
        }
        $a['terms'] = array_values($a['terms']);
        $a['menus'] = array_values($a['menus']);
        file_put_contents($dir . '/header.json', json_encode([
            'authors' => $a['authors'], 'terms' => $a['terms'], 'menus' => $a['menus'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $a;
    }

    /** Auteurs, catégories, étiquettes et termes déclarés en tête du fichier. */
    private static function channelElement(string $name, string $xml, string $nsWp, array &$a): void
    {
        $s = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_PARSEHUGE);
        if ($s === false) {
            return; // (un SimpleXMLElement sans enfant hors espace de noms vaut « false » : comparaison stricte)
        }
        $w = $s->children($nsWp);
        $str = static fn ($v): string => trim((string) $v);
        switch ($name) {
            case 'author':
                $login = $str($w->author_login);
                if ($login === '') {
                    return;
                }
                $a['authors'][$login] = [
                    'id' => (int) $str($w->author_id), 'login' => $login, 'email' => $str($w->author_email),
                    'name' => $str($w->author_display_name), 'first_name' => $str($w->author_first_name),
                    'last_name' => $str($w->author_last_name), 'slug' => '', 'role' => '', 'bio' => '', 'registered' => '',
                ];
                return;
            case 'category':
                self::addTerm($a, 'category', (int) $str($w->term_id), rawurldecode($str($w->category_nicename)), $str($w->cat_name), rawurldecode($str($w->category_parent)), $str($w->category_description));
                return;
            case 'tag':
                self::addTerm($a, 'tag', (int) $str($w->term_id), rawurldecode($str($w->tag_slug)), $str($w->tag_name), '', $str($w->tag_description));
                return;
            case 'term':
                $tax = $str($w->term_taxonomy);
                $slug = rawurldecode($str($w->term_slug));
                $id = (int) $str($w->term_id);
                if ($tax === 'category' || $tax === 'post_tag') {
                    self::addTerm($a, $tax === 'post_tag' ? 'tag' : 'category', $id, $slug, $str($w->term_name), rawurldecode($str($w->term_parent)), $str($w->term_description));
                } elseif ($tax === 'nav_menu') {
                    $a['menus'][$id ?: $slug] = ['id' => $id, 'slug' => $slug, 'name' => $str($w->term_name), 'locations' => []];
                } elseif ($tax !== 'post_format' && $tax !== '') {
                    $a['other_taxonomies'][$tax] = ($a['other_taxonomies'][$tax] ?? 0) + 1;
                }
                return;
        }
    }

    private static function addTerm(array &$a, string $tax, int $id, string $slug, string $name, string $parentSlug, string $desc): void
    {
        if ($slug === '' && $name === '') {
            return;
        }
        // WordPress 6.x/7.x émet chaque catégorie deux fois (<wp:category> et <wp:term>) : dédoublonnage.
        $key = $tax . ':' . ($id ?: $slug);
        $a['terms'][$key] = [
            'id' => $id, 'taxonomy' => $tax, 'slug' => $slug, 'name' => $name !== '' ? $name : $slug,
            'parent' => 0, 'parent_slug' => $parentSlug, 'description' => $desc, 'link' => '',
        ];
    }

    /** Convertit un <item> et l'écrit dans le fichier de son type. */
    private static function item(string $xml, array $ns, array &$a, callable $write, bool $otherTypes): void
    {
        $s = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_PARSEHUGE);
        if ($s === false) {
            return; // (un SimpleXMLElement sans enfant hors espace de noms vaut « false » : comparaison stricte)
        }
        $nsWp = $ns['wp'];
        if ($nsWp === '') {
            foreach ($s->getDocNamespaces(true) as $uri) {
                if (self::isWpNs($uri)) {
                    $nsWp = $uri;
                }
            }
        }
        $nsExcerpt = $ns['excerpt'] !== '' ? $ns['excerpt'] : ($nsWp !== '' ? $nsWp . 'excerpt/' : '');
        $w = $s->children($nsWp);
        $str = static fn ($v): string => (string) $v;
        $type = trim($str($w->post_type));
        $status = trim($str($w->status));
        $a['items']++;
        $a['types'][$type][$status] = ($a['types'][$type][$status] ?? 0) + 1;

        $meta = [];
        $oldSlugs = [];
        foreach ($w->postmeta as $pm) {
            $k = $str($pm->meta_key);
            $v = $str($pm->meta_value);
            if ($k === '_wp_old_slug') {
                $oldSlugs[] = rawurldecode($v);
            } elseif (!array_key_exists($k, $meta)) {
                $meta[$k] = $v;
            }
        }
        $terms = [];
        foreach ($s->category as $c) {
            $terms[] = ['taxonomy' => (string) $c['domain'], 'slug' => rawurldecode((string) $c['nicename']), 'name' => trim($str($c))];
        }
        $item = [
            'id' => (int) $str($w->post_id),
            'type' => $type,
            'status' => $status,
            'title' => $str($s->title),
            'link' => trim($str($s->link)),
            'guid' => trim($str($s->guid)),
            'author' => trim($str($s->children(self::NS_DC)->creator)),
            'content' => $str($s->children(self::NS_CONTENT)->encoded),
            'excerpt' => $nsExcerpt !== '' ? $str($s->children($nsExcerpt)->encoded) : '',
            'date' => trim($str($w->post_date)),
            'date_gmt' => trim($str($w->post_date_gmt)),
            'modified_gmt' => trim($str($w->post_modified_gmt)),
            'comment_status' => trim($str($w->comment_status)),
            'slug' => rawurldecode(trim($str($w->post_name))),
            'parent' => (int) $str($w->post_parent),
            'menu_order' => (int) $str($w->menu_order),
            'password' => $str($w->post_password),
            'sticky' => trim($str($w->is_sticky)) === '1',
            'attachment_url' => trim($str($w->attachment_url)),
            'terms' => $terms,
            'meta' => $meta,
            'old_slugs' => $oldSlugs,
            'rendered' => false,
        ];

        // Commentaires : fichier séparé (phase « commentaires »), triés par identifiant.
        $comments = [];
        foreach ($w->comment as $c) {
            $comments[] = [
                'id' => (int) $str($c->comment_id), 'post' => $item['id'], 'parent' => (int) $str($c->comment_parent),
                'author' => trim($str($c->comment_author)), 'email' => trim($str($c->comment_author_email)),
                'url' => trim($str($c->comment_author_url)), 'ip' => trim($str($c->comment_author_IP)),
                'date_gmt' => trim($str($c->comment_date_gmt)), 'date' => trim($str($c->comment_date)),
                'content' => $str($c->comment_content), 'approved' => trim($str($c->comment_approved)),
                'type' => trim($str($c->comment_type)), 'user_id' => (int) $str($c->comment_user_id),
            ];
        }

        $kind = match (true) {
            $type === 'attachment' => 'attachment',
            in_array($type, ['page', 'post', 'nav_menu_item', 'wp_block', 'wp_navigation'], true) => $type,
            self::isInternal($type) => null,
            in_array($type, self::SHOP, true) => 'shop',
            default => 'cpt',
        };
        if ($kind === 'shop') {
            $a['shop'][$type] = ($a['shop'][$type] ?? 0) + 1;
            return;
        }
        if ($kind === 'cpt') {
            $a['cpt'][$type] = ($a['cpt'][$type] ?? 0) + 1;
            if (!$otherTypes) {
                return;
            }
            $kind = 'other';
        }
        if ($kind === null) {
            return;
        }
        $write($kind, $item);
        $a['kinds'][$kind]++;

        if ($comments && in_array($kind, ['page', 'post', 'other'], true)) {
            usort($comments, static fn ($x, $y) => $x['id'] <=> $y['id']);
            foreach ($comments as $c) {
                $a['comments']['total']++;
                if (in_array($c['type'], ['pingback', 'trackback'], true)) {
                    $a['comments']['pingbacks']++;
                } elseif ($c['approved'] === '1') {
                    $a['comments']['approved']++;
                } elseif ($c['approved'] === '0') {
                    $a['comments']['pending']++;
                } else {
                    $a['comments']['spam']++;
                }
                $write('comments', $c);
            }
        }

        // Informations pour deviner la structure des permaliens et l'accueil.
        if ($status === 'publish' && in_array($type, ['post', 'page'], true) && $item['link'] !== '' && !str_contains($item['link'], '?')) {
            str_ends_with($item['link'], '/') ? $a['trailing']['slash']++ : $a['trailing']['noslash']++;
            if ($type === 'post' && count($a['samples']) < 100) {
                $a['samples'][] = [
                    'link' => $item['link'], 'slug' => $item['slug'], 'date' => $item['date'], 'id' => $item['id'],
                    'cats' => array_values(array_map(static fn ($t) => $t['slug'], array_filter($terms, static fn ($t) => $t['taxonomy'] === 'category'))),
                ];
            }
        }
        // Dates locales et GMT : servent à retrouver le fuseau horaire du site.
        if (in_array($status, ['publish', 'future', 'private'], true) && $item['date_gmt'] !== '' && !str_starts_with($item['date_gmt'], '0000') && count($a['dates']) < 200) {
            $a['dates'][] = [$item['date'], $item['date_gmt']];
        }
        if ($type === 'page' && $status === 'publish' && $a['front_page'] === null) {
            $home = rtrim($a['site']['base_blog_url'] !== '' ? $a['site']['base_blog_url'] : $a['site']['link'], '/');
            if ($home !== '' && rtrim($item['link'], '/') === $home) {
                $a['front_page'] = $item['id'];
            }
        }
        $siteHosts = [Context::hostKey($a['site']['base_blog_url'] ?: $a['site']['link']), Context::hostKey($a['site']['base_site_url'])];
        $mediaHost = $type === 'attachment' ? Context::hostKey($item['attachment_url']) : '';
        if ($mediaHost !== '' && !in_array($mediaHost, $siteHosts, true) && !in_array($mediaHost, $a['media_hosts'], true) && count($a['media_hosts']) < 10) {
            $a['media_hosts'][] = $mediaHost; // médias servis par un CDN ou un stockage externe
        }
        if ($type === 'attachment' && $a['uploads'] === null && $mediaHost !== '' && in_array($mediaHost, $siteHosts, true) && !empty($meta['_wp_attached_file'])) {
            $file = ltrim($meta['_wp_attached_file'], '/');
            $path = rawurldecode((string) parse_url($item['attachment_url'], PHP_URL_PATH));
            if (str_ends_with($path, '/' . $file)) {
                $a['uploads'] = substr($path, 0, -strlen($file) - 1);
            }
        }
    }

    // ------------------------------------------------------------------
    // Lecture des fichiers préparés
    // ------------------------------------------------------------------

    public static function header(string $dir): array
    {
        $h = is_file($dir . '/header.json') ? json_decode((string) file_get_contents($dir . '/header.json'), true) : null;
        return is_array($h) ? $h : ['authors' => [], 'terms' => [], 'menus' => []];
    }

    /** Élément suivant d'un type, à partir de la position $offset (mise à jour). */
    public static function next(string $dir, string $kind, int &$offset): ?array
    {
        $file = $dir . '/' . $kind . '.jsonl';
        if (!is_file($file)) {
            return null;
        }
        $h = fopen($file, 'rb');
        if (!$h) {
            return null;
        }
        try {
            fseek($h, $offset);
            while (($line = fgets($h)) !== false) {
                $offset = (int) ftell($h);
                $row = json_decode($line, true);
                if (is_array($row)) {
                    return $row;
                }
            }
            return null;
        } finally {
            fclose($h);
        }
    }

    /** Tous les éléments d'un type peu volumineux (menus, compositions synchronisées). */
    public static function all(string $dir, string $kind): array
    {
        $out = [];
        $offset = 0;
        while (($row = self::next($dir, $kind, $offset)) !== null) {
            $out[] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Filtre de flux : caractères interdits en XML
    // ------------------------------------------------------------------

    private static function registerFilter(): void
    {
        if (!self::$filter) {
            self::$filter = stream_filter_register('wordless.wxrclean', WxrCleanFilter::class) || in_array('wordless.wxrclean', stream_get_filters(), true);
        }
    }
}

/**
 * Filtre de flux : supprime les caractères de contrôle interdits en XML 1.0 et
 * remplace les séquences UTF-8 invalides, en gérant les caractères coupés
 * entre deux blocs lus.
 */
final class WxrCleanFilter extends \php_user_filter
{
    private string $carry = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $data = $this->carry . $bucket->data;
            $this->carry = '';
            // Garde pour le bloc suivant un caractère multi-octets incomplet en fin de bloc.
            $len = strlen($data);
            for ($i = 1; $i <= 3 && $i <= $len; $i++) {
                $byte = ord($data[$len - $i]);
                if ($byte < 0x80) {
                    break;
                }
                if ($byte >= 0xC0) {
                    $need = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
                    if ($need > $i) {
                        $this->carry = substr($data, $len - $i);
                        $data = substr($data, 0, $len - $i);
                    }
                    break;
                }
            }
            $bucket->data = self::clean($data);
            stream_bucket_append($out, $bucket);
        }
        if ($closing && $this->carry !== '') {
            $bucket = stream_bucket_new($this->stream, self::clean($this->carry));
            $this->carry = '';
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }

    private static function clean(string $data): string
    {
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_scrub($data, 'UTF-8');
        }
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $data);
    }
}
