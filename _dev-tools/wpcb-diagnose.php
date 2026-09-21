<?php
/**
 * Rebuzz Backup & Restore - standalone restore diagnostic.
 *
 * Answers one question: why does extracting this backup fail?
 * Read-only apart from a temp scratch dir it cleans up after itself.
 *
 * CLI : php wpcb-diagnose.php /full/path/to/backup.zip
 * WEB : upload beside wp-load.php, visit it, then DELETE IT.
 *
 * DELETE THIS FILE WHEN DONE - it reports server paths.
 */

$cli = (PHP_SAPI === 'cli');

if (!$cli) {
    require_once __DIR__ . '/wp-load.php';
    if (!current_user_can('manage_options')) {
        http_response_code(403);
        exit('Administrator login required.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function out($fmt, ...$a) { echo $a ? vsprintf($fmt, $a) : $fmt, "\n"; }
function head($t) { out("\n=== %s ===", $t); }

// ---------------------------------------------------------------- archive
$zipPath = null;

if ($cli && isset($argv[1])) {
    $zipPath = $argv[1];
} else {
    // Newest backup in the plugin's data dir.
    $up  = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
    $dir = $up ? $up['basedir'] . '/rebuzz-backup-and-restore' : __DIR__;
    // Backups may sit directly in the data dir or in its backups/ subfolder.
    $found = array_merge(
        glob($dir . '/*.zip') ?: [],
        glob($dir . '/backups/*.zip') ?: []
    );
    usort($found, function ($a, $b) { return filemtime($b) - filemtime($a); });
    $zipPath = $found ? $found[0] : null;
}

if (!$zipPath || !file_exists($zipPath)) {
    exit("No backup ZIP found. Pass one as an argument.\n");
}

// ------------------------------------------------------------ environment
head('Environment');
out('PHP            : %s (%s)', PHP_VERSION, PHP_SAPI);
out('OS             : %s %s', PHP_OS, php_uname('r'));
out('ZipArchive     : %s', class_exists('ZipArchive') ? 'yes' : 'NO - fatal');
if (defined('ZipArchive::LIBZIP_VERSION')) {
    out('libzip         : %s', ZipArchive::LIBZIP_VERSION);
}
out('memory_limit   : %s', ini_get('memory_limit'));
out('max_execution  : %s', ini_get('max_execution_time'));
out('open_basedir   : %s', ini_get('open_basedir') ?: '(none)');
out('disable_funcs  : %s', ini_get('disable_functions') ?: '(none)');

$dest = dirname($zipPath) . '/wpcb-diag-' . uniqid();
@mkdir($dest, 0777, true);

$free = @disk_free_space($dest);
out('free on volume : %s', $free === false ? 'unmeasurable' : number_format($free / 1048576, 1) . ' MB');
out('dest writable  : %s', is_writable($dest) ? 'yes' : 'NO');

// ---------------------------------------------------------------- archive
head('Archive');
out('path           : %s', $zipPath);
out('size           : %s bytes', number_format(filesize($zipPath)));

$zip = new ZipArchive();
$rc  = $zip->open($zipPath);

if ($rc !== true) {
    out('open()         : FAILED (code %d)', $rc);
    @rmdir($dest);
    exit(1);
}

out('open()         : ok');
out('entries        : %d', $zip->numFiles);

// Strict consistency: catches a central directory whose local-header
// offsets don't line up with the actual local headers - the archive
// still opens normally, so only this check sees it.
$strict = new ZipArchive();
$srC = $strict->open($zipPath, ZipArchive::CHECKCONS);

if ($srC === true) {
    out('strict check   : PASSED');
    $strict->close();
} else {
    out('strict check   : FAILED (code %d)%s', $srC,
        $srC == 21 || $srC == 19 ? '  <-- ARCHIVE IS MALFORMED' : '');
}

// ------------------------------------------------------- full entry sweep
head('Entry sweep (reading every entry, verifying CRC)');

$bad = [];
$longest = ['len' => 0, 'name' => ''];
$checked = 0;
$destLen = strlen($dest) + 1;

for ($i = 0; $i < $zip->numFiles; $i++) {

    $name = $zip->getNameIndex($i);
    if ($name === false) { $bad[] = [$i, '(unreadable name)', 'getNameIndex() failed']; continue; }

    $full = $destLen + strlen($name);
    if ($full > $longest['len']) { $longest = ['len' => $full, 'name' => $name]; }

    if (substr($name, -1) === '/') { continue; }   // directory entry

    $stat = $zip->statIndex($i);
    if ($stat === false) { $bad[] = [$i, $name, 'statIndex() failed']; continue; }

    error_clear_last();
    $fh = @$zip->getStream($name);

    if (!$fh) {
        $e = error_get_last();
        $bad[] = [$i, $name, 'getStream() failed: ' . ($e['message'] ?? $zip->getStatusString())];
        continue;
    }

    $read = 0;
    $crc  = hash_init('crc32b');
    while (!feof($fh)) {
        $c = @fread($fh, 262144);
        if ($c === false) { break; }
        $read += strlen($c);
        hash_update($crc, $c);
    }
    fclose($fh);

    if ($read !== (int) $stat['size']) {
        $bad[] = [$i, $name, sprintf('short read: got %d of %d bytes', $read, $stat['size'])];
        continue;
    }

    if (hexdec(hash_final($crc)) !== (int) $stat['crc']) {
        $bad[] = [$i, $name, 'CRC mismatch - entry data is damaged'];
        continue;
    }

    $checked++;
}

out('entries verified OK : %d', $checked);
out('entries failing     : %d', count($bad));

if ($bad) {
    head('Failing entries');
    foreach (array_slice($bad, 0, 40) as $b) {
        out('#%-6d %s', $b[0], $b[1]);
        out('        -> %s', $b[2]);
    }
    if (count($bad) > 40) { out('... and %d more', count($bad) - 40); }
}

// ------------------------------------------------------------ path length
head('Path length');
out('workspace prefix    : %d chars', $destLen);
out('longest full path   : %d chars', $longest['len']);
out('  %s', $longest['name']);
$limit = (stripos(PHP_OS, 'WIN') === 0) ? 260 : 4096;
out('platform limit      : %d', $limit);
out('verdict             : %s', $longest['len'] >= $limit ? 'OVER THE LIMIT - this is your cause' : 'within limits');

// --------------------------------------------------------- real write test
head('Live write test (extracting the deepest entry for real)');

$target = $longest['name'];
error_clear_last();

if (@$zip->extractTo($dest, [$target])) {
    out('extractTo() : ok - the destination accepts this path');
} else {
    $e = error_get_last();
    out('extractTo() : FAILED');
    out('  php error : %s', $e['message'] ?? '(none reported)');
    out('  zip status: %s', $zip->getStatusString());
}

$zip->close();

// cleanup
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) { $f->isDir() ? @rmdir($f) : @unlink($f); }
@rmdir($dest);

head('Done');
out('If "entries failing" is 0 and path length is within limits,');
out('the archive is sound and the fault is environmental.');
out('DELETE THIS FILE NOW.');
