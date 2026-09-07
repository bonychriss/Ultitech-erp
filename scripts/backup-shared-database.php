<?php
declare(strict_types=1);

/**
 * Backup the shared application database (control / DATA_DB).
 *
 * Cron example (daily 02:15):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\public_html\scripts\backup-shared-database.php
 *   15 2 * * * /usr/bin/php /path/to/public_html/scripts/backup-shared-database.php
 *
 * Keeps the last 7 dumps under storage/shared-backups/.
 * Does not migrate tenants  protects the shared DB used by trial and paid companies.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';

$host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
$user = defined('DB_USER') ? (string) DB_USER : 'root';
$pass = defined('DB_PASS') ? (string) DB_PASS : '';
$mainDb = defined('DB_NAME') ? (string) DB_NAME : '';
$dataDb = defined('DATA_DB_NAME') ? trim((string) DATA_DB_NAME) : '';

$targets = [];
if ($mainDb !== '') {
    $targets[$mainDb] = $mainDb;
}
if ($dataDb !== '' && $dataDb !== $mainDb) {
    $targets[$dataDb] = $dataDb;
}
if ($targets === []) {
    fwrite(STDERR, "No DB_NAME / DATA_DB_NAME configured.\n");
    exit(1);
}

$outDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'shared-backups';
if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$mysqldump = null;
$candidates = [];
if (DIRECTORY_SEPARATOR === '\\') {
    $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $candidates[] = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe';
}
$candidates[] = 'mysqldump';
foreach ($candidates as $bin) {
    if ($bin !== 'mysqldump' && !is_file($bin)) {
        continue;
    }
    if ($bin === 'mysqldump') {
        $out = [];
        $code = 1;
        @exec('mysqldump --version 2>&1', $out, $code);
        if ($code !== 0) {
            continue;
        }
    }
    $mysqldump = $bin;
    break;
}
if ($mysqldump === null) {
    fwrite(STDERR, "mysqldump not found.\n");
    exit(1);
}

$stamp = date('Ymd_His');
$keep = 7;
$ok = 0;

foreach ($targets as $dbName) {
    $file = $outDir . DIRECTORY_SEPARATOR . 'shared_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $dbName) . '_' . $stamp . '.sql.gz';
    $cmd = escapeshellarg($mysqldump)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --single-transaction --routines --triggers --databases '
        . escapeshellarg($dbName);
    if ($pass !== '') {
        $cmd .= ' --password=' . escapeshellarg($pass);
    }

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start mysqldump for {$dbName}\n");
        continue;
    }
    fclose($pipes[0]);
    $sql = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_string($sql) || $sql === '') {
        fwrite(STDERR, "mysqldump failed for {$dbName}: {$err}\n");
        continue;
    }
    $gz = gzencode($sql, 6);
    if ($gz === false || file_put_contents($file, $gz) === false) {
        fwrite(STDERR, "Could not write {$file}\n");
        continue;
    }
    fwrite(STDOUT, "Wrote {$file} (" . strlen($gz) . " bytes)\n");
    $ok++;

    // Prune older dumps for this database name.
    $pattern = $outDir . DIRECTORY_SEPARATOR . 'shared_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $dbName) . '_*.sql.gz';
    $files = glob($pattern) ?: [];
    rsort($files, SORT_STRING);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

exit($ok > 0 ? 0 : 1);
