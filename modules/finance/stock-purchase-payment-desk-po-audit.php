<?php
/**
 * Stock Purchase Payment Desk - PO visibility / edit / attachment audit.
 *
 * Usage:
 *   HTML:  .../stock-purchase-payment-desk-po-audit.php?module=balances
 *   JSON:  .../stock-purchase-payment-desk-po-audit.php?format=json
 *   PO:    .../stock-purchase-payment-desk-po-audit.php?po_id=195
 *   Search: .../stock-purchase-payment-desk-po-audit.php?q=PUR-20260702
 *   Issues only: .../stock-purchase-payment-desk-po-audit.php?issues_only=1
 */
declare(strict_types=1);

require_once __DIR__ . '/stock-purchase-payment-desk-ui/sppd-po-audit-lib.php';

sppdRequireAccess();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'po_id' => trim((string) ($_GET['po_id'] ?? '')),
    'issues_only' => trim((string) ($_GET['issues_only'] ?? '')) !== '' ? '1' : '',
];

$report = sppd_audit_build_report($filters);
$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$summary = $report['summary'] ?? [];
$orders = $report['purchase_orders'] ?? [];
$connections = $report['connections'] ?? [];
$deskOrders = $report['desk_orders'] ?? [];
$orphans = $report['cross_database_orphans'] ?? [];

function sppd_audit_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sppd_audit_issue_class(array $issues): string
{
    return $issues === [] ? 'ok' : 'warn';
}

