<?php
require_once 'includes/functions.php';
requireLogin();
// Ensure columns used by this page exist (is_posted / posted_by / posted_at / swift_document)
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();
ensureRestrictedColumn();

// Get all vouchers (office-wide)
// Sorting by voucher number (pattern like PV/UGC/YYYY/NNN)
$sort = isset($_GET['sort']) ? strtolower($_GET['sort']) : 'newest';
if (!in_array($sort, ['newest', 'asc', 'desc'])) {
    $sort = 'newest';
}
$yearDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$seqDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$orderBy = "ORDER BY \n    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pv.voucher_no, '/', -2), '/', 1) AS UNSIGNED) $yearDir,\n    CAST(SUBSTRING_INDEX(pv.voucher_no, '/', -1) AS UNSIGNED) $seqDir";

$stmt = $pdo->prepare("
    SELECT pv.id, pv.voucher_no, pv.payee_name, pv.description, pv.currency,
        pv.total_amount, pv.status, pv.date_created, pv.prepared_by, pv.approved_by,
        IFNULL(pv.is_paid, 0) AS is_paid, IFNULL(pv.is_posted,0) AS is_posted, IFNULL(pv.is_restricted, 0) AS is_restricted,
        (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
        (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count,
        u.full_name AS creator_name, u.department AS creator_department, ua.role AS approver_role
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    LEFT JOIN users ua ON pv.approved_by = ua.id
    WHERE (pv.is_restricted = 0 OR pv.created_by = :myId OR :isAuth = 1)
    $orderBy
");
$stmt->bindValue(':myId', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->bindValue(':isAuth', (isAdmin() || isFinance()) ? 1 : 0, PDO::PARAM_INT);
$stmt->execute();
$vouchers = $stmt->fetchAll();

// Get unique prepared_by names for filter dropdown
$preparedByList = [];
foreach ($vouchers as $v) {
    $prep = trim((string) ($v['prepared_by'] ?? ''));
    if ($prep === '' && !empty($v['creator_name'])) {
        $prep = $v['creator_name'];
    }
    if (!empty($prep) && !in_array($prep, $preparedByList)) {
        $preparedByList[] = $prep;
    }
}
sort($preparedByList);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Vouchers - Ultimate General Trading</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Ensure header is sticky on this page */
        .header,
        .employee-header {
            position: -webkit-sticky !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 999 !important;
        }

        /* Page-local compaction for My Vouchers */
        body.dashboard .main-content {
            padding: 16px 14px;
        }

        body.dashboard .actions {
            margin-bottom: 16px;
        }

        body.dashboard .actions .btn {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 0;
        }

        body.dashboard .form-container {
            padding: 16px;
            border-radius: 0;
        }

        body.dashboard .form-container h2 {
            font-size: 16px;
            margin-bottom: 10px;
        }

        body.dashboard .form-container input[type="text"],
        body.dashboard .form-container select,
        body.dashboard .form-container input[type="date"],
        body.dashboard .form-container input[type="number"] {
            padding: 6px 8px;
            font-size: 12px;
            border-radius: 0;
        }

        body.dashboard .data-table {
            border-radius: 0;
            margin-bottom: 16px;
        }

        body.dashboard .data-table th {
            padding: 10px;
            font-size: 12px;
        }

        body.dashboard .data-table td {
            padding: 8px 10px;
            font-size: 12px;
        }

        .status-badge {
            font-size: 12px;
        }


        /* Search filters styling */
        .search-section {
            margin-bottom: 20px;
        }

        .search-primary {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .search-primary .search-input-group {
            flex: 1;
            min-width: 250px;
        }

        .search-primary .search-input-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .search-primary input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 0;
            font-size: 14px;
        }

        .filters-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
        }

        .filter-item label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-item input,
        .filter-item select {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 0;
            font-size: 13px;
            width: 100%;
        }

        .btn-clear {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            padding: 8px 16px;
            border-radius: 0;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-clear:hover {
            background: #e5e7eb;
            border-color: #9ca3af;
        }

        .sort-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sort-group label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
        }

        .sort-group select {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 0;
            font-size: 13px;
        }

        /* Mobile adjustments */
        @media (max-width: 640px) {
            .search-section {
                padding: 12px;
            }

            .search-primary {
                flex-direction: column;
                align-items: stretch;
            }

            .search-primary .search-input-group {
                min-width: 100%;
            }

            .filters-group {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .search-actions {
                flex-direction: column;
            }

            .search-actions button {
                width: 100%;
            }

            .sort-group {
                width: 100%;
                justify-content: space-between;
            }
        }

        /* Filter Toggle Button */
        .filter-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .filter-toggle-button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 0;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .filter-toggle-button:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .filter-toggle-button.active {
            background: #1e40af;
        }

        .filter-toggle-icon {
            transition: transform 0.3s ease;
            display: inline-block;
        }

        .filter-toggle-button.active .filter-toggle-icon {
            transform: rotate(180deg);
        }

        .filter-count {
            background: #ef4444;
            color: white;
            border-radius: 0;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
            display: none;
        }

        .filter-count.active {
            display: inline-block;
        }

        /* Advanced Filters - Hidden by default */
        .advanced-filters {
            display: none;
            animation: slideDown 0.3s ease;
        }

        .advanced-filters.show {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Show active filters indicator */
        .active-filters-info {
            font-size: 12px;
            color: #6b7280;
            margin-left: auto;
        }
    </style>
</head>

<body class="dashboard">
    <?php require_once 'includes/header_employee.php'; ?>

    <main class="main-content">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            <div class="toast-container no-print" aria-live="polite" aria-atomic="true">
                <div class="toast show" role="status">Voucher deleted permanently.</div>
            </div>
            <script>
                (function () {
                    function initToast() {
                        try {
                            // prevent message from reappearing on refresh/back navigation
                            var url = new URL(window.location.href);
                            if (url.searchParams.get('msg') === 'deleted') {
                                url.searchParams.delete('msg');
                                window.history.replaceState({}, document.title, url.toString());
                            }
                        } catch (e) { }
                        var t = document.querySelector('.toast');
                        if (!t) return;
                        // Auto-hide after 3 seconds
                        setTimeout(function () {
                            // ensure transition by toggling classes deterministically
                            t.classList.remove('show');
                            t.classList.add('hide');
                            // remove from DOM after transition (fallback at 600ms)
                            var done = false;
                            setTimeout(function () { if (done) return; done = true; var c = t && t.parentNode; if (c) { c.parentNode && c.parentNode.removeChild(c); } }, 600);
                            t.addEventListener('transitionend', function () { if (done) return; done = true; var c = t && t.parentNode; if (c) { c.parentNode && c.parentNode.removeChild(c); } }, { once: true });
                        }, 3000);
                    }
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initToast);
                    } else { initToast(); }
                })();
            </script>
            <?php endif; ?>
            <?php if (!empty($dbError)): ?>
            <div class="toast-container no-print" aria-live="polite" aria-atomic="true">
                <div class="toast show" role="status" style="background:#fef3c7; border:1px solid #f59e0b; color:#92400e;">A database error occurred. Please try again or contact support.</div>
            </div>
            <?php endif; ?>
        <div class="actions">
            <a href="dashboard.php" class="icon-link icon-neutral" title="Back" aria-label="Back">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </a>
            <a href="create-voucher.php" class="btn">Create New Voucher</a>
        </div>

        <div class="form-container">
            <h2>All Payment Vouchers</h2>

            <div class="search-section">
                <!-- Sort Option (Hidden, but functional) -->
                <div class="search-primary" style="display: none;">
                    <div class="sort-group">
                        <label for="sortVoucherNo">Sort:</label>
                        <select id="sortVoucherNo" onchange="applySort(this.value)">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Newest Last</option>
                        </select>
                    </div>
                </div>

                <!-- Hidden search input for functionality (synced with header) -->
                <input type="text" id="searchInput" style="display: none;"
                    onkeyup="performAdvancedSearch(); updateActiveFiltersCount();">

                <!-- Filter Toggle Button -->
                <div class="filter-toggle" style="border-bottom: none; padding-bottom: 0; margin-bottom: 0;">
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" class="filter-toggle-button" id="filterToggleBtn"
                            onclick="toggleFilters()">
                            <span>Show Filters</span>
                            <span class="filter-toggle-icon">â–¼</span>
                            <span class="filter-count" id="activeFilterCount"></span>
                        </button>
                        <button type="button" class="btn-clear" onclick="clearAllFilters()" style="display: none;"
                            id="clearFiltersBtn">
                            Clear All Filters
                        </button>
                    </div>
                    <span class="active-filters-info" id="activeFiltersInfo" style="display: none;"></span>
                </div>

                <!-- Filter Groups (Hidden by default) -->
                <div class="advanced-filters" id="advancedFilters">
                    <div class="filters-group">
                        <!-- Status Filter -->
                        <div class="filter-item">
                            <label for="filterStatus">Status</label>
                            <select id="filterStatus" onchange="performAdvancedSearch(); updateActiveFiltersCount();">
                                <option value="all">All Statuses</option>
                                <option value="confirming">Confirming</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="draft">Draft</option>
                                <option value="paid">Paid</option>
                                <option value="posted">Posted</option>
                            </select>
                        </div>

                        <!-- Date Filters -->
                        <div class="filter-item">
                            <label for="filterMonth">Month</label>
                            <select id="filterMonth" onchange="performAdvancedSearch(); updateActiveFiltersCount();">
                                <option value="all">All Months</option>
                                <option value="01">January</option>
                                <option value="02">February</option>
                                <option value="03">March</option>
                                <option value="04">April</option>
                                <option value="05">May</option>
                                <option value="06">June</option>
                                <option value="07">July</option>
                                <option value="08">August</option>
                                <option value="09">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label for="filterDate">Specific Date</label>
                            <input type="date" id="filterDate"
                                onchange="performAdvancedSearch(); updateActiveFiltersCount();">
                        </div>

                        <!-- Amount Filter -->
                        <div class="filter-item">
                            <label for="filterAmount">Minimum Amount</label>
                            <input type="number" id="filterAmount" placeholder="0.00" step="0.01" min="0"
                                onkeyup="performAdvancedSearch(); updateActiveFiltersCount();"
                                onchange="updateActiveFiltersCount();">
                        </div>

                        <!-- Prepared By Filter -->
                        <div class="filter-item">
                            <label for="filterPreparedBy">Prepared By</label>
                            <select id="filterPreparedBy"
                                onchange="performAdvancedSearch(); updateActiveFiltersCount();">
                                <option value="all">All Preparers</option>
                                <?php foreach ($preparedByList as $prep): ?>
                                    <option value="<?= htmlspecialchars($prep) ?>"><?= htmlspecialchars($prep) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($vouchers)): ?>
                <p>No vouchers found.</p>
            <?php else: ?>
                <div class="table-wrap stacked-table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Voucher No.</th>
                                <th>Payee</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Prepared By</th>
                                <th>Status</th>
                                <?php $isFinanceUser = isFinance(); ?>
                                <?php if ($isFinanceUser): ?>
                                    <th>Paid</th><?php endif; ?>
                                <th>Docs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1;
                            foreach ($vouchers as $voucher):
                                // Prepare data for search attributes
                                $prep = trim((string) ($voucher['prepared_by'] ?? ''));
                                if ($prep === '' && !empty($voucher['creator_name'])) {
                                    $prep = $voucher['creator_name'];
                                }
                                $prepDisplay = $prep !== '' ? $prep : 'â€”';
                                $prepDisplayFull = $prep !== '' ? ($prep . (!empty($voucher['creator_department']) ? ' (' . $voucher['creator_department'] . ')' : '')) : 'â€”';

                                $isPaidFlag = (int) ($voucher['is_paid'] ?? 0) === 1;
                                $isPostedFlag = (int) ($voucher['is_posted'] ?? 0) === 1;
                                $derivedStatus = $voucher['status'];
                                $looksDraft = !$isPaidFlag
                                    && strtolower($voucher['status']) === STATUS_PENDING
                                    && (
                                        empty($voucher['payee_name'])
                                        || (float) $voucher['total_amount'] <= 0
                                        || (int) ($voucher['item_count'] ?? 0) === 0
                                    );
                                if ($looksDraft) {
                                    $derivedStatus = STATUS_DRAFT;
                                }

                                $finalStatus = $isPostedFlag ? 'posted' : ($isPaidFlag ? 'paid' : strtolower($derivedStatus));
                                $dateCreated = strtotime($voucher['date_created']);
                                $month = date('m', $dateCreated);
                                $dateFormatted = date('Y-m-d', $dateCreated);

                                // Access Control Logic
                                $isRestricted = !empty($voucher['is_restricted']) && $voucher['is_restricted'] == 1;
                                $canView = isAdmin() || isFinance() || ($voucher['created_by'] ?? 0) == $_SESSION['user_id'];

                                $description = strtolower(htmlspecialchars($voucher['description'] ?? ''));
                                if ($isRestricted && !$canView) {
                                    $description = '<span style="color:#b91c1c; font-style:italic;">(Restricted Content)</span>';
                                }
                                ?>
                                <tr data-description="<?= $isRestricted && !$canView ? '' : htmlspecialchars($description, ENT_QUOTES) ?>"
                                    data-month="<?= $month ?>" data-date="<?= $dateFormatted ?>"
                                    data-amount="<?= (float) $voucher['total_amount'] ?>"
                                    data-prepared-by="<?= htmlspecialchars(strtolower($prep), ENT_QUOTES) ?>"
                                    data-status="<?= $finalStatus ?>" data-status-display="<?= $finalStatus ?>">
                                    <td><?= $sn++ ?></td>
                                    <td>
                                        <?= htmlspecialchars($voucher['voucher_no']) ?>
                                        <?php if ($isRestricted): ?>
                                            <span title="Restricted/Confidential" style="font-size:12px; cursor:help;">🔒</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($voucher['payee_name']) ?></td>
                                    <td><?= $isRestricted && !$canView ? $description : (htmlspecialchars(substr($voucher['description'], 0, 50)) . (strlen($voucher['description']) > 50 ? '...' : '')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($voucher['currency']) ?>
                                        <?= number_format($voucher['total_amount'], 2) ?>
                                    </td>
                                    <td><?= date('d/m/Y', $dateCreated) ?></td>
                                    <td><?= htmlspecialchars($prepDisplayFull) ?></td>
                                    <td>
                                        <?php if ($isPostedFlag): ?>
                                            <span class="status-badge" style="color:#facc15;">Posted</span>
                                        <?php elseif ($isPaidFlag): ?>
                                            <span class="status-badge status-approved">Paid</span>
                                        <?php else: ?>
                                            <span
                                                class="status-badge <?= 'status-' . htmlspecialchars($derivedStatus) ?>"><?php echo ucfirst($derivedStatus); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isFinanceUser): ?>
                                        <td>
                                            <?php $isPaidFlagDisplay = (int) ($voucher['is_paid'] ?? 0) === 1; ?>
                                            <span class="status-badge"
                                                style="background:<?= $isPaidFlagDisplay ? '#16a34a' : '#9ca3af' ?>; color:#fff;">
                                                <?= $isPaidFlagDisplay ? 'Paid' : 'Unpaid' ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($isRestricted && !$canView): ?>
                                            <span style="font-size:11px; color:#999; cursor:not-allowed;"
                                                title="Restricted">🔒</span>
                                        <?php else: ?>
                                            <?php $ac = (int) ($voucher['attachment_count'] ?? 0);
                                            if ($ac > 0): ?>
                                                <a href="view-voucher.php?id=<?= (int) $voucher['id'] ?>#attachments"
                                                    class="icon-link icon-neutral"
                                                    title="View <?= $ac ?> attachment<?= $ac > 1 ? 's' : '' ?>"
                                                    aria-label="View attachments" style="margin-right:6px;">
                                                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                        aria-hidden="true">
                                                        <path
                                                            d="M17.657 6.343a4.5 4.5 0 010 6.364l-7.071 7.071a3 3 0 01-4.243-4.243l7.07-7.071a1.5 1.5 0 012.122 2.122l-7.07 7.071"
                                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round" />
                                                    </svg>
                                                    <span
                                                        style="font-size:10px; margin-left:2px; vertical-align:middle;"><?= $ac ?></span>
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:11px; color:#666;">0</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canView): ?>
                                            <a href="view-voucher.php?id=<?= $voucher['id'] ?>" class="icon-link icon-neutral"
                                                title="View" aria-label="View voucher" style="margin-right:6px;">
                                                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    focusable="false" aria-hidden="true">
                                                    <path
                                                        d="M12 5c-7.633 0-11 7-11 7s3.367 7 11 7 11-7 11-7-3.367-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
                                                </svg>
                                            </a>
                                        <?php else: ?>
                                            <span class="icon-link icon-neutral" title="Restricted Access"
                                                style="opacity:0.3; cursor:not-allowed; margin-right:6px;">
                                                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    focusable="false" aria-hidden="true">
                                                    <path
                                                        d="M12 17c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-9h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM8.9 6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2H8.9V6z" />
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($voucher['status'] === 'pending' && canEditVoucher($voucher['id'], $_SESSION['user_id'])): ?>
                                            <a href="edit-voucher.php?id=<?= $voucher['id'] ?>" class="icon-link icon-black"
                                                title="Edit" aria-label="Edit voucher" style="margin-right:6px;">
                                                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                    focusable="false" aria-hidden="true">
                                                    <path
                                                        d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm14.71-9.21a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($voucher['status'] !== STATUS_APPROVED && canDeleteVoucher($voucher['id'], $_SESSION['user_id'])): ?>
                                            <form method="POST" action="delete-voucher.php" style="display:inline-block;"
                                                onsubmit="return confirm('Delete this voucher? This cannot be undone.');">
                                                <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
                                                <button type="submit" class="icon-btn icon-danger" title="Delete"
                                                    aria-label="Delete voucher">
                                                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                        focusable="false" aria-hidden="true">
                                                        <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none"
                                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round" />
                                                        <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2"
                                                            stroke-linecap="round" stroke-linejoin="round" />
                                                        <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2"
                                                            stroke-linecap="round" stroke-linejoin="round" />
                                                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none"
                                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php
                                        $canFinance = isFinance();
                                        ?>
                                        <?php if ($canFinance && $isPaidFlag && !$isPostedFlag): ?>
                                            <form method="POST" action="mark-posted.php"
                                                style="display:inline-block; margin-left:6px;"
                                                onsubmit="return confirm('Mark this voucher as POSTED?');">
                                                <input type="hidden" name="voucher_id" value="<?= (int) $voucher['id'] ?>">
                                                <button type="submit" class="mark-paid-link" style="color:#0d6efd;">Mark
                                                    Posted</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php
                                        $statusLower = strtolower((string) $voucher['status']);
                                        $approvedByAdmin = !empty($voucher['approved_by']) && ($voucher['approver_role'] === ROLE_ADMIN);
                                        $canShowMarkPaid = $canFinance && !$isPaidFlag && $statusLower === STATUS_APPROVED && $approvedByAdmin;
                                        ?>
                                        <?php if ($canShowMarkPaid): ?>
                                            <button type="button" class="mark-paid-link" style="margin-left:6px;"
                                                onclick="openSwiftModal(<?= (int) $voucher['id'] ?>)">Mark Paid</button>
                                        <?php elseif ($canFinance && !$isPaidFlag): ?>
                                            <span style="margin-left:6px; font-size:11px; color:#6b7280;">Awaiting admin
                                                approval</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php
    // Determine dynamic max from server for client-side hint/validation
    function _pv_toBytes($v)
    {
        $v = trim((string) $v);
        if ($v === '')
            return 0;
        $u = strtolower(substr($v, -1));
        $n = (float) $v;
        switch ($u) {
            case 'g':
                $n *= 1024;
            case 'm':
                $n *= 1024;
            case 'k':
                $n *= 1024;
        }
        return (int) round($n);
    }
    $swiftMaxBytes = min(max(1, _pv_toBytes(ini_get('upload_max_filesize') ?: '10M')), max(1, _pv_toBytes(ini_get('post_max_size') ?: '10M')));
    $swiftMaxMB = max(1, floor($swiftMaxBytes / 1024 / 1024));
    ?>
    <div id="swiftModal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2000; align-items:center; justify-content:center;">
        <div
            style="background:#fff; padding:24px; width:100%; max-width:420px; border:1px solid #111; border-radius:0; box-shadow:0 10px 35px rgba(0,0,0,.35); position:relative;">
            <h3 style="margin:0 0 12px; font-size:18px;">Attach SWIFT Proof</h3>
            <p style="margin:0 0 12px; font-size:13px; line-height:1.4; color:#374151;">Before marking this voucher as
                paid, please upload the SWIFT payment proof (PDF or image, max <?= (int) $swiftMaxMB ?>MB).</p>
            <form id="swiftForm" method="POST" action="mark-paid.php" enctype="multipart/form-data"
                onsubmit="return submitSwiftForm()">
                <input type="hidden" name="voucher_id" id="swiftVoucherId" value="">
                <input type="file" name="swift_file" id="swiftFile" accept="application/pdf,image/*" required
                    style="display:block; margin-bottom:14px;">
                <div id="swiftError" style="color:#b91c1c; font-size:12px; margin:4px 0 10px; display:none;"></div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" onclick="closeSwiftModal()"
                        style="background:#9ca3af; color:#fff; border:1px solid #9ca3af; padding:8px 14px; font-size:12px; cursor:pointer; border-radius:0;">Cancel</button>
                    <button type="submit"
                        style="background:#111; color:#fff; border:1px solid #111; padding:8px 14px; font-size:12px; cursor:pointer; border-radius:0;">Upload
                        & Mark Paid</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/voucher-v5.js?v=9"></script>
    <script>
        function applySort(val) {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', val);
            window.location.href = url.toString();
        }

        // Advanced search function that filters by all criteria
        function performAdvancedSearch() {
            const searchInput = document.getElementById('searchInput');
            const filterStatus = document.getElementById('filterStatus');
            const filterMonth = document.getElementById('filterMonth');
            const filterDate = document.getElementById('filterDate');
            const filterAmount = document.getElementById('filterAmount');
            const filterPreparedBy = document.getElementById('filterPreparedBy');
            const table = document.querySelector('.data-table tbody');

            if (!table) return;

            const rows = table.getElementsByTagName('tr');
            const searchText = (searchInput.value || '').toLowerCase().trim();
            const statusFilter = filterStatus.value || 'all';
            const monthFilter = filterMonth.value || 'all';
            const dateFilter = filterDate.value || '';
            const amountFilter = parseFloat(filterAmount.value) || 0;
            const preparedByFilter = (filterPreparedBy.value || 'all').toLowerCase();

            // Remove any existing no-results messages first
            const existingMessages = table.querySelectorAll('.no-results-message');
            existingMessages.forEach(msg => msg.remove());

            let visibleCount = 0;

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                // Skip message rows
                if (row.classList.contains('no-results-message')) {
                    continue;
                }

                let showRow = true;

                // Filter by description/keywords
                if (searchText) {
                    const description = (row.getAttribute('data-description') || '').toLowerCase();
                    if (description.indexOf(searchText) === -1) {
                        showRow = false;
                    }
                }

                // Filter by status
                if (showRow && statusFilter !== 'all') {
                    const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
                    if (rowStatus !== statusFilter) {
                        showRow = false;
                    }
                }

                // Filter by month
                if (showRow && monthFilter !== 'all') {
                    const rowMonth = row.getAttribute('data-month') || '';
                    if (rowMonth !== monthFilter) {
                        showRow = false;
                    }
                }

                // Filter by date
                if (showRow && dateFilter) {
                    const rowDate = row.getAttribute('data-date') || '';
                    if (rowDate !== dateFilter) {
                        showRow = false;
                    }
                }

                // Filter by amount (minimum)
                if (showRow && amountFilter > 0) {
                    const rowAmount = parseFloat(row.getAttribute('data-amount') || 0);
                    if (rowAmount < amountFilter) {
                        showRow = false;
                    }
                }

                // Filter by prepared by
                if (showRow && preparedByFilter !== 'all') {
                    const rowPreparedBy = (row.getAttribute('data-prepared-by') || '').toLowerCase().trim();
                    // Check if the prepared by contains the filter text (case-insensitive partial match)
                    if (rowPreparedBy.indexOf(preparedByFilter) === -1) {
                        showRow = false;
                    }
                }

                // Show or hide row
                if (showRow) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }

            // Update table message if no results
            updateTableMessage(visibleCount);
        }

        // Toggle filters visibility
        function toggleFilters() {
            const filtersDiv = document.getElementById('advancedFilters');
            const toggleBtn = document.getElementById('filterToggleBtn');
            const clearBtn = document.getElementById('clearFiltersBtn');
            const toggleText = toggleBtn.querySelector('span:first-child');

            if (filtersDiv.classList.contains('show')) {
                filtersDiv.classList.remove('show');
                toggleBtn.classList.remove('active');
                toggleText.textContent = 'Show Filters';
                if (clearBtn) clearBtn.style.display = 'none';
            } else {
                filtersDiv.classList.add('show');
                toggleBtn.classList.add('active');
                toggleText.textContent = 'Hide Filters';
                if (clearBtn) clearBtn.style.display = 'inline-block';
            }
        }

        // Update active filters count
        function updateActiveFiltersCount() {
            const filterStatus = document.getElementById('filterStatus').value;
            const filterMonth = document.getElementById('filterMonth').value;
            const filterDate = document.getElementById('filterDate').value;
            const filterAmount = document.getElementById('filterAmount').value;
            const filterPreparedBy = document.getElementById('filterPreparedBy').value;
            const searchInput = document.getElementById('searchInput').value;
            const clearBtn = document.getElementById('clearFiltersBtn');

            let activeCount = 0;
            const activeFilters = [];

            if (searchInput.trim() !== '') {
                activeCount++;
                activeFilters.push('Search');
            }
            if (filterStatus !== 'all') {
                activeCount++;
                activeFilters.push('Status: ' + filterStatus);
            }
            if (filterMonth !== 'all') {
                activeCount++;
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];
                activeFilters.push('Month: ' + monthNames[parseInt(filterMonth) - 1]);
            }
            if (filterDate !== '') {
                activeCount++;
                activeFilters.push('Date: ' + filterDate);
            }
            if (filterAmount !== '' && parseFloat(filterAmount) > 0) {
                activeCount++;
                activeFilters.push('Amount: â‰¥' + filterAmount);
            }
            if (filterPreparedBy !== 'all') {
                activeCount++;
                activeFilters.push('Prepared By: ' + filterPreparedBy);
            }

            const countBadge = document.getElementById('activeFilterCount');
            const infoText = document.getElementById('activeFiltersInfo');

            if (activeCount > 0) {
                countBadge.textContent = activeCount;
                countBadge.classList.add('active');
                infoText.textContent = activeFilters.join(', ');
                infoText.style.display = 'block';
                // Show clear button if filters are active
                if (clearBtn) clearBtn.style.display = 'inline-block';
            } else {
                countBadge.classList.remove('active');
                infoText.style.display = 'none';
                // Hide clear button only if filters panel is closed and no active filters
                const filtersDiv = document.getElementById('advancedFilters');
                if (clearBtn && !filtersDiv.classList.contains('show')) {
                    clearBtn.style.display = 'none';
                }
            }
        }

        // Clear all filters
        function clearAllFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterStatus').value = 'all';
            document.getElementById('filterMonth').value = 'all';
            document.getElementById('filterDate').value = '';
            document.getElementById('filterAmount').value = '';
            document.getElementById('filterPreparedBy').value = 'all';
            performAdvancedSearch();
            updateActiveFiltersCount();
        }

        // Update table message based on visible rows
        function updateTableMessage(visibleCount) {
            const table = document.querySelector('.data-table tbody');
            if (!table) return;

            if (visibleCount === 0) {
                // Find first data row to get column count
                const firstRow = table.querySelector('tr:not(.no-results-message)');
                if (firstRow) {
                    const colCount = firstRow.cells.length;
                    const messageRow = document.createElement('tr');
                    messageRow.className = 'no-results-message';
                    messageRow.innerHTML = '<td colspan="' + colCount + '" style="text-align:center; padding:30px; color:#666; font-style:italic; background:#f9fafb;">No vouchers match your search criteria. Try adjusting your filters.</td>';
                    table.appendChild(messageRow);
                }
            }
        }

        // Sync header search with page search
        function syncHeaderSearch() {
            const headerSearchInput = document.getElementById('headerSearchInput');
            const pageSearchInput = document.getElementById('searchInput');

            if (headerSearchInput && pageSearchInput) {
                // Override the header's handleHeaderSearch to use our sync
                // Sync header -> page on every input change
                headerSearchInput.addEventListener('input', function () {
                    pageSearchInput.value = this.value;
                    performAdvancedSearch();
                    updateActiveFiltersCount();
                });

                // Also sync on keyup for immediate feedback
                headerSearchInput.addEventListener('keyup', function () {
                    if (pageSearchInput.value !== this.value) {
                        pageSearchInput.value = this.value;
                        performAdvancedSearch();
                        updateActiveFiltersCount();
                    }
                });
            }
        }

        // Initialize search on page load
        document.addEventListener('DOMContentLoaded', function () {
            performAdvancedSearch();
            updateActiveFiltersCount();
            syncHeaderSearch();

            // Show clear button if filters panel should be open
            const filtersDiv = document.getElementById('advancedFilters');
            const clearBtn = document.getElementById('clearFiltersBtn');
            const activeCount = document.getElementById('activeFilterCount');

            // If filters are active, show the filters panel and clear button
            if (activeCount.classList.contains('active')) {
                toggleFilters();
            } else if (filtersDiv && filtersDiv.classList.contains('show') && clearBtn) {
                // If panel is open (for any reason), show clear button
                clearBtn.style.display = 'inline-block';
            }
        });

        const SWIFT_MAX_BYTES = <?= (int) $swiftMaxBytes ?>;
        function openSwiftModal(voucherId) {
            var m = document.getElementById('swiftModal');
            if (!m) return; document.getElementById('swiftVoucherId').value = voucherId;
            document.getElementById('swiftFile').value = '';
            document.getElementById('swiftError').style.display = 'none';
            m.style.display = 'flex';
        }
        function closeSwiftModal() { var m = document.getElementById('swiftModal'); if (m) m.style.display = 'none'; }
        function submitSwiftForm() {
            var f = document.getElementById('swiftFile'); var err = document.getElementById('swiftError');
            if (!f || !f.files || f.files.length === 0) { err.textContent = 'Please choose a SWIFT proof file.'; err.style.display = 'block'; return false; }
            var file = f.files[0];
            var allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
            var ext = file.name.split('.').pop().toLowerCase();
            if (allowedExt.indexOf(ext) === -1) { err.textContent = 'Invalid file type. Allowed: pdf, jpg, jpeg, png, gif, webp.'; err.style.display = 'block'; return false; }
            if (file.size === 0) { err.textContent = 'File is empty.'; err.style.display = 'block'; return false; }
            if (file.size > SWIFT_MAX_BYTES) { err.textContent = 'File exceeds the current server limit (max ~<?= (int) $swiftMaxMB ?>MB).'; err.style.display = 'block'; return false; }
            return true;
        }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSwiftModal(); } });
        document.addEventListener('click', function (e) { var m = document.getElementById('swiftModal'); if (m && e.target === m) { closeSwiftModal(); } });
    </script>
    <script>
        // Fetch users for WhatsApp notification
        <?php
        $stmtWa = $pdo->prepare("SELECT full_name, role, whatsapp_number FROM users WHERE whatsapp_number IS NOT NULL AND whatsapp_number != '' ORDER BY full_name ASC");
        $stmtWa->execute();
        $waUsers = $stmtWa->fetchAll(PDO::FETCH_ASSOC);
        ?>
    </script>

    <!-- Notify Modal -->
    <div id="notifyModal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div
            style="background:white; padding:20px; border-radius:8px; width:100%; max-width:400px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
            <h3 style="margin-top:0; margin-bottom:15px; font-size:18px;">Notify User</h3>

            <div style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px; font-size:12px; font-weight:600;">Select User</label>
                <select id="notifyUserSelect" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;"
                    onchange="updateNotifyPreview()">
                    <option value="">-- Choose User --</option>
                    <?php foreach ($waUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['whatsapp_number']) ?>"
                            data-name="<?= htmlspecialchars($u['full_name']) ?>">
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px; font-size:12px; font-weight:600;">Message
                    Preview</label>
                <textarea id="notifyMessagePreview" rows="4"
                    style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; font-size:12px; background:#f9fafb;"
                    readonly></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button onclick="closeNotifyModal()"
                    style="padding:6px 12px; border:1px solid #ddd; background:white; cursor:pointer; border-radius:4px; font-size:12px;">Cancel</button>
                <button onclick="sendToGroup()" class="btn"
                    style="padding:6px 12px; border:none; background:#128C7E; color:white; cursor:pointer; border-radius:4px; display:inline-flex; align-items:center; font-size:12px;">
                    Send to Group
                </button>
                <a id="notifySendBtn" href="#" target="_blank" class="btn"
                    style="padding:6px 12px; background:#25D366; color:white; text-decoration:none; display:inline-flex; align-items:center; opacity:0.5; pointer-events:none; font-size:12px; border-radius:4px;">
                    Send WhatsApp
                </a>
            </div>
        </div>
    </div>

    <script>
        let currentVoucherData = null;

        function openNotifyModal(voucherData) {
            currentVoucherData = voucherData;
            document.getElementById('notifyModal').style.display = 'flex';
            document.getElementById('notifyUserSelect').value = '';
            document.getElementById('notifyMessagePreview').value = '';
            updateNotifyPreview();
        }

        function closeNotifyModal() {
            document.getElementById('notifyModal').style.display = 'none';
        }

        function sendToGroup() {
            if (!currentVoucherData) return;
            const msg = `Hello Team,\n\nPlease review/check this voucher:\n\nVoucher No: ${currentVoucherData.voucher_no}\nPayee: ${currentVoucherData.payee}\nAmount: ${currentVoucherData.amount}\n\nLink: ${currentVoucherData.link}`;
            document.getElementById('notifyMessagePreview').value = msg;
            const encodedMsg = encodeURIComponent(msg);
            window.open(`https://wa.me/?text=${encodedMsg}`, '_blank');
        }

        function updateNotifyPreview() {
            const select = document.getElementById('notifyUserSelect');
            const btn = document.getElementById('notifySendBtn');
            const selectedOption = select.options[select.selectedIndex];
            const phone = select.value;

            if (!phone || !currentVoucherData) {
                btn.style.opacity = '0.5';
                btn.style.pointerEvents = 'none';
                document.getElementById('notifyMessagePreview').value = 'Select a user to view message.';
                return;
            }

            const userName = selectedOption.getAttribute('data-name');
            const msg = `Hello ${userName},\n\nPlease review/check this voucher:\n\nVoucher No: ${currentVoucherData.voucher_no}\nPayee: ${currentVoucherData.payee}\nAmount: ${currentVoucherData.amount}\n\nLink: ${currentVoucherData.link}`;

            document.getElementById('notifyMessagePreview').value = msg;

            const encodedMsg = encodeURIComponent(msg);
            btn.href = `https://wa.me/${phone}?text=${encodedMsg}`;
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        }

        // Close on outside click
        document.getElementById('notifyModal').addEventListener('click', function (e) {
            if (e.target === this) closeNotifyModal();
        });
    </script>
</body>

</html>