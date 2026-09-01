<?php
// Enable error reporting for debugging on shared hosting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../includes/functions.php';
require_once '../modules/balances/functions.php'; // Balances Module Integration
require_once '../includes/accounting_service.php';
requireAdmin();
// Ensure voucher tables/columns exist before any queries (production may lack imported ERP tables).
if (function_exists('ensurePaymentVouchersCoreSchema')) {
    ensurePaymentVouchersCoreSchema();
}
if (function_exists('ensureBalancesSchema')) {
    ensureBalancesSchema();
}
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

$scanReport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['security_scan'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } else {
    // Lightweight integrity scan: list suspicious files and PHP inside uploads
    $root = realpath(dirname(__DIR__));
    $suspicious = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
      $path = $file->getPathname();
      $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
      // Skip known folders that are blocked or large
      // Normalize path separators for portable checks
      $nrel = str_replace('\\', '/', $rel);
      // Skip large/irrelevant directories using fast prefix tests (avoids regex compilation issues on some hosts)
      if (strpos($nrel, '.git/') === 0 || strpos($nrel, 'laravel-core/') === 0 || strpos($nrel, 'tasks/') === 0 || strpos($nrel, 'vendor/') === 0) {
        continue;
      }
      // Flag any PHP-like file inside uploads/signatures/images directories (no regex needed)
      if (
        (strpos($nrel, 'assets/uploads/') === 0 || strpos($nrel, 'assets/signatures/') === 0 || strpos($nrel, 'assets/images/') === 0)
        && preg_match('/\.(php|phtml|php7|phar)$/i', $nrel)
      ) {
        $suspicious[] = ['type' => 'php-in-upload', 'path' => $rel];
        continue;
      }
      // Scan PHP for obfuscated payload signatures
      if ($file->isFile() && preg_match('/\.php[0-9]*$/i', $path)) {
        $size = $file->getSize();
        if ($size > 0 && $size < 512 * 1024) { // skip huge files
          $code = @file_get_contents($path, false, null, 0, 200000);
          if ($code !== false) {
            if (preg_match('/base64_decode\s*\(/i', $code) && preg_match('/eval\s*\(/i', $code)) {
              $suspicious[] = ['type' => 'eval-base64', 'path' => $rel];
              continue;
            }
            if (preg_match('/(shell_exec|passthru|system)\s*\(/i', $code)) {
              $suspicious[] = ['type' => 'shell-call', 'path' => $rel];
              continue;
            }
            if (preg_match('/gzinflate|gzuncompress|str_rot13/i', $code) && preg_match('/eval\s*\(/i', $code)) {
              $suspicious[] = ['type' => 'compressed-eval', 'path' => $rel];
              continue;
            }
          }
        }
      }
    }
    $scanReport = $suspicious;
    if (empty($suspicious)) {
      $success = 'Security scan: no suspicious files found.';
    }
  }
}

$success = $error = null;

