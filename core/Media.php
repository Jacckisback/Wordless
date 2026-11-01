<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Médiathèque : envoi sécurisé (type réel vérifié, ré-encodage des images qui
 * supprime les métadonnées EXIF/GPS), variantes responsives + WebP,
 * balise <picture> avec srcset, largeur/hauteur (zéro décalage de mise en page).
 */
final class Media
{
    public const WIDTHS = [480, 960, 1440];
    public const MAX_DIM = 2400;

    public const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'zip' => 'application/zip', 'epub' => 'application/epub+zip',
    ];

    public static function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        static $cache = [];
        if (!array_key_exists($id, $cache)) {
            $row = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
            $cache[$id] = $row ? self::decode($row) : null;
        }
        return $cache[$id];
    }

    public static function decode(array $row): array
    {
        $row['variants'] = json_dec($row['variants'], []);
        return $row;
    }

    public static function isImage(array $m): bool
    {
        return str_starts_with((string) $m['mime'], 'image/');
    }

    /** Envoi depuis un formulaire ($_FILES['file']). */
    public static function upload(array $file, array $meta = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ((int) ($file['error'] ?? 4)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('Fichier trop volumineux (limite du serveur : {max}).', ['max' => ini_get('upload_max_filesize')]),
                UPLOAD_ERR_NO_FILE => __('Aucun fichier reçu.'),
                default => __('L’envoi a échoué, réessayez.'),
            });
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(__('Fichier refusé.'));
        }
        return self::import((string) $file['tmp_name'], (string) $file['name'], $meta);
    }

    /** Importe un fichier distant (WordPress, MCP, IA). */
    public static function importUrl(string $url, array $meta = [], ?string $targetRel = null): array
    {
        $existing = Db::one('SELECT * FROM media WHERE source_url = ?', [$url]);
        if ($existing) {
            return self::decode($existing);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wlm');
        try {
            $res = HttpClient::download($url, $tmp);
            if ($res['status'] !== 200) {
                throw new \RuntimeException(__('Téléchargement impossible ({code}) : {url}', ['code' => $res['status'], 'url' => $url]));
            }
            $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'fichier';
            $meta['source_url'] = $url;
            return self::import($tmp, rawurldecode($name), $meta, $targetRel);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Vérifie, range et traite un fichier local.
     * $targetRel permet de conserver un chemin (ex. 2024/05/photo.jpg depuis WordPress).
     */
    public static function import(string $tmp, string $originalName, array $meta = [], ?string $targetRel = null): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'jpe') {
            $ext = 'jpg';
        }
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : (self::TYPES[$ext] ?? 'application/octet-stream');
        if (!isset(self::TYPES[$ext])) {
            $ext = (string) (array_search($mime, self::TYPES, true) ?: '');
        }
        if ($ext === '' || !isset(self::TYPES[$ext])) {
            throw new \RuntimeException(__('Type de fichier non autorisé.'));
        }
        $expected = self::TYPES[$ext];
        $compatible = $mime === $expected
            || ($ext === 'csv' && str_starts_with($mime, 'text/'))
            || ($ext === 'txt' && str_starts_with($mime, 'text/'))
            || (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'epub'], true) && in_array($mime, ['application/zip', 'application/octet-stream', $expected], true))
            || ($ext === 'm4a' && in_array($mime, ['audio/x-m4a', 'video/mp4', 'audio/mp4'], true))
            || ($ext === 'ogg' && str_contains($mime, 'ogg'));
        if (!$compatible) {
            throw new \RuntimeException(__('Le contenu du fichier ne correspond pas à son extension.'));
        }
        $hash = hash_file('sha256', $tmp);
        if (empty($meta['allow_duplicate'])) {
            $dupe = Db::one('SELECT * FROM media WHERE hash = ?', [$hash]);
            if ($dupe) {
                return self::decode($dupe);
            }
        }

        if ($targetRel !== null && preg_match('#^(\d{4}/\d{2}/)?[\w.\-]+$#', $targetRel) && !str_contains($targetRel, '..')) {
            $rel = self::uniquePath(preg_replace('#\.[^.]+$#', '', $targetRel) . '.' . $ext);
        } else {
            $base = slugify(pathinfo($originalName, PATHINFO_FILENAME), 60) ?: 'fichier';
            $rel = self::uniquePath(gmdate('Y/m/') . $base . '.' . $ext);
        }
        $dest = WL_MEDIA . '/' . $rel;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
            throw new \RuntimeException(__('Impossible d’enregistrer le fichier.'));
        }
        @chmod($dest, 0644);

        $row = [
            'path' => $rel,
            'mime' => $expected,
            'size' => (int) filesize($dest),
            'alt' => mb_substr(trim((string) ($meta['alt'] ?? '')), 0, 250),
            'title' => mb_substr(trim((string) ($meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME))), 0, 200),
            'caption' => mb_substr(trim((string) ($meta['caption'] ?? '')), 0, 500),
            'hash' => $hash,
            'user_id' => Auth::id(),
            'wp_id' => $meta['wp_id'] ?? null,
            'source_url' => $meta['source_url'] ?? null,
            'created_at' => (int) ($meta['created_at'] ?? time()),
        ];
        if (str_starts_with($expected, 'image/')) {
            $row = array_merge($row, self::processImage($dest, $rel));
            $row['hash'] = $hash;
        }
        $id = Db::insert('media', $row);
        Activity::log('upload', 'media', $id, $row['title']);
        return self::find($id) ?? [];
    }

    private static function uniquePath(string $rel): string
    {
        $info = pathinfo($rel);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $candidate = $rel;
        $i = 2;
        while (is_file(WL_MEDIA . '/' . $candidate) || Db::val('SELECT 1 FROM media WHERE path = ?', [$candidate])) {
            $candidate = $dir . $info['filename'] . '-' . $i++ . '.' . $info['extension'];
        }
        return $candidate;
    }

    /**
     * Ré-encode l'image (supprime EXIF/GPS, corrige l'orientation), la limite à
     * MAX_DIM px et crée les variantes de largeur + WebP.
     */
    public static function processImage(string $file, string $rel): array
    {
        $info = @getimagesize($file);
        $out = ['width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'variants' => []];
        if (!$info || !extension_loaded('gd')) {
            return $out;
        }
        $type = $info[2];
        if ($type === IMAGETYPE_GIF) {
            return $out; // les GIF animés perdraient leur animation
        }
        @ini_set('memory_limit', '512M');
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if (!$img) {
            return $out;
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot !== 0) {
                $rotated = imagerotate($img, $rot, 0);
                if ($rotated) {
                    imagedestroy($img);
                    $img = $rotated;
                }
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) > self::MAX_DIM) {
            $ratio = self::MAX_DIM / max($w, $h);
            $img = self::resize($img, (int) round($w * $ratio), (int) round($h * $ratio));
            $w = imagesx($img);
            $h = imagesy($img);
        }
        $fmt = $type === IMAGETYPE_PNG ? 'png' : ($type === IMAGETYPE_WEBP ? 'webp' : 'jpg');
        self::save($img, $file, $fmt);
        $out['width'] = $w;
        $out['height'] = $h;
        $out['size'] = (int) filesize($file);

        $base = preg_replace('#\.[^.]+$#', '', $rel);
        $variants = [];
        $webp = function_exists('imagewebp');
        foreach (self::WIDTHS as $vw) {
            if ($vw >= $w) {
                break;
            }
            $vh = (int) round($h * $vw / $w);
            $small = self::resize($img, $vw, $vh, false);
            $v = [];
            if ($fmt !== 'webp') {
                $v[$fmt] = $base . '-' . $vw . 'w.' . $fmt;
                self::save($small, WL_MEDIA . '/' . $v[$fmt], $fmt);
            }
            if ($webp) {
                $v['webp'] = $base . '-' . $vw . 'w.webp';
                self::save($small, WL_MEDIA . '/' . $v['webp'], 'webp');
            }
            imagedestroy($small);
            $variants[(string) $vw] = $v;
        }
        if ($webp && $fmt !== 'webp') {
            $full = $base . '.webp';
            if (!is_file(WL_MEDIA . '/' . $full)) {
                self::save($img, WL_MEDIA . '/' . $full, 'webp');
                $variants['full'] = ['webp' => $full];
            }
        }
        imagedestroy($img);
        $out['variants'] = $variants;
        return $out;
    }

    private static function resize(\GdImage $img, int $w, int $h, bool $destroy = true): \GdImage
    {
        $dst = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        if ($destroy) {
            imagedestroy($img);
        }
        return $dst;
    }

    private static function save(\GdImage $img, string $file, string $fmt): void
    {
        match ($fmt) {
            'png' => imagepng($img, $file, 8),
            'webp' => imagewebp($img, $file, 80),
            default => imagejpeg($img, $file, 82),
        };
        @chmod($file, 0644);
    }

    public static function url(?array $m, ?int $width = null): string
    {
        if (!$m) {
            return '';
        }
        if ($width !== null && self::isImage($m)) {
            foreach ($m['variants'] as $w => $v) {
                if ($w !== 'full' && (int) $w >= $width) {
                    $fmt = pathinfo($m['path'], PATHINFO_EXTENSION);
                    return media_url($v[$fmt] ?? $v['webp'] ?? $m['path']);
                }
            }
        }
        return media_url($m['path']);
    }

    public static function absoluteUrl(?array $m, ?int $width = null): string
    {
        if (!$m) {
            return '';
        }
        $u = self::url($m, $width);
        $base = Config::basePath();
        return Config::baseUrl() . ($base !== '' ? substr($u, strlen($base)) : $u);
    }

    /**
     * <picture> responsive. Options : sizes, class, alt, loading (lazy|eager),
     * priority (true => fetchpriority=high, pour l'image principale/LCP).
     */
    public static function picture(int|array|null $media, array $o = []): string
    {
        $m = is_array($media) ? $media : self::find($media);
        if (!$m || !self::isImage($m)) {
            return '';
        }
        $alt = (string) ($o['alt'] ?? $m['alt']);
        $sizes = (string) ($o['sizes'] ?? '(min-width: 72rem) 72rem, 100vw');
        $fmt = pathinfo($m['path'], PATHINFO_EXTENSION);
        $priority = !empty($o['priority']);
        $attrs = [
            'src' => media_url($m['path']),
            'alt' => $alt,
            'width' => $m['width'],
            'height' => $m['height'],
            'loading' => $priority ? 'eager' : ($o['loading'] ?? 'lazy'),
            'decoding' => $priority ? 'sync' : 'async',
        ];
        if ($priority) {
            $attrs['fetchpriority'] = 'high';
        }
        if (!empty($o['class'])) {
            $attrs['class'] = $o['class'];
        }
        $set = [];
        $webpSet = [];
        foreach ($m['variants'] as $w => $v) {
            if ($w === 'full') {
                continue;
            }
            if (isset($v[$fmt])) {
                $set[] = media_url($v[$fmt]) . ' ' . $w . 'w';
            }
            if (isset($v['webp'])) {
                $webpSet[] = media_url($v['webp']) . ' ' . $w . 'w';
            }
        }
        if ($set || $webpSet) {
            $set[] = media_url($m['path']) . ' ' . $m['width'] . 'w';
            if ($fmt === 'webp') {
                $webpSet = [];
            } elseif (isset($m['variants']['full']['webp'])) {
                $webpSet[] = media_url($m['variants']['full']['webp']) . ' ' . $m['width'] . 'w';
            }
        }
        $img = '<img';
        foreach ($attrs as $k => $v) {
            if ($v !== null && $v !== '') {
                $img .= ' ' . $k . '="' . e($v) . '"';
            } elseif ($k === 'alt') {
                $img .= ' alt=""';
            }
        }
        if (count($set) > 1) {
            $img .= ' srcset="' . e(implode(', ', $set)) . '" sizes="' . e($sizes) . '"';
        }
        $img .= '>';
        if (count($webpSet) > 1) {
            return '<picture><source type="image/webp" srcset="' . e(implode(', ', $webpSet)) . '" sizes="' . e($sizes) . '">' . $img . '</picture>';
        }
        return $img;
    }

    public static function delete(int $id): void
    {
        $m = self::find($id);
        if (!$m) {
            return;
        }
        $files = [$m['path']];
        foreach ($m['variants'] as $v) {
            foreach ($v as $f) {
                $files[] = $f;
            }
        }
        foreach ($files as $f) {
            $abs = WL_MEDIA . '/' . $f;
            if (is_file($abs) && str_starts_with(realpath($abs) ?: '', realpath(WL_MEDIA) ?: WL_MEDIA)) {
                @unlink($abs);
            }
        }
        Db::delete('media', ['id' => $id]);
        Activity::log('delete', 'media', $id, $m['title']);
    }

    public static function list(array $q = []): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($q['q'])) {
            $where[] = '(title LIKE ? OR alt LIKE ? OR path LIKE ?)';
            $like = '%' . $q['q'] . '%';
            array_push($p, $like, $like, $like);
        }
        if (($q['type'] ?? '') === 'image') {
            $where[] = "mime LIKE 'image/%'";
        } elseif (($q['type'] ?? '') === 'file') {
            $where[] = "mime NOT LIKE 'image/%'";
        }
        if (!empty($q['missing_alt'])) {
            $where[] = "mime LIKE 'image/%' AND alt = ''";
        }
        $limit = max(1, min(200, (int) ($q['limit'] ?? 60)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $rows = Db::all('SELECT * FROM media WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ? OFFSET ?', [...$p, $limit, $offset]);
        return array_map([self::class, 'decode'], $rows);
    }

    /** Poids total des médias (fichiers originaux et variantes). */
    public static function diskUsage(): int
    {
        $total = 0;
        if (!is_dir(WL_MEDIA)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WL_MEDIA, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $total += $f->getSize();
        }
        return $total;
    }
}
