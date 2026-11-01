<?php
declare(strict_types=1);

/*
 * Construit l'archive à installer : dist/wordless-<version>.zip
 * Usage : php bin/build-dist.php
 * L'archive contient un dossier wordless/ dont on envoie le contenu par FTP.
 * Les tests, les données et les médias de développement en sont exclus.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Extension zip requise.\n");
    exit(1);
}
$root = dirname(__DIR__);
preg_match("/WL_VERSION'?\s*[,=]\s*'([^']+)'/", (string) file_get_contents($root . '/core/bootstrap.php'), $m);
$version = $m[1] ?? '0.0.0';

$include = ['index.php', 'router.php', '.htaccess', 'README.md', 'core', 'admin', 'themes', 'plugins', 'bin/wordless', 'docs',
    'data/.htaccess', 'data/index.html', 'media/.htaccess', 'media/index.html'];
$files = [];
foreach ($include as $item) {
    $path = $root . '/' . $item;
    if (is_file($path)) {
        $files[] = $item;
        continue;
    }
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if ($f->isFile() && !preg_match('#(^|/)\.DS_Store$#', $rel)) {
            $files[] = str_replace('\\', '/', $rel);
        }
    }
}
sort($files);

@mkdir($root . '/dist', 0775, true);
$zipPath = $root . '/dist/wordless-' . $version . '.zip';
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Impossible de créer $zipPath\n");
    exit(1);
}
$zip->addEmptyDir('wordless');
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, 'wordless/' . $rel);
    if ($rel === 'bin/wordless') {
        $zip->setExternalAttributesName('wordless/' . $rel, ZipArchive::OPSYS_UNIX, 0100755 << 16);
    }
}
$zip->close();
printf("%s : %d fichiers, %s Ko\n", 'dist/' . basename($zipPath), count($files), number_format(filesize($zipPath) / 1024, 0, ',', ' '));
