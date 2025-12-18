<?php
/**
 * audit-codebase.php
 * ------------------
 * Rychlý audit projektových souborů:
 * - najde možné "nepoužívané" soubory (nikde se na ně neodkazuje názvem souboru)
 * - vypíše mapu importérů a JS parserů
 * - ukáže odkazy (include/require/script/link) mezi soubory
 *
 * Použití: nakopírujte do kořene projektu (vedle broker.php) a otevřete v prohlížeči,
 * nebo spusťte přes CLI: php audit-codebase.php
 *
 * POZOR: Jednoduchá heuristika (vyhledávání podle názvu souboru) – může mít false +/-.
 */
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

$root = __DIR__;
$exts = ['php','js','css','html','htm'];
$files = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) continue;
    $rel = ltrim(str_replace('\\','/', substr($file->getPathname(), strlen($root))), '/');
    $files[$rel] = [
        'path' => $file->getPathname(),
        'ext'  => $ext,
        'size' => $file->getSize(),
        'refs' => [], // who references this file
        'links'=> [], // which files this file references
    ];
}

// simple content cache
$contents = [];
foreach ($files as $rel => $meta) {
    $contents[$rel] = @file_get_contents($meta['path']) ?: '';
}

function add_link(&$files, $from, $to) {
    if (!isset($files[$from]) || !isset($files[$to])) return;
    $files[$from]['links'][] = $to;
    $files[$to]['refs'][] = $from;
}

// detect references by filename (include/require/script/link/src/href)
foreach ($files as $from => $meta) {
    $code = $GLOBALS['contents'][$from];

    // PHP include/require
    if ($meta['ext'] === 'php') {
        if (preg_match_all('/\b(include|require)(_once)?\s*\(?\s*[\'"]([^\'"]+)[\'"]\s*\)?/i', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $target = $x[3];
                // normalizace relativních cest
                $targetRel = ltrim(str_replace('\\','/', realpath(dirname($meta['path']).'/'.$target)), $GLOBALS['root'].'/');
                if ($targetRel && isset($files[$targetRel])) add_link($files, $from, $targetRel);
            }
        }
    }

    // HTML/JS/CSS <script src>, <link href>, import ... from '...'
    if (preg_match_all('/\b(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $target = $x[1];
            if (preg_match('/^(https?:)?\/\//', $target)) continue;
            $targetRel = ltrim(str_replace('\\','/', realpath(dirname($meta['path']).'/'.$target)), $GLOBALS['root'].'/');
            if ($targetRel && isset($files[$targetRel])) add_link($files, $from, $targetRel);
        }
    }
    if ($meta['ext'] === 'js') {
        if (preg_match_all('/\bimport\s+[^;]*\s+from\s+[\'"]([^\'"]+)[\'"]/i', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $target = $x[1];
                if (preg_match('/^(https?:)?\/\//', $target)) continue;
                // doplň .js, pokud chybí
                if (pathinfo($target, PATHINFO_EXTENSION)==='') $target .= '.js';
                $targetRel = ltrim(str_replace('\\','/', realpath(dirname($meta['path']).'/'.$target)), $GLOBALS['root'].'/');
                if ($targetRel && isset($files[$targetRel])) add_link($files, $from, $targetRel);
            }
        }
    }
}

// heuristika "možná nepoužívané": nikdo neodkazuje (refs prázdné) a současně to není "vstupní" stránka (index.php apod.)
$entryHints = ['index.php','broker.php','rates.php','import.php','portfolio.php','reports.php','transaction.php'];
$maybeUnused = [];
foreach ($files as $rel => $meta) {
    if (in_array(basename($rel), $entryHints, true)) continue;
    if (empty($meta['refs'])) $maybeUnused[] = $rel;
}

// registry importérů (PHP i JS)
$phpImporters = [];
foreach ($files as $rel => $meta) {
    if ($meta['ext'] === 'php' && preg_match('/import_methods\/(.+Importer)\.php$/', $rel, $m)) {
        $phpImporters[] = $rel;
    }
}
$jsParsers = [];
foreach ($files as $rel => $meta) {
    if ($meta['ext']==='js' && preg_match('#/js/parsers/([^/]+)\.js$#', $rel, $m)) {
        $jsParsers[] = $rel;
    }
}

// output
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'root' => $root,
    'files_total' => count($files),
    'maybe_unused' => $maybeUnused,
    'php_importers' => $phpImporters,
    'js_parsers' => $jsParsers,
    'graph_sample' => array_slice($files, 0, 50, true), // zkrácená ukázka
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
