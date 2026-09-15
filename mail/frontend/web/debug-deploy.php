<?php
/**
 * Temporary deploy diagnostics.
 * Open: https://ultimate.co.tz/staff/mail/frontend/web/debug-deploy.php
 * DELETE this file after fixing.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$ok = static function (bool $cond): string {
    return $cond ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>';
};

$webRoot = __DIR__;
$appIndex = $webRoot . '/app/index.html';
$projectRoot = dirname(__DIR__, 2);
$vendor = $projectRoot . '/vendor/autoload.php';
$mainLocal = $projectRoot . '/common/config/main-local.php';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$detectedBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$expectedAssetPrefix = $detectedBase . '/app/assets/';

$checks = [];
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
$checks[] = ['This file path', $webRoot, is_dir($webRoot)];
$checks[] = ['project root (vendor parent)', $projectRoot, is_dir($projectRoot)];
$checks[] = ['vendor/autoload.php', $vendor, is_file($vendor)];
$checks[] = ['common/config/main-local.php', $mainLocal, is_file($mainLocal)];
$checks[] = ['frontend/web/index.php', $webRoot . '/index.php', is_file($webRoot . '/index.php')];
$checks[] = ['frontend/web/.htaccess', $webRoot . '/.htaccess', is_file($webRoot . '/.htaccess')];
$checks[] = ['React app/index.html', $appIndex, is_file($appIndex)];
$checks[] = ['app/ directory', $webRoot . '/app', is_dir($webRoot . '/app')];

$indexHtml = is_file($appIndex) ? (string) file_get_contents($appIndex) : '';
preg_match_all('/(?:src|href)="([^"]+)"/i', $indexHtml, $m);
$assetUrls = array_values(array_filter($m[1] ?? [], static fn($u) => str_contains($u, '/assets/')));

$assetDiskOk = true;
$assetRows = [];
foreach ($assetUrls as $url) {
    $pathPart = parse_url($url, PHP_URL_PATH) ?: $url;
    if (preg_match('#/app/(assets/.+)$#', $pathPart, $mm)) {
        $disk = $webRoot . '/app/' . $mm[1];
    } else {
        $disk = $webRoot . $pathPart;
    }
    $exists = is_file($disk);
    if (!$exists) {
        $assetDiskOk = false;
    }
    $assetRows[] = [
        'url' => $url,
        'disk' => $disk,
        'exists' => $exists,
        'path_matches_request' => str_starts_with($pathPart, $detectedBase . '/app/'),
    ];
}

$dbInfo = ['status' => 'skipped', 'detail' => 'vendor or main-local missing'];
$yiiInfo = ['status' => 'skipped', 'detail' => 'vendor or main-local missing'];
$indexPhpEnv = ['YII_DEBUG' => '?', 'YII_ENV' => '?'];

if (is_file($webRoot . '/index.php')) {
    $indexSrc = (string) file_get_contents($webRoot . '/index.php');
    if (preg_match("/YII_DEBUG['\"]?\s*,\s*(true|false)/", $indexSrc, $mm)) {
        $indexPhpEnv['YII_DEBUG'] = $mm[1];
    }
    if (preg_match("/YII_ENV['\"]?\s*,\s*'([^']+)'/", $indexSrc, $mm)) {
        $indexPhpEnv['YII_ENV'] = $mm[1];
    }
}

if (is_file($vendor) && is_file($mainLocal)) {
    try {
        require $vendor;
        $cfg = require $mainLocal;
        $db = $cfg['components']['db'] ?? null;
        if (is_array($db)) {
            $dsn = (string) ($db['dsn'] ?? '');
            $user = (string) ($db['username'] ?? '');
            $pass = (string) ($db['password'] ?? '');
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $dbInfo = [
                'status' => 'ok',
                'detail' => 'Connected to `' . $name . '` (' . count($tables) . ' tables). User=' . $user . ' DSN=' . $dsn,
            ];
        } else {
            $dbInfo = ['status' => 'fail', 'detail' => 'No db component in main-local.php'];
        }
    } catch (Throwable $e) {
        $dbInfo = ['status' => 'fail', 'detail' => $e->getMessage()];
    }

    try {
        defined('YII_DEBUG') or define('YII_DEBUG', true);
        defined('YII_ENV') or define('YII_ENV', 'dev');
        require $projectRoot . '/vendor/yiisoft/yii2/Yii.php';
        require $projectRoot . '/common/config/bootstrap.php';
        require dirname(__DIR__) . '/config/bootstrap.php';
        $config = yii\helpers\ArrayHelper::merge(
            require $projectRoot . '/common/config/main.php',
            require $projectRoot . '/common/config/main-local.php',
            require dirname(__DIR__) . '/config/main.php',
            require dirname(__DIR__) . '/config/main-local.php',
        );
        new yii\web\Application($config);
        $yiiInfo = [
            'status' => 'ok',
            'detail' => 'Yii boots. baseUrl=' . Yii::$app->request->baseUrl
                . ' scriptUrl=' . Yii::$app->request->scriptUrl,
        ];
    } catch (Throwable $e) {
        $yiiInfo = ['status' => 'fail', 'detail' => $e->getMessage()];
    }
}

$appFiles = [];
$assetsDir = $webRoot . '/app/assets';
if (is_dir($assetsDir)) {
    foreach (scandir($assetsDir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $appFiles[] = $f . ' (' . filesize($assetsDir . '/' . $f) . ' bytes)';
    }
}

$pathMismatch = false;
foreach ($assetRows as $row) {
    if (!$row['path_matches_request'] || !$row['exists']) {
        $pathMismatch = true;
        break;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Mail deploy debug</title>
  <style>
    body { font: 14px/1.45 system-ui, sans-serif; margin: 24px; color: #111; background: #f6f7f9; }
    h1 { margin: 0 0 8px; font-size: 20px; }
    .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 14px 16px; margin: 12px 0; }
    .ok { color: #0a7a2f; font-weight: 700; }
    .bad { color: #b00020; font-weight: 700; }
    .warn { color: #9a6700; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
    code, pre { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
    pre { white-space: pre-wrap; background: #f0f2f5; padding: 10px; border-radius: 6px; overflow: auto; }
    .banner { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
    .banner.bad { background: #fde8ec; }
    .banner.ok { background: #e6f6eb; }
    .banner.warn { background: #fff6e0; }
  </style>
</head>
<body>
  <h1>Mail deploy debug</h1>
  <p>Delete <code>frontend/web/debug-deploy.php</code> after you finish.</p>

  <?php if (!$assetDiskOk || $pathMismatch || !is_file($appIndex)): ?>
    <div class="banner bad">Likely cause of blank page: React asset paths missing or wrong base URL.</div>
  <?php elseif (($dbInfo['status'] ?? '') === 'fail'): ?>
    <div class="banner warn">UI files look OK, but database connection failed — login/API will break.</div>
  <?php else: ?>
    <div class="banner ok">Core files look present. If the app is still blank, check the browser Network tab for blocked JS/CSS.</div>
  <?php endif; ?>

  <div class="box">
    <h2>Request</h2>
    <table>
      <tr><th>REQUEST_URI</th><td><code><?= htmlspecialchars($requestUri) ?></code></td></tr>
      <tr><th>SCRIPT_NAME</th><td><code><?= htmlspecialchars($scriptName) ?></code></td></tr>
      <tr><th>Detected web base</th><td><code><?= htmlspecialchars($detectedBase) ?></code></td></tr>
      <tr><th>Expected asset prefix</th><td><code><?= htmlspecialchars($expectedAssetPrefix) ?></code></td></tr>
      <tr><th>HTTP_HOST</th><td><code><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></code></td></tr>
      <tr><th>DOCUMENT_ROOT</th><td><code><?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '') ?></code></td></tr>
    </table>
  </div>

  <div class="box">
    <h2>Filesystem</h2>
    <table>
      <tr><th>Check</th><th>Status</th><th>Value</th></tr>
      <?php foreach ($checks as [$label, $value, $pass]): ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td><?= $ok($pass) ?></td>
          <td><code><?= htmlspecialchars((string) $value) ?></code></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td>index.php YII_ENV / YII_DEBUG</td>
        <td><?= $ok(($indexPhpEnv['YII_ENV'] ?? '') === 'prod') ?></td>
        <td><code>ENV=<?= htmlspecialchars($indexPhpEnv['YII_ENV']) ?> DEBUG=<?= htmlspecialchars($indexPhpEnv['YII_DEBUG']) ?></code>
          <?= ($indexPhpEnv['YII_ENV'] !== 'prod') ? '<span class="warn"> — use prod index.php on live</span>' : '' ?>
        </td>
      </tr>
    </table>
  </div>

  <div class="box">
    <h2>React index.html asset tags</h2>
    <?php if ($indexHtml === ''): ?>
      <p class="bad">app/index.html missing — run <code>npm run build:live</code> and upload <code>frontend/web/app/</code>.</p>
    <?php else: ?>
      <pre><?= htmlspecialchars($indexHtml) ?></pre>
      <table>
        <tr><th>Asset URL in HTML</th><th>Matches this site path?</th><th>File on disk?</th><th>Disk path</th></tr>
        <?php foreach ($assetRows as $row): ?>
          <tr>
            <td><code><?= htmlspecialchars($row['url']) ?></code></td>
            <td><?= $ok($row['path_matches_request']) ?></td>
            <td><?= $ok($row['exists']) ?></td>
            <td><code><?= htmlspecialchars($row['disk']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p>Files in <code>app/assets/</code>:</p>
      <pre><?= htmlspecialchars($appFiles ? implode("\n", $appFiles) : '(empty)') ?></pre>
    <?php endif; ?>
  </div>

  <div class="box">
    <h2>Live asset HTTP check (from browser)</h2>
    <ul id="http-checks"><li>Running…</li></ul>
  </div>

  <div class="box">
    <h2>Database</h2>
    <p><?= ($dbInfo['status'] === 'ok') ? $ok(true) : $ok(false) ?>
      <code><?= htmlspecialchars($dbInfo['detail']) ?></code></p>
  </div>

  <div class="box">
    <h2>Yii bootstrap</h2>
    <p><?= ($yiiInfo['status'] === 'ok') ? $ok(true) : $ok(false) ?>
      <code><?= htmlspecialchars($yiiInfo['detail']) ?></code></p>
  </div>

  <div class="box">
    <h2>Quick links</h2>
    <ul>
      <li><a href="<?= htmlspecialchars($detectedBase . '/') ?>"><?= htmlspecialchars($detectedBase . '/') ?></a></li>
      <li><a href="<?= htmlspecialchars($detectedBase . '/app/') ?>"><?= htmlspecialchars($detectedBase . '/app/') ?></a></li>
      <li><a href="<?= htmlspecialchars($detectedBase . '/api/bootstrap') ?>" target="_blank" rel="noopener">pretty: <?= htmlspecialchars($detectedBase . '/api/bootstrap') ?></a></li>
      <li><a href="<?= htmlspecialchars($detectedBase . '/index.php/api/bootstrap') ?>" target="_blank" rel="noopener">via index.php: <?= htmlspecialchars($detectedBase . '/index.php/api/bootstrap') ?></a> (should return JSON)</li>
    </ul>
  </div>

  <script>
    (async () => {
      const ul = document.getElementById('http-checks');
      ul.innerHTML = '';
      const urls = <?= json_encode(array_values($assetUrls), JSON_UNESCAPED_SLASHES) ?>;
      if (!urls.length) {
        ul.innerHTML = '<li class="bad">No asset URLs found in index.html</li>';
        return;
      }
      for (const url of urls) {
        const li = document.createElement('li');
        try {
          const res = await fetch(url, { method: 'GET', cache: 'no-store' });
          li.innerHTML = (res.ok ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>')
            + ' <code>' + url + '</code> → HTTP ' + res.status
            + (res.redirected ? ' (redirected)' : '');
        } catch (e) {
          li.innerHTML = '<span class="bad">FAIL</span> <code>' + url + '</code> → ' + e;
        }
        ul.appendChild(li);
      }
    })();
  </script>
</body>
</html>
