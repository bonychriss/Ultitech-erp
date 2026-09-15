<?php
/**
 * One-time DB setup for live hosting (no SSH required).
 *
 * Open: https://ultimate.co.tz/staff/mail/frontend/web/migrate-web.php?key=mail-setup-2026
 * DELETE this file immediately after a successful run.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$key = $_GET['key'] ?? '';
if ($key !== 'mail-setup-2026') {
    http_response_code(403);
    echo "Forbidden. Use ?key=mail-setup-2026\n";
    exit;
}

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'prod');

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
require $root . '/console/config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php',
    require $root . '/common/config/main-local.php',
    require $root . '/console/config/main.php',
    require $root . '/console/config/main-local.php',
);

// Console Request has no baseUrl/scriptUrl (those are web-only)
unset(
    $config['components']['request']['baseUrl'],
    $config['components']['request']['scriptUrl'],
    $config['components']['request']['cookieValidationKey'],
    $config['components']['urlManager'],
);

try {
    $app = new yii\console\Application($config);
    echo "DB: " . Yii::$app->db->dsn . "\n";
    echo "User: " . Yii::$app->db->username . "\n\n";
    echo "Running migrations...\n\n";

    ob_start();
    $code = $app->runAction('migrate/up', ['interactive' => false]);
    $out = ob_get_clean();
    echo $out !== '' ? $out : "(no console output captured)\n";
    echo "\nExit code: {$code}\n";

    $tables = Yii::$app->db->createCommand('SHOW TABLES')->queryColumn();
    echo "\nTables now (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo " - {$t}\n";
    }

    echo "\nDONE. Delete migrate-web.php from the server now.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
