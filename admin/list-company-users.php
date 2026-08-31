<?php
/**
 * Super-admin report: users and emails per company (from user_company_index).
 */
require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    $loginUrl = function_exists('app_url') ? app_url('/login.php?next=admin/list-company-users.php') : '/login.php';
    header('Location: ' . $loginUrl);
    exit;
}

if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
    http_response_code(403);
    die('Access denied. Super admin only.');
}

ensureMultiCompanyControlSchema();

global $control_pdo, $pdo;
$usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;

$rows = array();
$tableMissing = !function_exists('userCompanyIndexTableReady') || !userCompanyIndexTableReady();
$filterCompany = strtolower(trim((string) ($_GET['company'] ?? $_GET['company_slug'] ?? '')));
$filterStatus = strtolower(trim((string) ($_GET['status'] ?? '')));

if (!$tableMissing && $usePdo instanceof PDO) {
    try {
        $sql = "SELECT i.company_id, i.company_slug, c.company_name,
                       i.email, i.username, i.role, i.status, i.source,
                       i.tenant_user_id, i.tenant_db_name, i.last_synced_at
                FROM user_company_index i
                LEFT JOIN companies c ON c.id = i.company_id
                WHERE 1=1";
        $params = array();
        if ($filterCompany !== '') {
            $sql .= ' AND i.company_slug = ?';
            $params[] = $filterCompany;
        }
        if ($filterStatus !== '' && in_array($filterStatus, array('active', 'inactive', 'pending', 'blocked'), true)) {
            $sql .= ' AND i.status = ?';
            $params[] = $filterStatus;
        }
        $sql .= ' ORDER BY c.company_name ASC, i.email ASC';
        $stmt = $usePdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$byCompany = array();
foreach ($rows as $row) {
    $slug = strtolower(trim((string) ($row['company_slug'] ?? 'unknown')));
    if (!isset($byCompany[$slug])) {
        $byCompany[$slug] = array(
            'company_name' => (string) ($row['company_name'] ?? $slug),
            'company_slug' => $slug,
            'users' => array(),
        );
    }
    $byCompany[$slug]['users'][] = $row;
}

$companySlugs = array();
if ($usePdo instanceof PDO && tableExists('companies', $usePdo)) {
    try {
        $companySlugs = $usePdo->query("SELECT company_slug, company_name FROM companies WHERE TRIM(company_slug) <> '' ORDER BY company_name")
            ->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        $companySlugs = array();
    }
}

$totalUsers = count($rows);
$esc = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Company Users Report</title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; margin: 24px; background: #f1f5f9; color: #0f172a; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; font-size: 1.5rem; }
        .muted { color: #64748b; font-size: 14px; }
        .filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin: 16px 0; }
        .filters label { display: flex; flex-direction: column; font-size: 12px; font-weight: 600; color: #475569; gap: 4px; }
        select, .btn { padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; }
        .btn { background: #4f46e5; color: #fff; border: none; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn:hover { background: #4338ca; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        h2 { font-size: 1.15rem; margin: 0 0 12px; color: #1e293b; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f1f5f9; color: #64748b; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-blocked { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px 12px; text-align: left; }
        th { background: #f8fafc; font-weight: 600; }
        tr:nth-child(even) { background: #fafafa; }
        .err { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
        .stat { font-weight: 600; color: #4f46e5; }
        @media print {
            .no-print { display: none; }
            body { background: #fff; margin: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Company users &amp; emails</h1>
        <p class="muted">From <code>user_company_index</code> (control database). Total: <span class="stat"><?= (int) $totalUsers ?></span> users.</p>

        <?php if (!empty($loadError)): ?>
            <p class="err"><?= $esc($loadError) ?></p>
        <?php elseif ($tableMissing): ?>
            <p class="err">Table <code>user_company_index</code> is missing. Create it and run <a href="<?= $esc(app_url('/admin/sync-user-company-index.php')) ?>">sync</a> first.</p>
        <?php else: ?>

        <form method="get" class="filters no-print">
            <label>
                Company
                <select name="company">
                    <option value="">All companies</option>
                    <?php foreach ($companySlugs as $co): ?>
                        <?php $s = strtolower(trim((string) ($co['company_slug'] ?? ''))); ?>
                        <option value="<?= $esc($s) ?>"<?= $filterCompany === $s ? ' selected' : '' ?>><?= $esc((string) ($co['company_name'] ?? $s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach (array('active', 'inactive', 'pending', 'blocked') as $st): ?>
                        <option value="<?= $esc($st) ?>"<?= $filterStatus === $st ? ' selected' : '' ?>><?= $esc(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn">Filter</button>
            <a href="?" class="btn btn-secondary">Reset</a>
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        </form>

        <p class="muted no-print">
            <a href="<?= $esc(app_url('/admin/sync-user-company-index.php')) ?>">Re-sync index</a>
            &nbsp;|&nbsp;
            <?php
            $mgmtQs = $filterCompany !== '' ? ('?company_slug=' . rawurlencode($filterCompany) . '&module=settings') : '?module=settings';
            ?>
            <a href="<?= $esc(app_url('/admin/management.php' . $mgmtQs)) ?>">Company management</a>
            &nbsp;|&nbsp;
            <a href="<?= $esc(app_url('/admin/settings.php' . $mgmtQs)) ?>">Settings hub</a>
            &nbsp;|&nbsp;
            <a href="<?= $esc(app_url('/select-module.php')) ?>">Modules</a>
        </p>

        <?php if (empty($byCompany)): ?>
            <p class="muted">No users found. Run the sync tool to populate the index.</p>
        <?php else: ?>
            <?php foreach ($byCompany as $group): ?>
                <div class="card" style="margin-top: 16px; padding-top: 16px;">
                    <h2><?= $esc($group['company_name']) ?> <span class="muted">(<?= $esc($group['company_slug']) ?>)</span></h2>
                    <p class="muted"><?= count($group['users']) ?> user(s)</p>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Tenant user ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 0; foreach ($group['users'] as $u): $n++; ?>
                                <?php
                                $st = strtolower((string) ($u['status'] ?? 'active'));
                                $badgeClass = 'badge-active';
                                if ($st === 'inactive') {
                                    $badgeClass = 'badge-inactive';
                                } elseif ($st === 'pending') {
                                    $badgeClass = 'badge-pending';
                                } elseif ($st === 'blocked') {
                                    $badgeClass = 'badge-blocked';
                                }
                                ?>
                                <tr>
                                    <td><?= $n ?></td>
                                    <td><strong><?= $esc($u['email'] ?? '') ?></strong></td>
                                    <td><?= $esc($u['username'] ?? '') ?></td>
                                    <td><?= $esc($u['role'] ?? '') ?></td>
                                    <td><span class="badge <?= $esc($badgeClass) ?>"><?= $esc(ucfirst($st)) ?></span></td>
                                    <td><?= $esc((string) ($u['tenant_user_id'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