// Handle approval / rejection / delete / mark paid / mark posted (React desk posts here)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_id'])) {
  $voucher_id = (int) $_POST['voucher_id'];

  // Handle mark_paid and mark_posted separately
  if (isset($_POST['mark_paid']) && $_POST['mark_paid'] == '1') {
    $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $swift_path = null;

    // --- Handle SWIFT Document Upload ---
    if (isset($_FILES['swift_file']) && $_FILES['swift_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/vouchers/' . $voucher_id . '/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = 'swift_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['swift_file']['name']);
        if (move_uploaded_file($_FILES['swift_file']['tmp_name'], $upload_dir . $filename)) {
            $swift_path = 'assets/uploads/vouchers/' . $voucher_id . '/' . $filename;
        }
    }

    try {
        $pdo->beginTransaction();

        $stmtV = $pdo->prepare("SELECT voucher_no, payee_name, total_amount FROM payment_vouchers WHERE id = ? FOR UPDATE");
        $stmtV->execute([$voucher_id]);
        $v = $stmtV->fetch();
        if (!$v) throw new Exception("Voucher not found.");

        // Mark Paid first (this does its own checks, but we're already in a transaction handled by our own manual control if we want, OR we can let it handle its own)
        // Actually markVoucherPaidStrict starts its own transaction. To nest them, we need to be careful.
        // I'll call it first, if OK, then record balance.
        
        $pdo->rollBack(); // Release search lock to let the function do its job
        
        $result = markVoucherPaidStrict($voucher_id, (int) $_SESSION['user_id']);
        if (!$result['ok']) throw new Exception($result['error']);

        if ($account_id && $account_id > 0) {
            $desc = "Payment for Voucher #{$v['voucher_no']} to {$v['payee_name']}";
            if (!recordTransaction($account_id, 'debit', $v['total_amount'], $desc, 'payment_voucher', $voucher_id)) {
                throw new Exception("Balance deduction failed.");
            }
        }

        if ($swift_path || ($account_id && $account_id > 0)) {
            $upd = $pdo->prepare("UPDATE payment_vouchers SET swift_document = ?, payment_account_id = ? WHERE id = ?");
            $upd->execute([$swift_path, $account_id, $voucher_id]);
        }
        
        $newBal = 0; $currency = 'TZS';
        if($account_id) {
            $aStmt = $pdo->prepare("SELECT current_balance, currency FROM financial_accounts WHERE id = ?");
            $aStmt->execute([$account_id]);
            $acc = $aStmt->fetch();
            if($acc) { $newBal = $acc['current_balance']; $currency = $acc['currency']; }
        }
        $_SESSION['success_msg'] = "Voucher marked as paid. Deducted: {$currency} " . number_format($v['total_amount'], 2) . ". New Balance: {$currency} " . number_format($newBal, 2);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

      // --- UNIFIED LEDGER: POST TO GENERAL LEDGER ON PAYMENT ---
      try {
          $accSvc = new AccountingService($pdo);
          
          // Use selected account or default to Operating Bank (1001)
          $creditAccId = $account_id;
          if (!$creditAccId) {
              $creditAccId = $accSvc->getAccountIdByCode('1001');
          }
          
          // Fetch voucher details for the entry
          $vStmt = $pdo->prepare("SELECT voucher_no, payee_name, total_amount FROM payment_vouchers WHERE id = ?");
          $vStmt->execute([$voucher_id]);
          $vDet = $vStmt->fetch();

          if ($vDet) {
              // Fetch voucher items to determine expense accounts
              $itemStmt = $pdo->prepare("SELECT budget_type, amount, name FROM voucher_items WHERE voucher_id = ?");
              $itemStmt->execute([$voucher_id]);
              $vItems = $itemStmt->fetchAll();
              
              $journalPostings = [];
              $totalVoucherAmount = 0;
              
              foreach ($vItems as $vi) {
                  $accCode = $accSvc->mapBudgetToAccountCode($vi['budget_type']);
                  $expenseAccId = $accSvc->getAccountIdByCode($accCode);
                  
                  if ($expenseAccId) {
                      $journalPostings[] = [
                          'account_id' => $expenseAccId,
                          'debit' => $vi['amount'],
                          'credit' => 0
                      ];
                      $totalVoucherAmount += $vi['amount'];
                  }
              }
              
              if ($creditAccId && $totalVoucherAmount > 0) {
                  $journalPostings[] = [
                      'account_id' => $creditAccId,
                      'debit' => 0,
                      'credit' => $totalVoucherAmount
                  ];
                  
                  $vNumber = (string)($vDet['voucher_no'] ?? '#' . $voucher_id);
                  $accSvc->postEntry(
                      date('Y-m-d'),
                      $vNumber,
                      "Voucher Payment: $vNumber - " . ($vDet['payee_name'] ?? 'Multiple Items'),
                      $journalPostings
                  );
              }
          }
      } catch (Exception $eAcc) {
          error_log("Ledger Posting Failed for Voucher Payment $voucher_id: " . $eAcc->getMessage());
      }
      // --- END UNIFIED LEDGER ---
  } elseif (isset($_POST['mark_posted']) && $_POST['mark_posted'] == '1') {
    $result = markVoucherPosted($voucher_id, (int) $_SESSION['user_id']);
    if ($result['ok']) {
      $success = 'Voucher marked as posted.';
    } else {
      $error = 'Error: ' . ($result['error'] ?? 'Failed to mark voucher as posted.');
    }
  } elseif (isset($_POST['action']) && !empty($_POST['action'])) {
    $action = $_POST['action'];
    $comments = trim($_POST['comments'] ?? '');

    if (!in_array($action, ['approved', 'rejected', 'delete'], true)) {
      $error = 'Invalid action.';
    } else if ($action === 'delete') {
      // Admin-only hard delete
      try {
        $del = $pdo->prepare('DELETE FROM payment_vouchers WHERE id = ?');
        $del->execute([$voucher_id]);
        $success = 'Voucher deleted permanently.';
      } catch (Exception $e) {
        $error = 'Error deleting voucher: ' . $e->getMessage();
      }
    } else {
      // Approve or reject
      // Guard: block approving obvious drafts
      if ($action === 'approved') {
        try {
          $chk = $pdo->prepare("SELECT pv.status, pv.payee_name, pv.total_amount, pv.voucher_no, pv.approved_by,
          (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
          IFNULL(pv.is_paid,0) AS is_paid
          FROM payment_vouchers pv WHERE pv.id = ? LIMIT 1");
          $chk->execute([$voucher_id]);
          $row = $chk->fetch();
          if ($row) {
            $isPaidFlag = (int) ($row['is_paid'] ?? 0) === 1;
            $looksDraft = !$isPaidFlag
              && strtolower($row['status']) === STATUS_PENDING
              && (empty($row['payee_name']) || (float) $row['total_amount'] <= 0 || (int) $row['item_count'] === 0);
            if ($looksDraft) {
              $error = 'Cannot approve a draft voucher. Please ask the creator to complete it first.';
              $action = null; // neutralize
            } else {
              // Anomaly guard: Voucher already paid before admin approval -> block approval and notify
              $notApprovedYet = strtolower($row['status']) !== STATUS_APPROVED;
              if ($isPaidFlag && $notApprovedYet) {
                $error = 'Approval blocked: Voucher is already marked as PAID before admin approval.';
                $action = null; // neutralize
                // Notifications: alert admins and finance users
                try {
                  $vno = (string) ($row['voucher_no'] ?? ('#' . $voucher_id));
                  createNotification([
                    'user_id' => null,
                    'audience' => 'admin',
                    'title' => 'Anomaly: Paid before approval',
                    'message' => sprintf('Voucher %s is marked PAID but not approved. Approval was blocked for investigation.', $vno),
                    'voucher_id' => $voucher_id,
                  ]);
                  // Notify all finance users individually
                  $fu = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND LOWER(department) = 'finance'");
                  $fu->execute();
                  $financeUsers = $fu->fetchAll();
                  if ($financeUsers) {
                    foreach ($financeUsers as $urow) {
                      createNotification([
                        'user_id' => (int) $urow['id'],
                        'audience' => 'user',
                        'title' => 'Approval blocked',
                        'message' => sprintf('Voucher %s: approval blocked because it was paid before admin approval. Please coordinate with Admin.', $vno),
                        'voucher_id' => $voucher_id,
                      ]);
                    }
                  }
                } catch (Exception $eN) { /* ignore notify errors */
                }
              }
            }
          }
        } catch (Exception $eDraft) {
          error_log('Draft check failed: ' . $eDraft->getMessage());
        }
      }
      if ($action !== null) {
        try {
          $pdo->beginTransaction();

          if ($action === 'approved') {
            // Map approver to GM name per business rule
            $gmName = null;
            try {
              $approverStmt = $pdo->prepare('SELECT username, email, full_name FROM users WHERE id = ? LIMIT 1');
              $approverStmt->execute([$_SESSION['user_id']]);
              $approver = $approverStmt->fetch();
              $approverEmail = strtolower(trim($approver['email'] ?? ''));
              $approverUsername = trim($approver['username'] ?? '');
              $approverFullName = trim($approver['full_name'] ?? '');
              if ($approverEmail === 'rajabmwanyika@gmail.com') {
                $gmName = 'RAJABU MWANYIKA';
              } elseif ($approverEmail === 'rajabmsomali@gmail.com') {
                $gmName = $approverFullName !== '' ? $approverFullName : ($approverUsername !== '' ? $approverUsername : null);
              }
            } catch (Exception $eMap) {
              error_log('Approver mapping failed: ' . $eMap->getMessage());
            }

            $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW(), general_manager=? WHERE id=?');
            $up->execute([$action, $_SESSION['user_id'], $gmName, $voucher_id]);
          } else { // rejected
            $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW() WHERE id=?');
            $up->execute([$action, $_SESSION['user_id'], $voucher_id]);
          }

          logVoucherAction($voucher_id, $_SESSION['user_id'], $action, $comments);
          $pdo->commit();
          try {
            notifyUserVoucherStatus($voucher_id, $action);
          } catch (Exception $eN) { /* ignore */
          }
          $success = 'Voucher has been ' . $action . ' successfully.';
        } catch (Exception $ex) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          $error = 'Error processing voucher: ' . $ex->getMessage();
        }
      }
    }
  }
}

// -------------------------------------------------------------------------
// React Admin Dashboard shell (POST handlers above are reused by the UI).
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_id'])) {
    if (!empty($success)) {
        $_SESSION['success_msg'] = $success;
    }
    if (!empty($error)) {
        $_SESSION['error_msg'] = $error;
    }
    $qs = $_GET;
    if (!isset($qs['module']) || (string) $qs['module'] === '') {
        $qs['module'] = 'voucher';
    }
    $redir = 'dashboard.php' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}

