<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$page_title = 'Posting / Approval Workflow';

global $pdo;

$stats = [
    ['label' => 'Draft', 'count' => 0, 'icon' => 'far fa-file-lines', 'tone' => '#2563eb'],
    ['label' => 'Pending Approval', 'count' => 0, 'icon' => 'far fa-clock', 'tone' => '#f59e0b'],
    ['label' => 'Approved', 'count' => 0, 'icon' => 'far fa-circle-check', 'tone' => '#16a34a'],
    ['label' => 'Posted', 'count' => 0, 'icon' => 'fas fa-arrow-up-from-bracket', 'tone' => '#16a34a'],
    ['label' => 'Locked', 'count' => 0, 'icon' => 'fas fa-lock', 'tone' => '#8b5cf6'],
];

$documents = [];

$statusClass = static function (string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'posted') return 'ok';
    if ($s === 'approved') return 'approved';
    if ($s === 'pending approval' || $s === 'pending') return 'pending';
    if ($s === 'draft' || $s === 'confirming') return 'draft';
    if ($s === 'rejected') return 'rejected';
    if ($s === 'locked') return 'rejected';
    return 'draft';
};

$formatDocumentDate = static function (?string $date): string {
    if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y h:i A', $ts) : $date;
};

$mapStatus = static function (array $row): array {
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $posted = isset($row['is_posted']) && ((string) $row['is_posted'] === '1' || (string) $row['is_posted'] === 'true');
    $restricted = isset($row['is_restricted']) && ((string) $row['is_restricted'] === '1' || (string) $row['is_restricted'] === 'true');

    if ($restricted) {
        return ['label' => 'Locked', 'step' => 'Locked'];
    }
    if ($posted) {
        return ['label' => 'Posted', 'step' => 'Posted'];
    }
    if ($status === 'approved') {
        return ['label' => 'Approved', 'step' => 'Pending Posting'];
    }
    if ($status === 'pending') {
        return ['label' => 'Pending Approval', 'step' => trim((string) ($row['department_manager'] ?? $row['general_manager'] ?? $row['prepared_by'] ?? 'Finance Review')) ?: 'Pending Approval'];
    }
    if ($status === 'confirming') {
        return ['label' => 'Draft', 'step' => 'Draft'];
    }
    if ($status === 'rejected') {
        return ['label' => 'Rejected', 'step' => 'Rejected'];
    }
    if ($status === 'locked') {
        return ['label' => 'Locked', 'step' => 'Locked'];
    }
    return ['label' => ucfirst($status ?: 'Pending Approval'), 'step' => ucfirst($status ?: 'Pending Approval')];
};

