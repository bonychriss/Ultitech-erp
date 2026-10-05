<?php
/**
 * Read-only map of the ultimate.co.tz shop.
 *
 * Upload this one file to:
 *   /home/ultimate/public_html/ultitech/inspect.php
 *
 * Open it once, copy the report, then delete the file from cPanel.
 * It does not change files, does not change the database, and does not call ultitech.io.
 */
declare(strict_types=1);

const INSPECT_KEY = 'ugt7k-sync-4m2p';

$given = (string) ($_GET['key'] ?? '');
if ($given === '' || !hash_equals(INSPECT_KEY, $given)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function laravelRoot(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (is_file($dir . '/artisan') && is_file($dir . '/.env')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return '';
}

function rel(string $root, string $path): string
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (str_starts_with($path, $root)) {
        return ltrim(substr($path, strlen($root)), '/');
    }
    return $path;
}

function skipName(string $name): bool
{
    return in_array($name, [
        'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
    ], true);
}

/**
 * @return array{dirs:int,files:int,bytes:int,children:list<string>}
 */
function summarize(string $path, int $depth = 0): array
{
    $out = ['dirs' => 0, 'files' => 0, 'bytes' => 0, 'children' => []];
    if (!is_dir($path)) {
        return $out;
    }
    $items = @scandir($path);
    if (!is_array($items)) {
        $out['children'][] = '(unreadable)';
        return $out;
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $name;
        if (is_link($full)) {
            $out['children'][] = $name . ' -> link';
            continue;
        }
        if (is_dir($full)) {
            $out['dirs']++;
            if (in_array($name, ['vendor', 'node_modules', 'storage'], true)) {
                $out['children'][] = $name . '/ (not expanded)';
                continue;
            }
            $out['children'][] = $name . '/';
            if ($depth < 1) {
                $child = summarize($full, $depth + 1);
                $out['dirs'] += $child['dirs'];
                $out['files'] += $child['files'];
                $out['bytes'] += $child['bytes'];
            }
            continue;
        }
        if (is_file($full)) {
            $out['files']++;
            $out['bytes'] += (int) (@filesize($full) ?: 0);
            if ($depth === 0 && count($out['children']) < 80) {
                $out['children'][] = $name;
            }
        }
    }
    return $out;
}

function listFiles(string $dir, int $limit = 400): array
{
    if (!is_dir($dir)) {
        return ['(missing)'];
    }
    $names = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $names[] = str_replace('\\', '/', $file->getPathname());
        if (count($names) >= $limit) {
            $names[] = '... truncated at ' . $limit . ' files';
            break;
        }
    }
    sort($names);
    return $names;
}

function envKeyNames(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $keys = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return ['(unreadable)'];
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    sort($keys);
    return $keys;
}

function composerLine(string $root): string
{
    $file = $root . '/composer.json';
    if (!is_file($file)) {
        return '(composer.json missing)';
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        return '(composer.json is not JSON)';
    }
    $laravel = (string) ($json['require']['laravel/framework'] ?? '');
    $php = (string) ($json['require']['php'] ?? '');
    $name = (string) ($json['name'] ?? '');
    return trim($name . '  php ' . $php . '  laravel/framework ' . $laravel);
}

function frameworkVersion(string $root): string
{
    $file = $root . '/vendor/laravel/framework/composer.json';
    if (!is_file($file)) {
        return '(vendor laravel framework not found)';
    }
    $json = json_decode((string) file_get_contents($file), true);
    return is_array($json) ? (string) ($json['version'] ?? 'unknown') : 'unknown';
}

function routeHints(string $root): array
{
    $dir = $root . '/routes';
    if (!is_dir($dir)) {
        return ['(routes directory missing)'];
    }
    $hits = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $text = (string) file_get_contents($file);
        if (strlen($text) > 400000) {
            $hits[] = basename($file) . ' (too large to scan)';
            continue;
        }
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $n => $line) {
            if (preg_match('/quote|product|order|checkout|cart|admin/i', $line) && preg_match('/Route::|function\s+/', $line)) {
                $hits[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
            }
            if (count($hits) >= 180) {
                $hits[] = '... truncated';
                return $hits;
            }
        }
    }
    return $hits === [] ? ['(no matching route lines)'] : $hits;
}

function interestingPaths(string $root): array
{
    $paths = [
        'app/Http/Controllers',
        'app/Http/Controllers/Admin',
        'app/Models',
        'app/Console',
        'database/migrations',
        'resources/views/backend',
        'resources/views/frontend',
        'resources/views/frontend/product_details',
        'app/Http/Helpers',
    ];
    $lines = [];
    foreach ($paths as $relPath) {
        $full = $root . '/' . $relPath;
        $lines[] = '## ' . $relPath;
        if (!is_dir($full)) {
            $lines[] = '(missing)';
            continue;
        }
        foreach (listFiles($full, 250) as $file) {
            $lines[] = rel($root, $file);
        }
    }
    return $lines;
}