require_once __DIR__ . '/dashboard-ui/lib.php';

$assets = adminDashboardUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Admin Dashboard</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Admin Dashboard</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/dashboard-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$adminBase = adminDashboardUiWebBasePath();
$rootBase = adminDashboardUiRootWebPath();
$employeeBase = ($rootBase === '' ? '' : $rootBase) . '/employee';
$qsModule = isset($_GET['module']) && (string) $_GET['module'] !== '' ? (string) $_GET['module'] : 'voucher';

$flashMsg = $_SESSION['success_msg'] ?? ($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
if ($flashMsg === '' && !empty($success)) {
    $flashMsg = $success;
} elseif ($flashMsg === '' && !empty($error)) {
    $flashMsg = $error;
}

$referenceToggleUrl = function_exists('paymentVoucherReferenceToggleUrl')
    ? paymentVoucherReferenceToggleUrl()
    : (function_exists('app_url') ? app_url('/toggle-voucher-reference.php') : '/toggle-voucher-reference.php');

$dashboardConfig = [
    'role' => 'admin',
    'inlineApproveReject' => true,
    'share' => false,
    'module' => $qsModule,
    'myVouchersUrl' => $adminBase . '/all-vouchers.php',
    'createUrl' => $employeeBase . '/create-voucher.php',
    'viewUrl' => $employeeBase . '/view-voucher.php',
    'editUrl' => $employeeBase . '/edit-voucher.php',
    'deleteUrl' => $adminBase . '/dashboard.php',
    'deleteMode' => 'action',
    'approveUrl' => $adminBase . '/dashboard.php',
    'markPaidUrl' => $adminBase . '/dashboard.php',
    'reportsUrl' => $adminBase . '/reports.php',
    'userManualUrl' => $employeeBase . '/user-manual.php',
    'referenceToggleUrl' => $referenceToggleUrl,
    'flash' => $flashMsg,
    'mountSearchInHeader' => true,
];

$GLOBALS['_erp_header_style_linked'] = false;
$employeeHeaderTitle = 'Dashboard';
$reportsHref = htmlspecialchars($adminBase . '/reports.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$userManualHref = htmlspecialchars($employeeBase . '/user-manual.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');
$createHref = htmlspecialchars($employeeBase . '/create-voucher.php?module=' . rawurlencode($qsModule), ENT_QUOTES, 'UTF-8');

$employeeHeaderCenterHtml = '<div class="ad-header-toolbar">'
    . '<a href="' . $reportsHref . '" class="ad-chip-btn ad-chip-btn--ghost">'
    . '<span class="ad-chip-dollar" aria-hidden="true">$</span> Reports</a>'
    . '<div id="ed-dashboard-search-slot" class="ed-dashboard-search-slot"></div>'
    . '<div class="ad-header-actions">'
    . '<a href="' . $userManualHref . '" class="ad-chip-btn ad-chip-btn--ghost ad-chip-btn--icon" title="User Guide" aria-label="User Guide">'
    . '<i class="fas fa-book-open" aria-hidden="true"></i></a>'
    . '<a href="' . $createHref . '" class="ad-chip-btn ad-chip-btn--primary">'
    . '<i class="fas fa-plus" aria-hidden="true"></i> Create Voucher</a>'
    . '</div>'
    . '</div>';

$employeeHeaderRightHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ultimate General Trading</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__DASHBOARD_API_BASE__ = <?= json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) ?>;
        window.__DASHBOARD_CFG__ = <?= json_encode($dashboardConfig, JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php require __DIR__ . '/../includes/nav-back-script.php'; ?>
    <style>
        :root { --bg-body: #f1f5f9; --header-height: 72px; }
        body.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }

        .main-content.dashboard-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.35rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }
        .main-content.dashboard-react-root #root { width: 100%; max-width: none; margin: 0; }

        /* Hide React's duplicate action row — everything lives in the PHP header */
        body.dashboard .ed-header--actions-only,
        body.dashboard .ed-header--no-search { display: none !important; }

        /* Park the KPI band directly beneath the sticky header, never under it */
        body.dashboard .ed-sticky-top {
            top: var(--ad-header-h, 64px) !important;
            padding-top: 6px !important;
            margin-top: 0 !important;
            background: #f1f5f9 !important;
        }
        body.dashboard .ed-sticky-top::before {
            background: #f1f5f9 !important;
            height: 140px !important;
        }
        body.dashboard .ed-kpis {
            margin-bottom: 12px !important;
        }
        body.dashboard .ed-kpis--admin {
            margin-top: 0 !important;
        }

        body.dashboard .header.admin-header {
            background: #f1f5f9 !important;
            border: none !important;
            box-shadow: none !important;
            height: auto !important;
            min-height: 64px;
            overflow: visible !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1020 !important;
        }
        body.dashboard .header.admin-header::after { display: none !important; }

        body.dashboard .header.admin-header .header-content {
            display: grid !important;
            grid-template-columns: auto minmax(280px, 1fr) auto;
            align-items: center !important;
            gap: 14px;
            min-height: 64px;
            padding: 8px 20px !important;
            position: static !important;
        }
        body.dashboard .header.admin-header .header-left {
            display: none !important;
            align-items: center !important;
            justify-content: flex-start !important;
            margin: 0 !important;
            padding: 0 !important;
            grid-column: 1;
            z-index: 6;
        }
        body.dashboard .header.admin-header .employee-header-menu-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 2.5rem;
            min-height: 2.5rem;
            margin: 0 !important;
            padding: 0.2rem !important;
            color: #0f172a !important;
            line-height: 1;
        }
        body.dashboard .header.admin-header .employee-header-menu-btn .erp-hamburger {
            color: #0f172a !important;
        }
        body.dashboard .header.admin-header .employee-header-page-heading {
            grid-column: 1;
            margin: 0 !important;
            padding: 0 !important;
            max-width: none;
        }
        body.dashboard .header.admin-header .employee-header-page-title {
            font-size: 1.35rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            white-space: nowrap;
            line-height: 1.2 !important;
        }
        body.dashboard .admin-header-center-slot {
            grid-column: 2;
            display: flex !important;
            align-items: center !important;
            justify-content: stretch !important;
            min-width: 0 !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
        }
        body.dashboard .ad-header-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-width: 0;
        }
        body.dashboard .ed-dashboard-search-slot {
            flex: 1 1 auto;
            min-width: 180px;
            max-width: 480px;
        }
        body.dashboard .ad-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            margin-left: auto;
        }

        .ad-chip-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none !important;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: background .15s, box-shadow .15s, transform .15s;
        }
        .ad-chip-btn--ghost {
            background: #fff;
            color: #374151 !important;
            border-color: #e5e7eb;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .ad-chip-btn--ghost:hover {
            background: #f8fafc;
            color: #111827 !important;
        }
        .ad-chip-btn--primary {
            background: #6d5df6;
            color: #fff !important;
            box-shadow: 0 6px 16px rgba(109, 93, 246, .25);
        }
        .ad-chip-btn--primary:hover {
            background: #5b4bd6;
            color: #fff !important;
            transform: translateY(-1px);
        }
        .ad-chip-btn--icon {
            width: 40px;
            padding: 0 !important;
            justify-content: center;
        }
        .ad-chip-dollar { font-weight: 700; }

        /* Search must look correct outside .ed-page (portaled into header) */
        body.dashboard .ed-dashboard-search-slot .ed-search,
        body.dashboard .ed-dashboard-search-slot .ed-search--in-header {
            position: static !important;
            left: auto !important;
            top: auto !important;
            transform: none !important;
            width: 100% !important;
            margin: 0 !important;
            text-align: left;
        }
        body.dashboard .ed-dashboard-search-slot .ed-search-wrap {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100%;
        }
        body.dashboard .ed-dashboard-search-slot .ed-search-icon {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            color: #9ca3af !important;
            pointer-events: none !important;
            z-index: 1;
        }
        body.dashboard .ed-dashboard-search-slot input.ed-search-input,
        body.dashboard .ed-dashboard-search-slot input.ed-search-input[type="search"] {
            width: 100% !important;
            height: 40px !important;
            padding: 0 44px 0 38px !important;
            border: 1px solid #d1d5db !important;
            border-radius: 9999px !important;
            font-size: 14px !important;
            background: #fff !important;
            color: #111827 !important;
            box-shadow: none !important;
            outline: none !important;
            -webkit-appearance: none !important;
            appearance: none !important;
        }
        body.dashboard .ed-dashboard-search-slot input.ed-search-input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12) !important;
        }
        body.dashboard .ed-dashboard-search-slot .ed-ai-btn {
            position: absolute !important;
            right: 6px !important;
            top: 50% !important;
            left: auto !important;
            transform: translateY(-50%) !important;
            width: 30px !important;
            height: 30px !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 50% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
            color: #fff !important;
            z-index: 2;
        }
        body.dashboard .ed-dashboard-search-slot .ed-suggestions {
            z-index: 50;
        }

        body.dashboard .header.admin-header .header-right.header-actions-tray {
            grid-column: 3;
            position: static !important;
            top: auto !important;
            right: auto !important;
            margin: 0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
        }
        body.dashboard .header.admin-header .theme-toggle-btn {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
        }

