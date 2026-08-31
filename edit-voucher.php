<?php
// Force no-cache to avoid stale HTML/JS on hosts with aggressive caching (InfinityFree)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/functions.php';
requireLogin();

$dashboardUrl = ($_SESSION['role'] === 'admin') ? 'admin/dashboard.php' : 'employee/dashboard.php';

if (!isset($_GET['id'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$voucher_id = intval($_GET['id']);

if (!canEditVoucher($voucher_id, $_SESSION['user_id']) && !canLimitedEditApprovedVoucher($voucher_id, $_SESSION['user_id'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ?');
$stmt->execute([$voucher_id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$vfLimitedEditMode = voucherUsesLimitedClassificationEditMode($voucher, $voucher_id, (int) $_SESSION['user_id']);

$stmt = $pdo->prepare('SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id');
$stmt->execute([$voucher_id]);
$existing_items = $stmt->fetchAll();
$attachments = getVoucherAttachments($voucher_id);

$error = '';
$success = '';

$isPaidFlag = isset($voucher['is_paid']) && (int) $voucher['is_paid'] === 1;
$isDraftDerived = !$isPaidFlag
    && isset($voucher['status']) && strtolower($voucher['status']) === STATUS_PENDING
    && (
        empty(trim((string) $voucher['payee_name']))
        || (float) $voucher['total_amount'] <= 0
        || count($existing_items) === 0
    );
$isDraftView = $isDraftDerived || (isset($_GET['draft']) && $_GET['draft'] == '1');

try {
    $usersStmt = $pdo->query('SELECT full_name, department, role FROM users WHERE is_active = 1 ORDER BY full_name');
    $allUsersRaw = $usersStmt->fetchAll();
    $allUsers = $allUsersRaw;
    $financeUsers = array_values(array_filter($allUsers, static function ($u) {
        $isFinance = isset($u['department']) && strcasecmp(trim($u['department']), 'Finance') === 0;
        $isAdmin = isset($u['role']) && $u['role'] === ROLE_ADMIN;
        return $isFinance || $isAdmin;
    }));
} catch (Exception $e) {
    $allUsers = [];
    $financeUsers = [];
}

$voucherModuleQs = '';
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $voucherModuleQs = '?module=' . rawurlencode((string) $_GET['module']);
}

$payees = [];
try {
    $stmt = $pdo->query('SELECT id, name, type FROM payees WHERE is_active = 1 ORDER BY name ASC');
    $payees = $stmt->fetchAll();
} catch (Exception $e) { /* silent */
}

$salesOrders = [];
try {
    $salesOrders = $pdo->query("
        SELECT
            so.id,
            so.order_number,
            so.status,
            so.created_at,
            COALESCE(c.company_name, c.contact_person, 'Unknown Customer') AS customer_name,
            COALESCE(u.full_name, 'Unassigned') AS salesperson_name
        FROM sales_orders so
        LEFT JOIN customers c ON c.id = so.customer_id
        LEFT JOIN users u ON u.id = so.created_by
        ORDER BY so.created_at DESC, so.id DESC
        LIMIT 500
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $salesOrders = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_create_payee') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? 'Other');
    $tin = trim($_POST['tin'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Payee Name is required']);
        exit;
    }

    try {
        $chk = $pdo->prepare('SELECT id FROM payees WHERE name = ?');
        $chk->execute([$name]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Payee already exists']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO payees (name, type, tin, contact_details, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$name, $type, $tin, $contact]);
        $newId = $pdo->lastInsertId();

        if ($type === 'Supplier') {
            $chk = $pdo->prepare('SELECT id FROM stocks_suppliers WHERE name = ?');
            $chk->execute([$name]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare('INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)')
                    ->execute([$name, $contact]);
            }
        }

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'type' => $type]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limited_classification_update']) && (int) $_POST['limited_classification_update'] === 1) {
    if (!$vfLimitedEditMode) {
        $error = 'Limited classification edit is not available for this voucher.';
    } else {
        $linked_sales_order_ids_raw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
        $linked_sales_order_ids = [];
        if ($linked_sales_order_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $linked_sales_order_ids_raw) as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $linked_sales_order_ids[$id] = $id;
                }
            }
        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
            $linked_sales_order_ids[(int) $_POST['linked_sales_order_id']] = (int) $_POST['linked_sales_order_id'];
        }
        $linked_sales_order_ids = array_values($linked_sales_order_ids);
        $result = saveApprovedVoucherLimitedClassification(
            $pdo,
            $voucher_id,
            (int) $_SESSION['user_id'],
            (string) ($_POST['voucher_purpose'] ?? 'general'),
            $linked_sales_order_ids
        );
        if ($result['ok']) {
            $redirectUrl = 'view-voucher.php?id=' . $voucher_id;
            if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
                $redirectUrl .= '&module=' . rawurlencode((string) $_GET['module']);
            }
            header('Location: ' . $redirectUrl);
            exit();
        }
        $error = $result['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['limited_classification_update'])) {
    $txStarted = false;
    try {
        $payee_name = trim($_POST['payee_name'] ?? '');
        $payee_id = isset($_POST['payee_id']) && is_numeric($_POST['payee_id']) ? (int) $_POST['payee_id'] : null;
        if ($payee_name === '' && $payee_id > 0) {
            $payeeLookup = $pdo->prepare('SELECT name FROM payees WHERE id = ?');
            $payeeLookup->execute([$payee_id]);
            $payee_name = trim((string) $payeeLookup->fetchColumn());
        }
        $voucher_purpose = normalizePaymentVoucherPurpose($_POST['voucher_purpose'] ?? 'general');
        $linked_sales_order_ids_raw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
        $linked_sales_order_ids = [];
        if ($linked_sales_order_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $linked_sales_order_ids_raw) as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $linked_sales_order_ids[$id] = $id;
                }
            }
        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
            $linked_sales_order_ids[(int) $_POST['linked_sales_order_id']] = (int) $_POST['linked_sales_order_id'];
        }
        $linked_sales_order_ids = array_values($linked_sales_order_ids);
        $linked_sales_order_id = !empty($linked_sales_order_ids) ? (int) $linked_sales_order_ids[0] : 0;
        $description = trim($_POST['description']);
        $currency = $_POST['currency'];
        $supporting_documents = intval($_POST['supporting_documents']);
        $applicant = trim($_POST['applicant']);
        $department_manager = trim($_POST['department_manager']);
        $prepared_by = trim($_POST['prepared_by']);
        $checked_by = isset($_POST['checked_by']) ? trim($_POST['checked_by']) : '';
        $general_manager = isset($voucher['general_manager']) ? $voucher['general_manager'] : null;
        $date_created = $_POST['date_created'];

        $is_restricted = isset($voucher['is_restricted']) ? (int) $voucher['is_restricted'] : 0;
        if (isAdmin() || (function_exists('isFinance') && isFinance())) {
            $is_restricted = isset($_POST['is_restricted']) ? 1 : 0;
        }

        if (empty($payee_name) || empty($description) || empty($date_created)) {
            throw new Exception('Please fill in all required fields');
        }

        $items = [];
        $total_amount = 0;

        if (!isset($_POST['payment_type']) || !is_array($_POST['payment_type'])) {
            throw new Exception('Please add at least one payment item');
        }

        for ($i = 0; $i < count($_POST['payment_type']); $i++) {
            $payment_type = trim($_POST['payment_type'][$i]);
            $budget_type = trim($_POST['budget_type'][$i]);
            $name = trim($_POST['name'][$i]);
            $amount = floatval($_POST['amount'][$i]);
            $item_description = trim($_POST['item_description'][$i]);
            if ($payment_type && $budget_type && $name && $amount > 0) {
                $items[] = [
                    'payment_type' => $payment_type,
                    'budget_type' => $budget_type,
                    'name' => $name,
                    'amount' => $amount,
                    'description' => $item_description,
                ];
                $total_amount += $amount;
            }
        }

        if (empty($items)) {
            throw new Exception('Please add at least one valid payment item');
        }

        $pdo->beginTransaction();
        $txStarted = true;

        try {
            $stmt = $pdo->prepare('
                UPDATE payment_vouchers
                SET payee_name = ?, description = ?, currency = ?, total_amount = ?,
                    supporting_documents = ?, applicant = ?, department_manager = ?,
                    general_manager = ?, date_created = ?, prepared_by = ?, checked_by = ?, is_restricted = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $payee_name, $description, $currency, $total_amount,
                $supporting_documents, $applicant, $department_manager, $general_manager,
                $date_created, $prepared_by, $checked_by, $is_restricted, $voucher_id,
            ]);
        } catch (Exception $e) {
            $stmt = $pdo->prepare('
                UPDATE payment_vouchers
                SET payee_name = ?, description = ?, currency = ?, total_amount = ?,
                    supporting_documents = ?, applicant = ?, department_manager = ?,
                    general_manager = ?, date_created = ?, prepared_by = ?, is_restricted = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $payee_name, $description, $currency, $total_amount,
                $supporting_documents, $applicant, $department_manager, $general_manager,
                $date_created, $prepared_by, $is_restricted, $voucher_id,
            ]);
        }

        try {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $extraSets = [];
            $extraVals = [];
            $purposeFragments = buildPaymentVoucherPurposeUpdateFragments($voucher_purpose, $pvCols);
            foreach ($purposeFragments['sets'] as $setFragment) {
                $extraSets[] = $setFragment;
            }
            foreach ($purposeFragments['vals'] as $valFragment) {
                $extraVals[] = $valFragment;
            }
            if (in_array('linked_sales_order_id', $pvCols, true)) {
                $extraSets[] = 'linked_sales_order_id = ?';
                $extraVals[] = ($linked_sales_order_id > 0 ? $linked_sales_order_id : null);
            }
            if (in_array('linked_sales_order_ids', $pvCols, true)) {
                $extraSets[] = 'linked_sales_order_ids = ?';
                $extraVals[] = !empty($linked_sales_order_ids) ? json_encode($linked_sales_order_ids) : null;
            }
            if (in_array('payee_id', $pvCols, true)) {
                $extraSets[] = 'payee_id = ?';
                $extraVals[] = ($payee_id > 0 ? $payee_id : null);
            }
            if (!empty($extraSets)) {
                $extraVals[] = $voucher_id;
                $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $extraSets) . ' WHERE id = ?')->execute($extraVals);
            }
        } catch (Throwable $eExtra) { /* ignore optional columns */
        }

        $stmt = $pdo->prepare('DELETE FROM voucher_items WHERE voucher_id = ?');
        $stmt->execute([$voucher_id]);

        $stmt = $pdo->prepare('
            INSERT INTO voucher_items (voucher_id, payment_type, budget_type, name, amount, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        foreach ($items as $item) {
            $stmt->execute([
                $voucher_id,
                $item['payment_type'],
                $item['budget_type'],
                $item['name'],
                $item['amount'],
                $item['description'],
            ]);
        }

        $newUploads = 0;
        if (!empty($_FILES['supporting_files']) && isset($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name'])) {
            ensureVoucherAttachmentsSchema();
            $baseDir = ensureVoucherUploadsDir();
            $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucher_id;
            if (!is_dir($voucherDir)) {
                @mkdir($voucherDir, 0775, true);
            }
            if (is_dir($voucherDir) && !is_writable($voucherDir)) {
                @chmod($voucherDir, 0775);
            }
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
            $toBytes = static function ($val) {
                $val = trim((string) $val);
                if ($val === '') {
                    return 0;
                }
                $u = strtolower(substr($val, -1));
                $n = (float) $val;
                switch ($u) {
                    case 'g':
                        $n *= 1024;
                    case 'm':
                        $n *= 1024;
                    case 'k':
                        $n *= 1024;
                }
                return (int) round($n);
            };
            $maxServer = min(max(1, $toBytes(ini_get('upload_max_filesize') ?: '10M')), max(1, $toBytes(ini_get('post_max_size') ?: '10M')));
            $names = $_FILES['supporting_files']['name'];
            $tmps = $_FILES['supporting_files']['tmp_name'];
            $types = $_FILES['supporting_files']['type'];
            $sizes = $_FILES['supporting_files']['size'];
            $errs = $_FILES['supporting_files']['error'];
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                if (!isset($names[$i]) || $errs[$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $orig = $names[$i];
                $size = (int) ($sizes[$i] ?? 0);
                $mime = (string) ($types[$i] ?? 'application/octet-stream');
                $tmp = $tmps[$i];
                if ($size <= 0 || $size > $maxServer) {
                    continue;
                }
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    continue;
                }
                $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                if ($safeBase === '') {
                    $safeBase = 'file';
                }
                $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
                $destRel = 'assets/uploads/vouchers/' . $voucher_id . '/' . $unique;
                if (@move_uploaded_file($tmp, $destAbs)) {
                    addVoucherAttachment($voucher_id, $destRel, $orig, $mime, $size, (int) $_SESSION['user_id']);
                    $newUploads++;
                }
            }
            if ($newUploads > 0) {
                try {
                    $attCountStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_attachments WHERE voucher_id = ?');
                    $attCountStmt->execute([$voucher_id]);
                    $realCount = (int) ($attCountStmt->fetch()['c'] ?? $newUploads);
                    $up = $pdo->prepare('UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?');
                    $up->execute([$realCount, $voucher_id]);
                } catch (Throwable $e) { /* ignore */
                }
            }
        }

        if (function_exists('syncVoucherApprovalAssignees')) {
            syncVoucherApprovalAssignees($pdo, $voucher_id, array(
                'Applicant' => $applicant,
                'Department Manager' => $department_manager,
                'Checked By' => $checked_by,
            ));
        }

        logVoucherAction($voucher_id, $_SESSION['user_id'], 'modified' . ($newUploads > 0 ? ' +attachments(' . $newUploads . ')' : ''));

        if ($txStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }

        try {
            $prevChecked = trim((string) ($voucher['checked_by'] ?? ''));
            $newChecked = trim((string) $checked_by);
            if ($newChecked !== '' && strcasecmp($prevChecked, $newChecked) !== 0) {
                notifyCheckedByAssignee($voucher_id);
            }
        } catch (Throwable $eN) {
            error_log('notifyCheckedByAssignee (edit) failed: ' . $eN->getMessage());
        }

        $redirectUrl = 'view-voucher.php?id=' . (int) $voucher_id . '&updated=1';
        if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
            $redirectUrl .= '&module=' . rawurlencode((string) $_GET['module']);
        }
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit();
        }
        if (function_exists('app_log')) {
            app_log('edit-voucher: headers already sent, using meta refresh to ' . $redirectUrl);
        }
        $safe = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safe . '"><title>Redirecting...</title></head><body>Redirecting... <a href="' . $safe . '">Continue</a></body></html>';
        exit();
    } catch (Exception $e) {
        if ($txStarted && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbEx) {
                error_log('edit-voucher rollback failed: ' . $rbEx->getMessage());
            }
        }
        $error = 'Failed to update voucher: ' . $e->getMessage();
        error_log('Voucher update failed: ' . $e->getMessage());
        error_log($e->getTraceAsString());
        if (function_exists('app_log')) {
            app_log('Voucher update failed for ID ' . $voucher_id . ': ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
        }
    }
}

$vfMode = 'edit';
$vfVoucher = $voucher;
$vfVoucherId = $voucher_id;
$vfExistingItems = $existing_items;
$vfAttachments = $attachments;
$vfIsDraftView = $isDraftView;
$vfCancelUrl = $dashboardUrl . $voucherModuleQs;
require __DIR__ . '/employee/includes/voucher-form-page.php';
