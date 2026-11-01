<?php
declare(strict_types=1);

/*
 * Outil de développement : liste les chaînes de l'interface (__() et _n() avec un texte
 * littéral) qui manquent dans core/lang/<langue>.php.
 * Usage : php bin/i18n-missing.php [en|es]   (code de sortie 1 s'il en manque)
 * Les chaînes passées en variable (rôles, prérequis…) doivent être ajoutées à la main.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/core', FilesystemIterator::SKIP_DOTS));
$files = iterator_to_array($it);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes', FilesystemIterator::SKIP_DOTS)) as $f) {
    $files[] = $f;
}
foreach ($files as $f) {
    if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/lang/')) {
        continue;
    }
    $toks = token_get_all((string) file_get_contents($f->getPathname()));
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], ['__', '_n'], true)) {
            continue;
        }
        $j = $i - 1;
        while ($j >= 0 && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
            $j--;
        }
        if ($j >= 0 && is_array($toks[$j]) && in_array($toks[$j][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }
        $k = $i + 1;
        while (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
            $k++;
        }
        if ($toks[$k] !== '(') {
            continue;
        }
        $k++;
        $want = $t[1] === '_n' ? 2 : 1;
        for ($a = 0; $a < $want; $a++) {
            while (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (!is_array($toks[$k]) || $toks[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }
            $raw = $toks[$k][1];
            $found[stripcslashes(substr($raw, 1, -1))] = substr(str_replace($root . '/', '', $f->getPathname()), 0);
            $k++;
            while (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if ($toks[$k] !== ',') {
                break;
            }
            $k++;
        }
    }
}
$langs = isset($argv[1]) ? [$argv[1]] : array_map(static fn ($p) => basename($p, '.php'), glob($root . '/core/lang/*.php') ?: []);
$missingAny = false;
foreach ($langs as $lang) {
    $tr = (array) require $root . '/core/lang/' . basename($lang) . '.php';
    $missing = array_diff_key($found, $tr);
    // Les chaînes identiques en français et dans la langue cible ne sont pas stockées : on ne les signale
    // que si elles contiennent des lettres accentuées ou des mots typiquement français.
    $missing = array_filter($missing, static fn ($s) => (bool) preg_match('/[àâçéèêëîïôûùüÿœ’«»]|\b(le|la|les|des|du|un|une|et|ou|pour|avec|votre|vos)\b/iu', (string) $s), ARRAY_FILTER_USE_KEY);
    printf("%s : %d chaîne(s) à traduire\n", $lang, count($missing));
    foreach ($missing as $s => $file) {
        echo "  [$file] $s\n";
    }
    $missingAny = $missingAny || $missing;
}
exit($missingAny ? 1 : 0);