function shopPdo(string $root): PDO
{
    $keys = [];
    $lines = file($root . '/.env', FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Could not read .env');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            $v = substr($v, 1, -1);
        }
        $keys[trim($k)] = $v;
    }
    $name = $keys['DB_DATABASE'] ?? '';
    $user = $keys['DB_USERNAME'] ?? '';
    $pass = $keys['DB_PASSWORD'] ?? '';
    $host = $keys['DB_HOST'] ?? 'localhost';
    $port = $keys['DB_PORT'] ?? '3306';
    if ($name === '' || $user === '') {
        throw new RuntimeException('Database name or user is missing');
    }
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function tableReport(PDO $pdo): array
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tables = array_map('strval', $tables);
    sort($tables);
    $lines = ['## all tables (' . count($tables) . ')'];
    foreach ($tables as $table) {
        $lines[] = $table;
    }
    $needles = ['product', 'categor', 'order', 'stock', 'cart', 'quote', 'payment', 'upload', 'user', 'notif', 'business_setting', 'ultitech', 'coupon'];
    $lines[] = '';
    $lines[] = '## columns for shop tables';
    foreach ($tables as $table) {
        $low = strtolower($table);
        $match = false;
        foreach ($needles as $needle) {
            if (str_contains($low, $needle)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }
        $lines[] = '';
        $lines[] = '### ' . $table;
        $cols = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll();
        foreach ($cols as $col) {
            $lines[] = (string) $col['Field'] . '  ' . (string) $col['Type'] . '  ' . (string) ($col['Null'] ?? '') . '  ' . (string) ($col['Key'] ?? '');
        }
    }
    return $lines;
}

$root = laravelRoot();
$parts = [];
$parts[] = 'Ultimate shop structure';
$parts[] = 'Generated ' . date('c');
$parts[] = 'PHP ' . PHP_VERSION;
$parts[] = 'This file: ' . __FILE__;
$parts[] = 'Laravel root: ' . ($root !== '' ? $root : '(not found)');
if ($root === '') {
    $parts[] = 'Upload this file inside public_html/ultitech/, next to the website that contains artisan and .env.';
} else {
    $parts[] = '';
    $parts[] = '## versions';
    $parts[] = composerLine($root);
    $parts[] = 'Installed framework: ' . frameworkVersion($root);
    $parts[] = '';
    $parts[] = '## .env key names only';
    foreach (envKeyNames($root . '/.env') as $key) {
        $parts[] = $key;
    }
    $parts[] = '';
    $parts[] = '## top of the project';
    $top = summarize($root, 0);
    $parts[] = 'files at top=' . $top['files'] . '  nested dirs counted one level=' . $top['dirs'];
    foreach ($top['children'] as $child) {
        $parts[] = $child;
    }
    $parts[] = '';
    $parts[] = '## ultitech folder';
    foreach (summarize(__DIR__, 0)['children'] as $child) {
        $parts[] = $child;
    }
    foreach (interestingPaths($root) as $line) {
        $parts[] = $line;
    }
    $parts[] = '';
    $parts[] = '## route hints';
    foreach (routeHints($root) as $line) {
        $parts[] = $line;
    }
    $kernel = $root . '/app/Console/Kernel.php';
    $parts[] = '';
    $parts[] = '## scheduler file';
    $parts[] = is_file($kernel) ? 'app/Console/Kernel.php exists' : 'app/Console/Kernel.php missing';
    if (is_file($kernel)) {
        $text = (string) file_get_contents($kernel);
        if (preg_match('/function schedule\([\s\S]{0,2500}\n    \}/', $text, $m)) {
            $parts[] = trim($m[0]);
        }
    }
    try {
        $pdo = shopPdo($root);
        $parts[] = '';
        foreach (tableReport($pdo) as $line) {
            $parts[] = $line;
        }
    } catch (Throwable $e) {
        $parts[] = '';
        $parts[] = '## database';
        $parts[] = 'Could not read table names: ' . $e->getMessage();
    }
}

$body = implode("\n", $parts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ultimate shop structure</title>
<style>
  body { margin: 0; font-family: Georgia, serif; background: #f6f3ee; color: #1c1917; }
  main { max-width: 980px; margin: 0 auto; padding: 28px 16px 64px; }
  pre { white-space: pre-wrap; word-break: break-word; background: #fff; border: 1px solid #e7e0d6; border-radius: 12px; padding: 16px; font-family: Consolas, monospace; font-size: 13px; line-height: 1.45; }
</style>
</head>
<body>
<main>
  <h1>Ultimate shop structure</h1>
  <p>Read only. Delete <code>inspect.php</code> from cPanel after you copy this report.</p>
  <pre><?= h($body) ?></pre>
</main>
</body>
</html>