$selfBase = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$query = $_GET;
unset($query['format']);
$jsonUrl = $selfBase . '?' . http_build_query(array_merge($query, ['format' => 'json']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPPD PO Audit</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: Inter, system-ui, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 1.25rem; }
        h1, h2 { margin: 0 0 0.75rem; }
        h1 { font-size: 1.35rem; }
        h2 { font-size: 1rem; color: #cbd5e1; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
        .grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .stat { background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 0.85rem; }
        .stat strong { display: block; font-size: 1.25rem; }
        .stat span { font-size: 0.75rem; color: #94a3b8; }
        table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
        th, td { border-bottom: 1px solid #334155; padding: 0.55rem 0.45rem; text-align: left; vertical-align: top; }
        th { color: #94a3b8; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .ok { color: #4ade80; }
        .warn { color: #fbbf24; }
        .bad { color: #f87171; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.72rem; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 1rem; }
        .toolbar a, .toolbar button { background: #334155; color: #f8fafc; border: 0; border-radius: 8px; padding: 0.45rem 0.75rem; text-decoration: none; font-size: 0.8125rem; cursor: pointer; }
        .toolbar a:hover, .toolbar button:hover { background: #475569; }
        form.filters { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; }
        form.filters label { display: grid; gap: 0.2rem; font-size: 0.72rem; color: #94a3b8; }
        form.filters input[type="text"], form.filters input[type="number"] { background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 8px; padding: 0.45rem 0.6rem; min-width: 10rem; }
        .pill { display: inline-block; padding: 0.1rem 0.45rem; border-radius: 999px; background: #334155; font-size: 0.68rem; margin-right: 0.25rem; }
        pre { white-space: pre-wrap; word-break: break-word; font-size: 0.72rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 0.75rem; max-height: 320px; overflow: auto; }
        .issues { margin: 0; padding-left: 1rem; }
        .issues li { margin: 0.15rem 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Stock Purchase Payment Desk - PO Audit</h1>
    <p style="color:#94a3b8;font-size:0.875rem;margin:0 0 1rem">
        Diagnose purchase orders missing from the desk, missing edit actions, payment-link mismatches, and attachment availability.
        Generated <?= sppd_audit_h((string) ($report['generated_at'] ?? '')) ?> - v<?= sppd_audit_h((string) ($report['version'] ?? '')) ?>
    </p>

    <div class="toolbar">
        <a href="<?= sppd_audit_h($jsonUrl) ?>">Download JSON</a>
        <a href="<?= sppd_audit_h(str_replace('stock-purchase-payment-desk-po-audit.php', 'stock-purchase-payment-desk.php', $selfBase) . '?module=balances') ?>">Open payment desk</a>
        <a href="<?= sppd_audit_h(str_replace('stock-purchase-payment-desk-po-audit.php', 'stock-purchase-payment-desk-debug.php', $selfBase)) ?>">Deployment debug</a>
    </div>

    <div class="card">
        <form class="filters" method="get">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['q', 'po_id', 'issues_only'], true)) continue; ?>
                <input type="hidden" name="<?= sppd_audit_h((string) $k) ?>" value="<?= sppd_audit_h((string) $v) ?>">
            <?php endforeach; ?>
            <label>PO number search
                <input type="text" name="q" value="<?= sppd_audit_h($filters['q']) ?>" placeholder="PUR-20260702-011">
            </label>
            <label>PO / desk ID
                <input type="number" name="po_id" value="<?= sppd_audit_h($filters['po_id']) ?>" placeholder="195">
            </label>
            <label><span>&nbsp;</span>
                <span><input type="checkbox" name="issues_only" value="1" <?= $filters['issues_only'] !== '' ? 'checked' : '' ?>> Issues only</span>
            </label>
            <button type="submit">Run audit</button>
        </form>
    </div>

    <div class="card">
        <h2>Summary</h2>
        <?php if (!empty($summary['attachment_check_note'])): ?>
            <p style="color:#94a3b8;font-size:0.78rem;margin:0 0 0.75rem"><?= sppd_audit_h((string) $summary['attachment_check_note']) ?></p>
        <?php endif; ?>
        <div class="grid">
            <div class="stat"><strong><?= (int) ($summary['desk_listed_count'] ?? 0) ?></strong><span>Listed on payment desk</span></div>
            <div class="stat"><strong><?= (int) ($summary['modern_scanned'] ?? 0) ?></strong><span>Modern POs scanned</span></div>
            <div class="stat"><strong><?= (int) ($summary['legacy_scanned'] ?? 0) ?></strong><span>Legacy POs scanned</span></div>
            <div class="stat"><strong class="warn"><?= (int) ($summary['with_issues'] ?? 0) ?></strong><span>POs with issues</span></div>
            <div class="stat"><strong class="warn"><?= (int) ($summary['missing_from_desk'] ?? 0) ?></strong><span>Not on desk (in scan)</span></div>
            <div class="stat"><strong><?= (int) ($summary['legacy_no_edit_expected'] ?? 0) ?></strong><span>Fully paid (no edit in desk)</span></div>
            <div class="stat"><strong class="warn"><?= (int) ($summary['modern_no_edit_link'] ?? 0) ?></strong><span>Modern POs missing edit</span></div>
            <div class="stat"><strong class="warn"><?= (int) ($summary['attachment_missing_files'] ?? 0) ?></strong><span>Missing attachment files</span></div>
            <div class="stat"><strong class="warn"><?= (int) ($summary['payment_link_mismatch'] ?? 0) ?></strong><span>Payment-link mismatches</span></div>
            <div class="stat"><strong><?= (int) ($summary['legacy_on_desk'] ?? 0) ?></strong><span>Legacy POs on desk</span></div>
        </div>
    </div>

    <div class="card">
        <h2>Database connections</h2>
        <table>
            <thead>
                <tr>
                    <th>Connection</th>
                    <th>Database</th>
                    <th>Desk PDO</th>
                    <th>PO tables</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($connections as $conn): ?>
                <tr>
                    <td><?= sppd_audit_h((string) ($conn['label'] ?? '')) ?></td>
                    <td class="mono"><?= sppd_audit_h((string) ($conn['db_name'] ?? '-')) ?></td>
                    <td class="<?= !empty($conn['is_desk_pdo']) ? 'ok' : '' ?>"><?= !empty($conn['is_desk_pdo']) ? 'yes' : 'no' ?></td>
                    <td class="mono">
                        <?php foreach (($conn['tables'] ?? []) as $table => $exists): ?>
                            <?php if ($exists && in_array($table, ['stocks_purchase_orders', 'purchases', 'supplier_payments', 'stocks_purchase_attachments'], true)): ?>
                                <span class="pill"><?= sppd_audit_h((string) $table) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php $stockRoots = $report['stock_root_candidates'] ?? []; if ($stockRoots !== []): ?>
    <div class="card">
        <h2>Invoice file search roots</h2>
        <p style="color:#94a3b8;font-size:0.78rem;margin:0 0 0.75rem">Attachment checks look for <code>uploads/invoices/�</code> under these stock folders.</p>
        <ul class="issues">
            <?php foreach ($stockRoots as $root): ?>
                <li class="mono"><?= sppd_audit_h((string) $root) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Currently listed on payment desk (<?= count($deskOrders) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Desk ID</th>
                    <th>PO number</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th>Balance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deskOrders as $order): ?>
                <tr>
                    <td><?= (int) ($order['id'] ?? 0) ?></td>
                    <td><?= sppd_audit_h((string) ($order['poNumber'] ?? '')) ?></td>
                    <td><?= sppd_audit_h((string) ($order['payeeName'] ?? '')) ?></td>
                    <td><?= sppd_audit_h((string) ($order['paymentStatus'] ?? '')) ?></td>
                    <td><?= sppd_audit_h((string) ($order['currency'] ?? '')) ?> <?= number_format((float) ($order['balanceDue'] ?? 0), 2) ?></td>
                    <td class="<?= ($order['editUrl'] ?? '') === '' ? 'warn' : 'ok' ?>">
                        <?= ($order['editUrl'] ?? '') !== '' ? 'edit available' : 'hidden (fully paid)' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>PO audit results (<?= count($orders) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Desk ID</th>
                    <th>PO number</th>
                    <th>Supplier</th>
                    <th>On desk</th>
                    <th>Edit</th>
                    <th>Attachments</th>
                    <th>Issues</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $po): ?>
                <?php $issues = $po['issues'] ?? []; ?>
                <tr>
                    <td><?= sppd_audit_h((string) ($po['source'] ?? '')) ?></td>
                    <td class="mono"><?= (int) ($po['desk_id'] ?? 0) ?><?php if (($po['source'] ?? '') === 'legacy'): ?> <span class="pill">real <?= (int) ($po['real_id'] ?? 0) ?></span><?php endif; ?></td>
                    <td><?= sppd_audit_h((string) ($po['po_number'] ?? '')) ?></td>
                    <td><?= sppd_audit_h((string) ($po['payee_name'] ?? '')) ?></td>
                    <td class="<?= !empty($po['on_desk']) ? 'ok' : 'warn' ?>"><?= !empty($po['on_desk']) ? 'yes' : 'no' ?></td>
                    <td class="<?= ($po['edit_url'] ?? '') === '' ? 'warn' : 'ok' ?>">
                        <?php if (($po['edit_url'] ?? '') !== ''): ?>
                            yes
                        <?php elseif (($po['open_po_url'] ?? $po['view_url'] ?? '') !== ''): ?>
                            open only
                        <?php else: ?>
                            no
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= (int) (($po['attachments']['count'] ?? 0)) ?> file(s)
                        <?php if (!empty($po['attachments']['has_missing_files'])): ?><span class="bad">missing on disk</span><?php endif; ?>
                    </td>
                    <td class="<?= sppd_audit_issue_class($issues) ?>">
                        <?php if ($issues === []): ?>none<?php else: ?>
                            <ul class="issues">
                                <?php foreach ($issues as $issue): ?>
                                    <li><?= sppd_audit_h((string) $issue) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($orphans !== []): ?>
    <div class="card">
        <h2>Cross-database POs not on desk (<?= count($orphans) ?>)</h2>
        <table>
            <thead><tr><th>Database</th><th>ID</th><th>PO number</th><th>Status</th><th>Issue</th></tr></thead>
            <tbody>
            <?php foreach ($orphans as $row): ?>
                <tr>
                    <td class="mono"><?= sppd_audit_h((string) ($row['db'] ?? '')) ?></td>
                    <td><?= (int) ($row['id'] ?? 0) ?></td>
                    <td><?= sppd_audit_h((string) ($row['po_number'] ?? '')) ?></td>
                    <td><?= sppd_audit_h((string) ($row['status'] ?? '')) ?></td>
                    <td class="warn"><?= sppd_audit_h((string) ($row['issue'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Detailed JSON (selected PO)</h2>
        <?php if ($filters['po_id'] !== '' || $filters['q'] !== ''): ?>
            <pre><?= sppd_audit_h(json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') ?></pre>
        <?php else: ?>
            <p style="color:#94a3b8;margin:0">Add <code>po_id</code> or <code>q</code> to see full per-PO attachment and payment-link detail here, or use JSON export.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