html[data-theme="dark"] body.dashboard .ed-sticky-top,
html[data-theme="dark"] body.dashboard .ed-sticky-top::before {
            background: #0f172a !important;
        }

        html[data-theme="dark"] body.dashboard,
        html[data-theme="dark"] .main-content.dashboard-react-root,
        html[data-theme="dark"] body.dashboard .header.admin-header {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.dashboard .employee-header-page-title { color: #f8fafc !important; }
        html[data-theme="dark"] .ad-chip-btn--ghost {
            background: #1e293b;
            color: #e2e8f0 !important;
            border-color: #334155;
        }
        html[data-theme="dark"] .ad-chip-btn--ghost:hover {
            background: #334155;
            color: #f8fafc !important;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot input.ed-search-input {
            background: #0f172a !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot input.ed-search-input::placeholder {
            color: #64748b !important;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot .ed-search-icon {
            color: #64748b !important;
        }
        html[data-theme="dark"] body.dashboard .ed-dashboard-search-slot .ed-suggestions {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        @media (max-width: 1100px) {
            body.dashboard .header.admin-header .header-content {
                grid-template-columns: auto 1fr;
                grid-template-rows: auto auto;
                gap: 10px 12px;
            }
            body.dashboard .header.admin-header .employee-header-page-heading {
                grid-column: 1;
                grid-row: 1;
            }
            body.dashboard .header.admin-header .header-right.header-actions-tray {
                grid-column: 2;
                grid-row: 1;
                justify-self: end;
            }
            body.dashboard .admin-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
        }
        @media (max-width: 991.98px) {
            body.dashboard .header.admin-header .header-content {
                grid-template-columns: auto auto 1fr !important;
                grid-template-rows: auto auto;
            }
            body.dashboard .header.admin-header .header-left {
                display: flex !important;
                grid-column: 1;
                grid-row: 1;
            }
            body.dashboard .header.admin-header .employee-header-menu-btn {
                display: inline-flex !important;
            }
            body.dashboard .header.admin-header .employee-header-page-heading {
                grid-column: 2;
                grid-row: 1;
            }
            body.dashboard .header.admin-header .header-right.header-actions-tray {
                grid-column: 3;
                grid-row: 1;
                justify-self: end;
            }
            body.dashboard .admin-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
            body.dashboard .main-content.dashboard-react-root {
                padding: 0.5rem 0.85rem 5.5rem !important;
            }
            body.dashboard .ad-header-toolbar {
                flex-wrap: wrap;
                gap: 8px;
            }
            body.dashboard .ed-dashboard-search-slot {
                order: 1;
                flex: 1 1 100%;
                max-width: none;
                min-width: 0;
            }
            body.dashboard .ad-header-actions {
                order: 2;
                margin-left: 0;
                width: 100%;
                justify-content: stretch;
                gap: 8px;
            }
            body.dashboard .ad-header-actions .ad-chip-btn {
                flex: 1 1 auto;
                justify-content: center;
                height: 38px;
                font-size: 13px;
                padding: 0 10px;
            }
            body.dashboard .ad-header-toolbar > .ad-chip-btn--ghost:first-child {
                display: none; /* Reports chip — reduce mobile clutter; still in sidebar */
            }
            body.dashboard .header.admin-header .employee-header-page-title {
                font-size: 1.15rem !important;
            }
        }
        @media (max-width: 768px) {
            body.dashboard .ed-sticky-top {
                position: static !important;
                top: auto !important;
            }
            body.dashboard .ed-sticky-top::before {
                display: none !important;
            }
            body.dashboard .ed-kpis,
            body.dashboard .ed-kpis--admin {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            body.dashboard .header.admin-header .header-content {
                padding: 8px 12px !important;
                gap: 8px 10px;
            }
        }
        @media (max-width: 700px) {
            body.dashboard .ad-header-actions .ad-chip-btn span,
            body.dashboard .ad-header-actions .ad-chip-btn { font-size: 13px; padding: 0 10px; }
            body.dashboard .ad-chip-btn--ghost:not(.ad-header-toolbar *) { /* keep compact */ }
        }

        html[data-theme="dark"] body.dashboard .header.admin-header .employee-header-menu-btn,
        html[data-theme="dark"] body.dashboard .header.admin-header .employee-header-menu-btn .erp-hamburger {
            color: #f8fafc !important;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content dashboard-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to use the Admin Dashboard.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
    <script>
    (function () {
        var header = document.querySelector('.header.admin-header');
        if (!header) return;
        function syncHeaderHeight() {
            var h = Math.round(header.getBoundingClientRect().height);
            if (h > 0) {
                document.documentElement.style.setProperty('--ad-header-h', h + 'px');
            }
        }
        syncHeaderHeight();
        window.addEventListener('resize', syncHeaderHeight);
        if (window.ResizeObserver) {
            new ResizeObserver(syncHeaderHeight).observe(header);
        }
    })();
    </script>
</body>
</html>
