<?php
// Basic smoke checks for multi-company isolation.
// Run in browser or CLI after migration and seed data are prepared.

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$companyA = trim((string) ($_GET['company_a'] ?? 'Ultimate General Trading'));
$companyB = trim((string) ($_GET['company_b'] ?? 'RoadMaster'));

function checkTableScoped(PDO $pdo, string $table, int $cidA, int $cidB): array
{
    $out = ['table' => $table, 'ok' => false, 'msg' => ''];
    if (!tableExists($table) || !columnExists($table, 'company_id')) {
        $out['msg'] = 'missing table or company_id';
        return $out;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE company_id = ?");
        $stmt->execute([$cidA]);
        $countA = (int) $stmt->fetchColumn();
        $stmt->execute([$cidB]);
        $countB = (int) $stmt->fetchColumn();
        $out['ok'] = true;
        $out['msg'] = "A={$countA}, B={$countB}";
        return $out;
    } catch (Throwable $e) {
        $out['msg'] = $e->getMessage();
        return $out;
    }
}

$cidA = 0;
$cidB = 0;
$stmtCompany = $pdo->prepare("SELECT id FROM companies WHERE company_name = ? LIMIT 1");
$stmtCompany->execute([$companyA]);
$cidA = (int) ($stmtCompany->fetchColumn() ?: 0);
$stmtCompany->execute([$companyB]);
$cidB = (int) ($stmtCompany->fetchColumn() ?: 0);

$results = [];
if ($cidA > 0 && $cidB > 0) {
    $tables = [
        'users',
        'payment_vouchers',
        'voucher_items',
        'sales_orders',
        'invoices',
        'stock',
        'erp_journal_entries',
        'financial_accounts',
        'account_transactions',
    ];
    foreach ($tables as $t) {
        $results[] = checkTableScoped($pdo, $t, $cidA, $cidB);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Multi-Company Smoke Test</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
  <h3>Multi-Company Smoke Test</h3>
  <p class="text-muted mb-4">Checks table-level company scoping for <strong><?= htmlspecialchars($companyA) ?></strong> and <strong><?= htmlspecialchars($companyB) ?></strong>.</p>

  <?php if ($cidA <= 0 || $cidB <= 0): ?>
    <div class="alert alert-danger">Seed companies not found. Ensure both companies exist before running tests.</div>
  <?php else: ?>
    <div class="alert alert-info">Company IDs: A=<?= (int) $cidA ?>, B=<?= (int) $cidB ?></div>
    <table class="table table-sm table-bordered bg-white">
      <thead><tr><th>Table</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string) $r['table']) ?></td>
          <td><?= $r['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">FAIL</span>' ?></td>
          <td><?= htmlspecialchars((string) $r['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