try {
    $myUserId = $_SESSION['user_id'] ?? 0;
    $myPendingVoucherIds = [];
    if ($myUserId > 0) {
        try {
            $myPendingVoucherIds = $pdo->query("SELECT DISTINCT voucher_id FROM approvals WHERE approver_id = $myUserId AND status = 'pending'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $myPendingVoucherIds = array_map('intval', $myPendingVoucherIds);
        } catch (Throwable $e) {}
    }

    $voucherRows = $pdo->query(
        "SELECT pv.id, pv.voucher_no AS no, 'Payment Voucher' AS type, COALESCE(pv.description, '') AS description, COALESCE(pv.status, 'confirming') AS status, IFNULL(pv.is_posted, 0) AS is_posted, COALESCE(u.full_name, '') AS created_by_name, COALESCE(pv.date_created, pv.created_at) AS on_date, COALESCE(pv.total_amount, 0) AS amount, COALESCE(pv.department_manager, '') AS department_manager, COALESCE(pv.general_manager, '') AS general_manager, COALESCE(pv.prepared_by, '') AS prepared_by, COALESCE(pv.checked_by, '') AS checked_by, IFNULL(pv.is_restricted, 0) AS is_restricted
         FROM payment_vouchers pv
         LEFT JOIN users u ON u.id = pv.created_by
         ORDER BY COALESCE(pv.date_created, pv.created_at) DESC, pv.id DESC
         LIMIT 1000"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($voucherRows as $row) {
        $mapped = $mapStatus($row);
        $isPendingMyApproval = in_array((int)$row['id'], $myPendingVoucherIds, true);
        $pendingType = '';
        if (strtolower($row['status']) === 'pending' && !$row['is_posted'] && !$row['is_restricted']) {
            $pendingType = $isPendingMyApproval ? 'my' : 'others';
        }
        $documents[] = [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'no' => $row['no'],
            'description' => $row['description'],
            'status' => $mapped['label'],
            'step' => $mapped['step'],
            'amount' => (float) $row['amount'],
            'by' => $row['created_by_name'] ?: 'System',
            'on' => $formatDocumentDate($row['on_date']),
            'raw_date' => date('Y-m-d', strtotime($row['on_date'])),
            'pending_type' => $pendingType,
            'link' => '../view-voucher.php?id=' . (int) $row['id'] . '&module=' . urlencode($jeModule),
        ];
    }

    $journalRows = $pdo->query(
        "SELECT je.id, je.entry_number AS no, 'Journal Entry' AS type, COALESCE(je.description, '') AS description, COALESCE(NULLIF(LOWER(je.status), ''), 'posted') AS status, 1 AS is_posted, COALESCE(u.full_name, '') AS created_by_name, je.date AS on_date, COALESCE(SUM(ji.debit - ji.credit), 0) AS amount, '' AS department_manager, '' AS general_manager, '' AS prepared_by, '' AS checked_by
         FROM erp_journal_entries je
         LEFT JOIN users u ON u.id = je.created_by
         LEFT JOIN erp_journal_items ji ON ji.journal_id = je.id
         GROUP BY je.id, je.entry_number, je.description, je.status, je.created_by, je.date, u.full_name
         ORDER BY je.date DESC, je.id DESC
         LIMIT 1000"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($journalRows as $row) {
        $mapped = $mapStatus($row);
        $documents[] = [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'no' => $row['no'],
            'description' => $row['description'],
            'status' => $mapped['label'],
            'step' => $mapped['step'],
            'amount' => (float) $row['amount'],
            'by' => $row['created_by_name'] ?: 'System',
            'on' => $formatDocumentDate($row['on_date']),
            'raw_date' => date('Y-m-d', strtotime($row['on_date'])),
            'pending_type' => '',
            'link' => 'view-journal.php?id=' . (int) $row['id'] . '&module=' . urlencode($jeModule),
        ];
    }

    usort($documents, static function (array $a, array $b) {
        $ta = strtotime($a['raw_date']) ?: 0;
        $tb = strtotime($b['raw_date']) ?: 0;
        if ($ta === $tb) {
            return $b['id'] <=> $a['id'];
        }
        return $tb <=> $ta;
    });

    $draftCount = 0;
    $pendingCount = 0;
    $approvedCount = 0;
    $postedCount = 0;
    $lockedCount = 0;
    $rejectedCount = 0;
    $pendingMyCount = 0;
    $pendingOthersCount = 0;

    foreach ($documents as $d) {
        if ($d['status'] === 'Draft') $draftCount++;
        elseif ($d['status'] === 'Pending Approval') $pendingCount++;
        elseif ($d['status'] === 'Approved') $approvedCount++;
        elseif ($d['status'] === 'Posted') $postedCount++;
        elseif ($d['status'] === 'Locked') $lockedCount++;
        elseif ($d['status'] === 'Rejected') $rejectedCount++;

        if ($d['pending_type'] === 'my') $pendingMyCount++;
        elseif ($d['pending_type'] === 'others') $pendingOthersCount++;
    }

    $stats = [
        ['label' => 'Draft', 'count' => $draftCount, 'icon' => 'far fa-file-lines', 'tone' => '#2563eb'],
        ['label' => 'Pending Approval', 'count' => $pendingCount, 'icon' => 'far fa-clock', 'tone' => '#f59e0b'],
        ['label' => 'Approved', 'count' => $approvedCount, 'icon' => 'far fa-circle-check', 'tone' => '#16a34a'],
        ['label' => 'Posted', 'count' => $postedCount, 'icon' => 'fas fa-arrow-up-from-bracket', 'tone' => '#16a34a'],
        ['label' => 'Locked', 'count' => $lockedCount, 'icon' => 'fas fa-lock', 'tone' => '#8b5cf6'],
    ];

    // Build unique creators
    $creators = [];
    foreach ($documents as $d) {
        if (!empty($d['by']) && !in_array($d['by'], $creators, true)) {
            $creators[] = $d['by'];
        }
    }
    sort($creators);

} catch (Throwable $e) {
    // Fail-safe defaults
    $pendingMyCount = 0;
    $pendingOthersCount = 0;
    $postedCount = 0;
    $lockedCount = 0;
    $rejectedCount = 0;
    $creators = [];
}

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.main-content.paw-shell{margin-top:0!important;padding:14px 0 26px!important;background:#f9fafb;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0f172a}
.paw-wrap{padding:0 16px}
.paw-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.paw-title{margin:0;font-size:34px;font-weight:800;color:#0b1f5d;line-height:1.1}
.paw-sub{margin:6px 0 0;font-size:14px;color:#64748b}
.paw-bc{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748b}
.paw-bc a{color:#2563eb;text-decoration:none;font-weight:700}
.paw-search{display:flex;align-items:center;gap:8px;border:1px solid #e5e7eb;background:#fff;border-radius:9px;padding:8px 10px;min-width:320px}
.paw-search input{border:0;outline:none;flex:1;min-width:0;font-size:13px}
.paw-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,380px);gap:12px;align-items:start}
.paw-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);overflow:hidden;margin-bottom:12px}
.paw-bd{padding:12px 14px}
.paw-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.paw-kpi{border:1px solid #eef2f7;border-radius:9px;padding:10px;display:flex;gap:10px;align-items:center}
.paw-kpi .ico{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#f8fafc}
.paw-kpi .n{font-size:26px;font-weight:800;color:#0f172a;line-height:1}
.paw-kpi .l{font-size:12px;color:#64748b;font-weight:700}
.paw-tabs{display:flex;gap:12px;flex-wrap:wrap;padding:0 14px;border-bottom:1px solid #eef2f7}
.paw-tabs button{border:0;background:transparent;padding:12px 0;font-size:13px;font-weight:700;color:#64748b;border-bottom:2px solid transparent;cursor:pointer}
.paw-tabs button.active{color:#2563eb;border-bottom-color:#2563eb}
.paw-tabs .b{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;border-radius:999px;font-size:10px;font-weight:800;padding:0 5px;margin-left:4px;background:#f59e0b;color:#fff}
.paw-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:12px 14px;border-bottom:1px solid #eef2f7}
.paw-fg label{display:block;font-size:13px;color:#475569;font-weight:700;margin-bottom:7px}
.paw-ctl{width:100%;height:40px;border:1px solid #dbe2ea;border-radius:8px;padding:0 12px;font-size:14px;background:#fff;color:#0f172a}
.paw-table-wrap{overflow:auto;padding:0 14px 10px}
.paw-table{width:100%;min-width:1100px;border-collapse:collapse;font-size:13px}
.paw-table th,.paw-table td{padding:11px 9px;border-bottom:1px solid #eef2f7;vertical-align:middle;white-space:nowrap}
.paw-table th:nth-child(4), .paw-table td:nth-child(4){width:220px;max-width:220px;white-space:normal;word-wrap:break-word;overflow:hidden;text-overflow:ellipsis}
.paw-table th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#475569;font-weight:800;background:#fafafa;text-align:left}
.paw-link{color:#2563eb;text-decoration:none;font-weight:700}
.paw-amt{text-align:right;font-weight:700}
.paw-pill{display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800}
.paw-pill.ok{background:#dcfce7;color:#15803d}
.paw-pill.pending{background:#fef3c7;color:#b45309}
.paw-pill.approved{background:#dcfce7;color:#166534}
.paw-pill.draft{background:#e2e8f0;color:#475569}
.paw-pill.rejected{background:#fee2e2;color:#b91c1c}
.paw-dot{display:inline-flex;align-items:center;gap:6px}
.paw-dot::before{content:"";width:7px;height:7px;border-radius:50%;background:#16a34a;display:inline-block}
.paw-dot.pending::before{background:#f59e0b}
.paw-dot.rejected::before{background:#ef4444}
.paw-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px}
.paw-pager{display:flex;gap:6px;align-items:center}
.paw-pager .p{width:24px;height:24px;border:1px solid #dbe2ea;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;color:#64748b;background:#fff}
.paw-pager .p.active{background:#2563eb;border-color:#2563eb;color:#fff}
.paw-side-h{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:15px;font-weight:700}
.paw-flow{padding:10px 14px}
.paw-flow-row{display:grid;grid-template-columns:20px minmax(0,1fr);gap:8px;align-items:start;margin-bottom:10px;position:relative}
.paw-flow-row:last-child{margin-bottom:0}
.paw-flow-dot{width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#fff;background:#2563eb;z-index:1}
.paw-flow-row.ok .paw-flow-dot{background:#16a34a}
.paw-flow-row.pending .paw-flow-dot{background:#f59e0b}
.paw-flow-row.locked .paw-flow-dot{background:#8b5cf6}
.paw-flow-row:not(:last-child)::after{content:"";position:absolute;left:8px;top:20px;bottom:-12px;width:2px;background:#e2e8f0}
.paw-flow-t{font-size:13px;font-weight:700;color:#0f172a;line-height:1.3}
.paw-flow-s{font-size:12px;color:#64748b;margin-top:3px;line-height:1.4}
.paw-legend{padding:12px 14px;border-top:1px solid #eef2f7}
.paw-legend .r{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px}
.paw-legend .r:last-child{margin-bottom:0}
.paw-legend .c{width:14px;height:14px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px;flex-shrink:0}
.paw-qa{padding:12px 14px;border-top:1px solid #eef2f7}
.paw-qa .q{display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;margin-bottom:8px;font-size:12px;font-weight:700;color:#2563eb;background:#f8fafc}
.paw-qa .q:last-child{margin-bottom:0}
@media(max-width:1200px){.paw-grid{grid-template-columns:1fr}.paw-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.paw-filters{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.paw-kpis,.paw-filters{grid-template-columns:1fr}.paw-search{min-width:0;width:100%}}
</style>

<main class="main-content paw-shell">
  <div class="paw-wrap">
    <div class="paw-top">
      <div>
        <h1 class="paw-title">Posting / Approval Workflow</h1>
        <p class="paw-sub">Manage document approval and posting status</p>
        <nav class="paw-bc">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="#">Finance &amp; Accounting</a><i class="fas fa-chevron-right"></i>
          <span>Posting / Approval Workflow</span>
        </nav>
      </div>
      <div class="paw-search"><i class="fas fa-search" style="color:#94a3b8;"></i><input type="text" placeholder="Search documents..."><span style="font-size:11px;color:#94a3b8;font-weight:700;">Ctrl + K</span></div>
    </div>

    <div class="paw-grid">
      <div>
        <section class="paw-card"><div class="paw-bd"><div class="paw-kpis">
          <?php foreach ($stats as $s): ?>
            <div class="paw-kpi"><span class="ico" style="color:<?= htmlspecialchars((string)$s['tone']) ?>"><i class="<?= htmlspecialchars((string)$s['icon']) ?>"></i></span><div><div class="l"><?= htmlspecialchars((string)$s['label']) ?></div><div class="n"><?= number_format((int)$s['count']) ?></div><div class="l">Documents</div></div></div>
          <?php endforeach; ?>
        </div></div></section>

        <section class="paw-card">
          <div class="paw-tabs">
            <button class="active" data-tab="all">All Documents</button>
            <button data-tab="pending_my">Pending My Approval <span class="b" style="background:#f59e0b;"><?= $pendingMyCount ?></span></button>
            <button data-tab="pending_others">Pending Others <span class="b" style="background:#94a3b8;"><?= $pendingOthersCount ?></span></button>
            <button data-tab="recent_posted">Recently Posted <span class="b" style="background:#16a34a;"><?= $postedCount ?></span></button>
            <button data-tab="locked">Locked Documents <span class="b" style="background:#8b5cf6;"><?= $lockedCount ?></span></button>
            <button data-tab="rejected">Rejected Documents <span class="b" style="background:#ef4444;"><?= $rejectedCount ?></span></button>
          </div>

          <div class="paw-filters">
            <div class="paw-fg">
                <label>Document Type</label>
                <select id="filterDocType" class="paw-ctl">
                    <option value="">All Types</option>
                    <option value="Payment Voucher">Payment Voucher</option>
                    <option value="Journal Entry">Journal Entry</option>
                </select>
            </div>
            <div class="paw-fg">
                <label>Status</label>
                <select id="filterStatus" class="paw-ctl">
                    <option value="">All Statuses</option>
                    <option value="Draft">Draft</option>
                    <option value="Pending Approval">Pending Approval</option>
                    <option value="Approved">Approved</option>
                    <option value="Posted">Posted</option>
                    <option value="Locked">Locked</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div class="paw-fg">
                <label>Date Range</label>
                <div style="display:flex; gap:6px; align-items:center;">
                    <input type="date" id="filterDateFrom" class="paw-ctl" style="padding: 0 4px; font-size: 12px;">
                    <span style="font-size: 11px; color: #64748b;">to</span>
                    <input type="date" id="filterDateTo" class="paw-ctl" style="padding: 0 4px; font-size: 12px;">
                </div>
            </div>
            <div class="paw-fg">
                <label>Created By</label>
                <select id="filterCreatedBy" class="paw-ctl">
                    <option value="">All Users</option>
                    <?php foreach ($creators as $creator): ?>
                        <option value="<?= htmlspecialchars(strtolower($creator)) ?>"><?= htmlspecialchars($creator) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="paw-fg" style="display:flex;align-items:flex-end;">
                <button id="btnResetFilters" class="paw-ctl" style="font-weight:700;"><i class="fas fa-rotate-left"></i> Reset Filters</button>
            </div>
          </div>

          <div class="paw-table-wrap">
            <table class="paw-table">
              <thead><tr><th>#</th><th>Document No.</th><th>Document Type</th><th>Description</th><th>Status</th><th>Current Step</th><th>Amount (TZS)</th><th>Created By</th><th>Created On</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($documents as $idx => $d): ?>
                  <?php $cls = $statusClass((string)$d['status']); ?>
                  <tr class="document-row" 
                      data-type="<?= htmlspecialchars($d['type']) ?>" 
                      data-status="<?= htmlspecialchars($d['status']) ?>" 
                      data-pending-type="<?= htmlspecialchars($d['pending_type']) ?>" 
                      data-created-by="<?= htmlspecialchars(strtolower($d['by'])) ?>" 
                      data-raw-date="<?= htmlspecialchars($d['raw_date']) ?>">
                    <td><?= $idx + 1 ?></td>
                    <td><a class="paw-link" href="<?= htmlspecialchars((string)$d['link']) ?>"><?= htmlspecialchars((string)$d['no']) ?></a></td>
                    <td><?= htmlspecialchars((string)$d['type']) ?></td>
                    <td><?= htmlspecialchars((string)$d['description']) ?></td>
                    <td><span class="paw-pill <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars((string)$d['status']) ?></span></td>
                    <td><span class="paw-dot <?= htmlspecialchars($cls === 'pending' ? 'pending' : ($cls === 'rejected' ? 'rejected' : '')) ?>"><?= htmlspecialchars((string)$d['step']) ?></span></td>
                    <td class="paw-amt" style="color:<?= ((float)$d['amount'] < 0 ? '#ef4444' : '#0f172a') ?>;">
                      <?= number_format((float)$d['amount'], 2) ?>
                    </td>
                    <td><?= htmlspecialchars((string)$d['by']) ?></td>
                    <td><?= htmlspecialchars((string)$d['on']) ?></td>
                    <td><a href="<?= htmlspecialchars((string)$d['link']) ?>"><i class="far fa-eye" style="color:#64748b;"></i></a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="paw-foot">
            <div id="postingInfo" style="font-size:11px;color:#64748b;">Showing 1 to 10 of 1,382 entries</div>
            <div style="display:flex;gap:10px;align-items:center;">
              <select id="postingPerPage" class="paw-ctl" style="width:110px;height:30px;font-size:12px;">
                <option value="10">10 per page</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
              </select>
              <div id="postingPager" class="paw-pager" aria-label="Pagination"></div>
            </div>
          </div>

          <script>
            (function(){
              function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
              function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
              
              document.addEventListener('DOMContentLoaded', function(){
                const table = qs('.paw-table');
                if (!table) return;
                const rows = qsa('tbody tr', table);
                const info = qs('#postingInfo');
                const perPageSelect = qs('#postingPerPage');
                const pager = qs('#postingPager');
                
                const tabButtons = qsa('.paw-tabs button');
                const inputSearch = qs('.paw-search input');
                const selectDocType = qs('#filterDocType');
                const selectStatus = qs('#filterStatus');
                const inputDateFrom = qs('#filterDateFrom');
                const inputDateTo = qs('#filterDateTo');
                const selectCreatedBy = qs('#filterCreatedBy');
                const btnReset = qs('#btnResetFilters');
                
                let activeTab = 'all';
                let perPage = parseInt(perPageSelect.value, 10) || 10;
                let currentPage = 1;
                
                // Track visible rows after filtering
                let visibleRows = [];

                function applyFilters() {
                  const searchVal = inputSearch.value.toLowerCase().trim();
                  const docTypeVal = selectDocType.value;
                  const statusVal = selectStatus.value;
                  const dateFromVal = inputDateFrom.value; // YYYY-MM-DD
                  const dateToVal = inputDateTo.value; // YYYY-MM-DD
                  const createdByVal = selectCreatedBy.value; // lowercase
                  
                  visibleRows = [];
                  
                  rows.forEach(r => {
                    const type = r.getAttribute('data-type');
                    const status = r.getAttribute('data-status');
                    const pendingType = r.getAttribute('data-pending-type');
                    const createdBy = r.getAttribute('data-created-by');
                    const rawDate = r.getAttribute('data-raw-date');
                    const text = r.textContent.toLowerCase();
                    
                    let match = true;
                    
                    // Tab filter
                    if (activeTab === 'pending_my' && pendingType !== 'my') match = false;
                    else if (activeTab === 'pending_others' && pendingType !== 'others') match = false;
                    else if (activeTab === 'recent_posted' && status !== 'Posted') match = false;
                    else if (activeTab === 'locked' && status !== 'Locked') match = false;
                    else if (activeTab === 'rejected' && status !== 'Rejected') match = false;
                    
                    // Dropdown filters
                    if (docTypeVal && type !== docTypeVal) match = false;
                    if (statusVal && status !== statusVal) match = false;
                    if (createdByVal && createdBy !== createdByVal) match = false;
                    
                    // Date range filter
                    if (dateFromVal && rawDate < dateFromVal) match = false;
                    if (dateToVal && rawDate > dateToVal) match = false;
                    
                    // Text search
                    if (searchVal && !text.includes(searchVal)) match = false;
                    
                    if (match) {
                      visibleRows.push(r);
                    } else {
                      r.style.display = 'none';
                    }
                  });
                  
                  // Reset to page 1 on filter change
                  renderPage(1);
                }
                
                function renderPage(page){
                  const totalRows = visibleRows.length;
                  const totalPages = Math.ceil(totalRows / perPage) || 1;
                  currentPage = Math.max(1, Math.min(page, totalPages));
                  
                  const start = (currentPage - 1) * perPage;
                  const end = Math.min(start + perPage, totalRows);
                  
                  // Hide all rows first, then show only the active page rows
                  rows.forEach(r => r.style.display = 'none');
                  visibleRows.forEach((r, i) => {
                    if (i >= start && i < end) {
                      r.style.display = '';
                    }
                  });
                  
                  info.textContent = `Showing ${totalRows === 0 ? 0 : start + 1} to ${end} of ${totalRows} entries`;
                  renderPager(totalPages);
                }
                
                function renderPager(totalPages){
                  const maxButtons = 7;
                  pager.innerHTML = '';
                  
                  function makeBtn(label, cls){
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'p' + (cls ? (' ' + cls) : '');
                    b.textContent = label;
                    b.style.cursor = 'pointer';
                    return b;
                  }
                  
                  const prev = makeBtn('<', 'prev');
                  prev.disabled = currentPage === 1;
                  prev.addEventListener('click', () => renderPage(currentPage - 1));
                  pager.appendChild(prev);
                  
                  function addPageBtn(i){
                    const b = makeBtn(i);
                    if (i === currentPage) b.classList.add('active');
                    b.addEventListener('click', () => renderPage(i));
                    pager.appendChild(b);
                  }
                  
                  function addEllipsis(){
                    const span = document.createElement('span');
                    span.className = 'p';
                    span.textContent = '...';
                    span.style.pointerEvents = 'none';
                    pager.appendChild(span);
                  }
                  
                  if (totalPages <= maxButtons) {
                    for (let i = 1; i <= totalPages; i++) addPageBtn(i);
                  } else {
                    let start = Math.max(1, currentPage - 3);
                    let end = Math.min(totalPages, start + maxButtons - 1);
                    if (end - start < maxButtons - 1) start = Math.max(1, end - maxButtons + 1);
                    if (start > 1) { addPageBtn(1); if (start > 2) addEllipsis(); }
                    for (let i = start; i <= end; i++) addPageBtn(i);
                    if (end < totalPages) { if (end < totalPages - 1) addEllipsis(); addPageBtn(totalPages); }
                  }
                  
                  const next = makeBtn('>', 'next');
                  next.disabled = currentPage === totalPages;
                  next.addEventListener('click', () => renderPage(currentPage + 1));
                  pager.appendChild(next);
                }
                
                // Event Listeners
                tabButtons.forEach(btn => {
                  btn.addEventListener('click', function(){
                    tabButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    activeTab = this.getAttribute('data-tab');
                    applyFilters();
                  });
                });
                
                inputSearch.addEventListener('input', applyFilters);
                selectDocType.addEventListener('change', applyFilters);
                selectStatus.addEventListener('change', applyFilters);
                inputDateFrom.addEventListener('change', applyFilters);
                inputDateTo.addEventListener('change', applyFilters);
                selectCreatedBy.addEventListener('change', applyFilters);
                
                btnReset.addEventListener('click', function(){
                  inputSearch.value = '';
                  selectDocType.value = '';
                  selectStatus.value = '';
                  inputDateFrom.value = '';
                  inputDateTo.value = '';
                  selectCreatedBy.value = '';
                  applyFilters();
                });
                
                perPageSelect.addEventListener('change', function(){
                  perPage = parseInt(this.value, 10) || 10;
                  renderPage(1);
                });
                
                // Ctrl + K shortcut
                document.addEventListener('keydown', function(e) {
                  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    inputSearch.focus();
                  }
                });
                
                // Expose function to set tab from quick actions
                window.setTab = function(tabName) {
                  const targetBtn = qs(`.paw-tabs button[data-tab="${tabName}"]`);
                  if (targetBtn) {
                    targetBtn.click();
                  }
                };
                
                // Initial execution
                applyFilters();
              });
            })();
          </script>
        </section>
      </div>

      <aside>
        <section class="paw-card">
          <div class="paw-side-h">Approval Workflow</div>
          <div class="paw-flow">
            <div class="paw-flow-row"><span class="paw-flow-dot"><i class="fas fa-1"></i></span><div><div class="paw-flow-t">Draft</div><div class="paw-flow-s">Document is created and saved as draft</div></div></div>
            <div class="paw-flow-row pending"><span class="paw-flow-dot"><i class="fas fa-2"></i></span><div><div class="paw-flow-t">Pending Approval</div><div class="paw-flow-s">Waiting for approval from authorized users</div></div></div>
            <div class="paw-flow-row"><span class="paw-flow-dot"><i class="fas fa-3"></i></span><div><div class="paw-flow-t">Approved</div><div class="paw-flow-s">Document has been approved</div></div></div>
            <div class="paw-flow-row ok"><span class="paw-flow-dot"><i class="fas fa-4"></i></span><div><div class="paw-flow-t">Posted</div><div class="paw-flow-s">Document is posted to ledger</div></div></div>
            <div class="paw-flow-row locked"><span class="paw-flow-dot"><i class="fas fa-lock"></i></span><div><div class="paw-flow-t">Locked</div><div class="paw-flow-s">Document is locked and cannot be edited</div></div></div>
          </div>

          <div class="paw-legend">
            <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:10px;">Status Legend</div>
            <div class="r"><span class="c" style="background:#94a3b8;">•</span><div><div style="font-size:12px;font-weight:700;">Draft</div><div style="font-size:11px;color:#64748b;">Document is in draft mode</div></div></div>
            <div class="r"><span class="c" style="background:#f59e0b;">•</span><div><div style="font-size:12px;font-weight:700;">Pending Approval</div><div style="font-size:11px;color:#64748b;">Waiting for approval</div></div></div>
            <div class="r"><span class="c" style="background:#16a34a;">•</span><div><div style="font-size:12px;font-weight:700;">Approved</div><div style="font-size:11px;color:#64748b;">Approved, waiting to be posted</div></div></div>
            <div class="r"><span class="c" style="background:#16a34a;">•</span><div><div style="font-size:12px;font-weight:700;">Posted</div><div style="font-size:11px;color:#64748b;">Successfully posted to ledger</div></div></div>
            <div class="r"><span class="c" style="background:#8b5cf6;">•</span><div><div style="font-size:12px;font-weight:700;">Locked</div><div style="font-size:11px;color:#64748b;">Locked, no further changes allowed</div></div></div>
            <div class="r"><span class="c" style="background:#ef4444;">•</span><div><div style="font-size:12px;font-weight:700;">Rejected</div><div style="font-size:11px;color:#64748b;">Document was rejected</div></div></div>
          </div>

          <div class="paw-qa">
            <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:10px;">Quick Actions</div>
            <div class="q" style="cursor:pointer;" onclick="setTab('pending_my')"><span><i class="fas fa-user-check"></i> My Approvals</span><span class="paw-pill pending"><?= $pendingMyCount ?></span></div>
            <div class="q" style="cursor:pointer;" onclick="setTab('recent_posted')"><span><i class="fas fa-clock-rotate-left"></i> Recently Posted</span><span class="paw-pill ok"><?= $postedCount ?></span></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
