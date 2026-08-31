<?php
/**
 * Debug applicant / approval assignee mismatch on payment vouchers.
 * DELETE after troubleshooting.
 *
 * https://ultitech.io/roadmaster/debug_voucher_applicant.php?key=ultitech-debug&id=34
 * https://ultitech.io/debug_voucher_applicant.php?key=ultitech-debug&company_slug=roadmaster&id=34
 * Apply fix:  &fix=1
 * Plain text:  &format=text
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

const DVA_VERSION = '1.0';
const DVA_KEY = 'ultitech-debug';

$dvaReqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#^/home/sites/#i', $dvaReqPath)) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'ultitech.io');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $qs = $_GET;
    if (!isset($qs['key'])) {
        $qs['key'] = DVA_KEY;
    }
    header('Location: ' . $scheme . '://' . $host . '/debug_voucher_applicant.php?' . http_build_query($qs), true, 302);
    exit;
}

if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}

$dvaKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$dvaExpected = DVA_KEY;
foreach ([__DIR__ . '/env.php', __DIR__ . '/includes/env.php', __DIR__ . '/env.local.php'] as $ep) {
    if (!is_file($ep)) {
        continue;
    }
    $DEBUG_KEY = $DEBUG_KEY ?? null;
    include $ep;
    if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
        $dvaExpected = trim((string) $DEBUG_KEY);
        break;
    }
}
if (PHP_SAPI !== 'cli' && ($dvaKey === '' || !hash_equals($dvaExpected, $dvaKey))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Use ?key=" . DVA_KEY . "\n";
    exit;
}

$dvaCompanySlug = isset($_GET['company_slug']) ? strtolower(trim((string) $_GET['company_slug'])) : 'roadmaster';
if ($dvaCompanySlug === '') {
    $dvaCompanySlug = 'roadmaster';
}
$_GET['company_slug'] = $dvaCompanySlug;
$_GET['module'] = isset($_GET['module']) ? (string) $_GET['module'] : 'voucher';

$voucherId = max(0, (int) ($_GET['id'] ?? 34));
$doFix = isset($_GET['fix']) && (string) $_GET['fix'] === '1';
$asText = in_array(strtolower((string) ($_GET['format'] ?? '')), ['text', 'plain'], true);

$rows = [];

function dva_row($section, $label, $value, $status = 'info')
{
    global $rows;
    $rows[] = [
        'section' => $section,
        'label' => $label,
        'value' => $value,
        'status' => $status,
    ];
}

function dva_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function dva_norm_role($role)
{
    $r = strtolower(trim((string) $role));
    $aliases = [
        'gm' => 'general manager',
        'dept manager' => 'department manager',
        'checker' => 'checked by',
        'check' => 'checked by',
    ];
    return $aliases[$r] ?? $r;
}

require_once __DIR__ . '/includes/functions.php';

global $pdo;
if (!($pdo instanceof PDO)) {
    dva_row('bootstrap', 'PDO', 'Not available', 'fail');
} else {
    try {
        dva_row('bootstrap', 'Database', (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'ok');
    } catch (Throwable $e) {
        dva_row('bootstrap', 'Database', $e->getMessage(), 'fail');
    }
    dva_row('bootstrap', 'company_slug', $dvaCompanySlug, 'info');
    dva_row('bootstrap', 'currentCompanyId', function_exists('currentCompanyId') ? (string) currentCompanyId() : '(n/a)', 'info');
    dva_row('bootstrap', 'script version', DVA_VERSION, 'info');
}

$codeFiles = [
    'includes/voucher-approval-flow-data.php',
    'includes/functions.php',
    'edit-voucher.php',
];
foreach ($codeFiles as $rel) {
    $path = __DIR__ . '/' . $rel;
    if (!is_file($path)) {
        dva_row('deploy', $rel, 'MISSING', 'fail');
        continue;
    }
    $src = (string) file_get_contents($path);
    $hasSync = strpos($src, 'syncVoucherApprovalAssignees') !== false;
    $hasRepair = strpos($src, 'needsApprovalSync') !== false || strpos($src, 'payment_vouchers is the source of truth') !== false;
    $detail = date('Y-m-d H:i:s', (int) filemtime($path)) . ' | ' . (int) filesize($path) . ' bytes';
    if ($rel === 'includes/voucher-approval-flow-data.php') {
        $detail .= $hasRepair ? ' | auto-repair: YES' : ' | auto-repair: NO (old file — deploy latest)';
    }
    if ($rel === 'edit-voucher.php') {
        $detail .= $hasSync ? ' | sync on edit: YES' : ' | sync on edit: NO (old file — deploy latest)';
    }
    if ($rel === 'includes/functions.php') {
        $detail .= strpos($src, 'fetchAll(PDO::FETCH_ASSOC)') !== false && strpos($src, 'syncVoucherApprovalAssignees') !== false
            ? ' | sync all role rows: likely YES'
            : ' | sync all role rows: check manually';
    }
    dva_row('deploy', $rel, $detail, ($hasRepair || $hasSync || $rel === 'includes/functions.php') ? 'ok' : 'warn');
}

$voucher = null;
if ($pdo instanceof PDO && $voucherId > 0) {
    try {
        $st = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
        $st->execute([$voucherId]);
        $voucher = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        dva_row('voucher', 'load error', $e->getMessage(), 'fail');
    }
}

if (!$voucher) {
    dva_row('voucher', 'id=' . $voucherId, 'NOT FOUND', 'fail');
} else {
    dva_row('voucher', 'voucher_no', (string) ($voucher['voucher_no'] ?? ''), 'ok');
    dva_row('voucher', 'status', (string) ($voucher['status'] ?? ''), 'info');
    dva_row('voucher', 'prepared_by', (string) ($voucher['prepared_by'] ?? ''), 'info');
    dva_row('voucher', 'created_by', (string) ($voucher['created_by'] ?? ''), 'info');

    $pvRoles = [
        'applicant' => trim((string) ($voucher['applicant'] ?? '')),
        'department manager' => trim((string) ($voucher['department_manager'] ?? '')),
        'checked by' => trim((string) ($voucher['checked_by'] ?? '')),
        'general manager' => trim((string) ($voucher['general_manager'] ?? '')),
    ];
    foreach ($pvRoles as $rk => $name) {
        dva_row('payment_vouchers', $rk, $name !== '' ? $name : '(empty)', $name !== '' ? 'ok' : 'warn');
        if ($name !== '' && function_exists('resolveVoucherUserIdByDisplayName')) {
            $uid = (int) resolveVoucherUserIdByDisplayName($pdo, $name);
            dva_row('user lookup', $rk . ' ? user id', $uid > 0 ? (string) $uid : 'NOT FOUND in users table', $uid > 0 ? 'ok' : 'warn');
        }
    }
}

$approvals = [];
if ($pdo instanceof PDO && $voucherId > 0) {
    try {
        $st = $pdo->prepare('SELECT id, voucher_id, approver_id, approver_name, role, status, approved_at, created_at, company_id FROM approvals WHERE voucher_id = ? ORDER BY id ASC');
        $st->execute([$voucherId]);
        $approvals = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        dva_row('approvals', 'load error', $e->getMessage(), 'fail');
    }
}

dva_row('approvals', 'row count', (string) count($approvals), 'info');

$byRole = [];
foreach ($approvals as $ar) {
    $rk = dva_norm_role($ar['role'] ?? '');
    if ($rk === '') {
        continue;
    }
    if (!isset($byRole[$rk])) {
        $byRole[$rk] = [];
    }
    $byRole[$rk][] = $ar;
    dva_row(
        'approvals row',
        'id=' . ($ar['id'] ?? '?') . ' [' . ($ar['role'] ?? '') . ']',
        sprintf(
            'name=%s | approver_id=%s | status=%s | company_id=%s',
            (string) ($ar['approver_name'] ?? ''),
            (string) ($ar['approver_id'] ?? 'null'),
            (string) ($ar['status'] ?? ''),
            (string) ($ar['company_id'] ?? 'null')
        ),
        'info'
    );
}

if ($voucher) {
    foreach (['applicant', 'department manager', 'checked by'] as $rk) {
        $expected = $pvRoles[$rk] ?? '';
        if ($expected === '') {
            continue;
        }
        $roleRows = $byRole[$rk] ?? [];
        if (count($roleRows) === 0) {
            dva_row('mismatch', $rk, 'No approvals row — preview uses voucher field; flow may synthesize from voucher', 'warn');
            continue;
        }
        if (count($roleRows) > 1) {
            dva_row('duplicate', $rk, count($roleRows) . ' rows for same role — dedup may pick wrong name', 'warn');
        }
        foreach ($roleRows as $i => $ar) {
            $actual = trim((string) ($ar['approver_name'] ?? ''));
            $match = strcasecmp($actual, $expected) === 0;
            dva_row(
                'compare',
                $rk . (count($roleRows) > 1 ? ' row#' . ($i + 1) : ''),
                'voucher="' . $expected . '" vs approvals="' . $actual . '"',
                $match ? 'ok' : 'fail'
            );
        }
    }
}

if ($doFix && $voucher && function_exists('syncVoucherApprovalAssignees')) {
    try {
        syncVoucherApprovalAssignees($pdo, $voucherId, [
            'Applicant' => $pvRoles['applicant'] ?? '',
            'Department Manager' => $pvRoles['department manager'] ?? '',
            'Checked By' => $pvRoles['checked by'] ?? '',
        ]);
        dva_row('fix', 'syncVoucherApprovalAssignees', 'Executed — reload without fix=1 to verify', 'ok');
    } catch (Throwable $e) {
        dva_row('fix', 'syncVoucherApprovalAssignees', $e->getMessage(), 'fail');
    }
}

$allStages = [];
if ($voucher) {
    $voucher_id = $voucherId;
    try {
        require __DIR__ . '/includes/voucher-approval-flow-data.php';
        dva_row('approval flow', 'allStages count', (string) count($allStages), 'info');
        foreach ($allStages as $st) {
            $rk = dva_norm_role($st['role'] ?? '');
            $expected = $pvRoles[$rk] ?? '';
            $display = trim((string) ($st['approver_name'] ?? ''));
            $match = $expected === '' || strcasecmp($display, $expected) === 0;
            dva_row(
                'approval flow stage',
                (string) ($st['role'] ?? ''),
                $display . ' (' . (string) ($st['status'] ?? '') . ')',
                $match ? 'ok' : 'fail'
            );
        }
    } catch (Throwable $e) {
        dva_row('approval flow', 'voucher-approval-flow-data.php', $e->getMessage(), 'fail');
    }
}

if ($asText) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "debug_voucher_applicant v" . DVA_VERSION . "\n";
    echo "voucher_id=" . $voucherId . " company_slug=" . $dvaCompanySlug . " fix=" . ($doFix ? '1' : '0') . "\n";
    echo str_repeat('-', 72) . "\n";
    $cur = '';
    foreach ($rows as $r) {
        if ($r['section'] !== $cur) {
            $cur = $r['section'];
            echo "\n[" . strtoupper($cur) . "]\n";
        }
        echo str_pad($r['label'], 36) . ' ' . strtoupper($r['status']) . '  ' . $r['value'] . "\n";
    }
    echo "\nURLs:\n";
    echo "  Report: .../debug_voucher_applicant.php?key=" . DVA_KEY . "&company_slug=" . $dvaCompanySlug . "&id=" . $voucherId . "\n";
    echo "  Fix:    ...&fix=1\n";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Voucher applicant debug #<?= (int) $voucherId ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; background: #f8fafc; color: #0f172a; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        .meta { color: #64748b; margin-bottom: 20px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; font-size: 14px; }
        th { background: #f1f5f9; width: 220px; }
        tr:last-child td, tr:last-child th { border-bottom: 0; }
        .ok { color: #047857; font-weight: 600; }
        .fail { color: #b91c1c; font-weight: 700; }
        .warn { color: #b45309; font-weight: 600; }
        .info { color: #334155; }
        .actions { margin: 16px 0; }
        .actions a { display: inline-block; margin-right: 12px; padding: 8px 14px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .actions a.secondary { background: #64748b; }
        .section td { background: #e2e8f0; font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: .04em; }
    </style>
</head>
<body>
    <h1>Voucher applicant / approval debug</h1>
    <p class="meta">
        Voucher #<?= (int) $voucherId ?> &middot;
        <?= dva_h($dvaCompanySlug) ?> &middot;
        v<?= DVA_VERSION ?> &middot;
        <?= dva_h(date('Y-m-d H:i:s')) ?>
    </p>
    <div class="actions">
        <a href="?key=<?= dva_h($dvaKey) ?>&amp;company_slug=<?= dva_h($dvaCompanySlug) ?>&amp;id=<?= (int) $voucherId ?>">Refresh</a>
        <a href="?key=<?= dva_h($dvaKey) ?>&amp;company_slug=<?= dva_h($dvaCompanySlug) ?>&amp;id=<?= (int) $voucherId ?>&amp;fix=1">Run sync fix</a>
        <a class="secondary" href="view-voucher.php?id=<?= (int) $voucherId ?>">Open voucher view</a>
        <a class="secondary" href="?key=<?= dva_h($dvaKey) ?>&amp;company_slug=<?= dva_h($dvaCompanySlug) ?>&amp;id=<?= (int) $voucherId ?>&amp;format=text">Plain text</a>
    </div>
    <table>
        <?php
        $cur = '';
        foreach ($rows as $r):
            if ($r['section'] !== $cur):
                $cur = $r['section'];
                echo '<tr class="section"><td colspan="2">' . dva_h($cur) . '</td></tr>';
            endif;
            $cls = dva_h($r['status']);
        ?>
        <tr>
            <th><?= dva_h($r['label']) ?></th>
            <td><span class="<?= $cls ?>"><?= strtoupper($cls) ?></span> — <?= dva_h($r['value']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="meta">Delete <code>debug_voucher_applicant.php</code> when finished.</p>
</body>
</html>
