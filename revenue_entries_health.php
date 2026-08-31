<?php
/**
 * Upload and open while logged in to diagnose revenue_entries HTTP 500.
 * Example: https://ultitech.io/ultimate/revenue_entries_health.php
 * Delete this file after debugging.
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

$steps = array();

function ren_health_step($label, $ok, $detail = '', $expected = false)
{
    global $steps;
    $steps[] = array('label' => $label, 'ok' => $ok, 'detail' => $detail, 'expected' => $expected);
}

try {
    require_once __DIR__ . '/includes/functions.php';
    ren_health_step('includes/functions.php', true);
} catch (Throwable $e) {
    ren_health_step('includes/functions.php', false, $e->getMessage());
    goto render;
}

ren_health_step('revenue_ledger.php exists', is_file(__DIR__ . '/includes/revenue_ledger.php'));
ren_health_step('revenue_sync.php exists', is_file(__DIR__ . '/includes/revenue_sync.php'));

try {
    require_once __DIR__ . '/includes/revenue_ledger.php';
    ren_health_step('includes/revenue_ledger.php load', true);
} catch (Throwable $e) {
    ren_health_step('includes/revenue_ledger.php load', false, $e->getMessage());
}

global $pdo, $control_pdo;
foreach (array('pdo' => $pdo, 'control_pdo' => $control_pdo) as $name => $conn) {
    if (!($conn instanceof PDO)) {
        ren_health_step($name . ' connected', false, 'not a PDO instance');
        continue;
    }
    try {
        $db = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
        $hasRe = function_exists('revenue_connection_has_revenue_entries')
            ? revenue_connection_has_revenue_entries($conn)
            : false;
        if ($hasRe) {
            $cnt = (int) $conn->query('SELECT COUNT(*) FROM revenue_entries')->fetchColumn();
            ren_health_step($name . ' revenue_entries', true, 'db=' . $db . ' rows=' . $cnt);
        } else {
            $isControl = ($name === 'control_pdo');
            ren_health_step(
                $name . ' revenue_entries',
                $isControl,
                'db=' . $db . ' — control DB has no revenue_entries (expected)',
                $isControl
            );
        }
    } catch (Throwable $e) {
        ren_health_step($name . ' query', false, $e->getMessage());
    }
}

if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
    ren_health_step('DATA_DB_NAME constant', true, DATA_DB_NAME);
} else {
    $resolved = function_exists('revenue_resolve_data_db_name') ? revenue_resolve_data_db_name() : '';
    ren_health_step(
        'DATA_DB_NAME in env.php',
        false,
        $resolved !== '' ? 'Add to live env.php: $DATA_DB_NAME = \'' . $resolved . '\';' : 'Not in env.php — add $DATA_DB_NAME = \'new_trading_voucher-35313030c7e2\';'
    );
}

if (function_exists('revenue_resolve_pdo')) {
    $resolvedPdo = revenue_resolve_pdo();
    if ($resolvedPdo instanceof PDO) {
        try {
            $db = (string) $resolvedPdo->query('SELECT DATABASE()')->fetchColumn();
            $cnt = (int) $resolvedPdo->query('SELECT COUNT(*) FROM revenue_entries')->fetchColumn();
            ren_health_step('revenue_resolve_pdo()', true, 'db=' . $db . ' rows=' . $cnt);
        } catch (Throwable $e) {
            ren_health_step('revenue_resolve_pdo()', false, $e->getMessage());
        }
    } else {
        ren_health_step('revenue_resolve_pdo()', false, 'could not connect to tenant revenue DB');
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Revenue entries health</title>
    <style>
        body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 800px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; vertical-align: top; }
        .ok { color: #15803d; }
        .fail { color: #b91c1c; }
        .expected { color: #64748b; }
        .note { background: #f0f9ff; border: 1px solid #bae6fd; padding: 12px; border-radius: 8px; margin: 1rem 0; }
    </style>
</head>
<body>
    <h1>Revenue entries health check</h1>
    <div class="note">
        <strong>Your data is on the tenant DB</strong> (<code>new_trading_voucher-35313030c7e2</code>, 187 rows).
        The control DB (<code>ultimate_trading-35313030f83f</code>) not having <code>revenue_entries</code> is normal.
    </div>
    <table>
        <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($steps as $s):
            $cls = !empty($s['expected']) ? 'expected' : ($s['ok'] ? 'ok' : 'fail');
            $label = $s['ok'] ? 'OK' : (!empty($s['expected']) ? 'OK (expected)' : 'FAIL');
        ?>
            <tr>
                <td><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="<?= $cls ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $s['detail'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><a href="revenue_entries.php?module=revenue">Try revenue_entries.php</a></p>
</body>
</html>
