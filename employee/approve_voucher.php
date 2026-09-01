<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

// Simple endpoint to record an approver's approval and signature for a voucher
// POST params:
// - voucher_id (int)
// - use_profile_signature (optional, '1')
// - signature (optional, base64 PNG data URI or raw base64)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$voucher_id = isset($_POST['voucher_id']) ? (int) $_POST['voucher_id'] : 0;

// DEBUG LOGGING
$logFile = __DIR__ . '/debug_approve.log';
$logData = date('Y-m-d H:i:s') . " Request: " . print_r($_POST, true) . "\n"
    . 'Session User: ' . ($_SESSION['user_id'] ?? 'None') . "\n";
file_put_contents($logFile, $logData, FILE_APPEND);

if ($voucher_id <= 0) {
    file_put_contents($logFile, "Invalid Voucher ID\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Invalid voucher id']);
    exit;
}

$userId = function_exists('resolveVoucherSessionUserId')
    ? (int) resolveVoucherSessionUserId($pdo)
    : (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $pdo->beginTransaction();
    $approval = false;
    if (!empty($_POST['approval_id']) && ctype_digit((string) $_POST['approval_id']) && (int) $_POST['approval_id'] > 0) {
        $aid = (int) $_POST['approval_id'];
        $stmt = $pdo->prepare('SELECT * FROM approvals WHERE id = ? FOR UPDATE');
        $stmt->execute([$aid]);
        $approval = $stmt->fetch();
        if ($approval && (int) $approval['voucher_id'] !== $voucher_id) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Approval does not belong to voucher']);
            exit;
        }
    }
    if (!$approval) {
        $voucherRow = null;
        try {
            $vst = $pdo->prepare('SELECT id, applicant, department_manager, checked_by, status, general_manager FROM payment_vouchers WHERE id = ? LIMIT 1');
            $vst->execute([$voucher_id]);
            $voucherRow = $vst->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $voucherRow = null;
        }
        $pendingForUser = array();
        if ($voucherRow && function_exists('getUserPendingVoucherApprovals')) {
            $uname = function_exists('resolveVoucherSessionDisplayName')
                ? resolveVoucherSessionDisplayName($pdo)
                : trim((string) ($_SESSION['full_name'] ?? ''));
            $pendingForUser = getUserPendingVoucherApprovals($pdo, $voucher_id, $userId, $uname, $voucherRow);
            if (!empty($pendingForUser)) {
                $aid = (int) ($pendingForUser[0]['id'] ?? 0);
                if ($aid > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM approvals WHERE id = ? FOR UPDATE');
                    $stmt->execute([$aid]);
                    $approval = $stmt->fetch();
                }
            }
        }
        if (!$approval && empty($pendingForUser)) {
            $stmt = $pdo->prepare(
                "SELECT * FROM approvals WHERE voucher_id = ? AND status = 'pending' AND (approver_id = ? OR LOWER(TRIM(approver_name)) = LOWER(TRIM((SELECT full_name FROM users WHERE id = ? LIMIT 1)))) FOR UPDATE"
            );
            $stmt->execute([$voucher_id, $userId, $userId]);
            $approval = $stmt->fetch();
        }
    }

    $voucherAssignees = null;
    try {
        $vst = $pdo->prepare('SELECT id, applicant, department_manager, checked_by, status, general_manager FROM payment_vouchers WHERE id = ? LIMIT 1');
        $vst->execute([$voucher_id]);
        $voucherAssignees = $vst->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $voucherAssignees = null;
    }
    if (!$voucherAssignees) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Voucher not found']);
        exit;
    }

    $uname = function_exists('resolveVoucherSessionDisplayName')
        ? resolveVoucherSessionDisplayName($pdo)
        : trim((string) ($_SESSION['full_name'] ?? ''));
    $rolesToApprove = function_exists('getUserPendingVoucherApprovals')
        ? getUserPendingVoucherApprovals($pdo, $voucher_id, $userId, $uname, $voucherAssignees)
        : array();

    if (!$approval && empty($rolesToApprove)) {
        file_put_contents($logFile, "Approval row NOT found for Voucher: $voucher_id, User: $userId\n", FILE_APPEND);
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'You are not registered as an approver for this voucher']);
        exit;
    }
    if ($approval) {
        file_put_contents($logFile, 'Approval Found: ' . print_r($approval, true) . "\n", FILE_APPEND);

        if ($approval['status'] === 'approved') {
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => 'Already approved']);
            exit;
        }
    }

    if (empty($rolesToApprove)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'You are not registered as an approver for this voucher']);
        exit;
    }

    $approvalIds = array_values(array_filter(array_map(static function ($row) {
        return (int) ($row['id'] ?? 0);
    }, $rolesToApprove), static function ($id) {
        return $id > 0;
    }));

    if (!empty($_POST['approval_id']) && ctype_digit((string) $_POST['approval_id']) && (int) $_POST['approval_id'] > 0) {
        $requestedId = (int) $_POST['approval_id'];
        if (!empty($approvalIds) && !in_array($requestedId, $approvalIds, true)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'You cannot approve this step']);
            exit;
        }
        if (!empty($approvalIds)) {
            $approvalIds = array($requestedId);
        }
    }

    if (function_exists('expandSamePersonApplicantDeptManagerApprovalIds')) {
        $approvalIds = expandSamePersonApplicantDeptManagerApprovalIds(
            $voucherAssignees,
            $rolesToApprove,
            $approvalIds
        );
    }

    $finalizeAsGm = false;
    $approvalIdSet = array_fill_keys($approvalIds, true);
    foreach ($rolesToApprove as $roleRow) {
        $roleId = (int) ($roleRow['id'] ?? 0);
        if ($roleId > 0 && empty($approvalIdSet[$roleId])) {
            continue;
        }
        $roleKey = function_exists('normalizeVoucherApprovalRoleKey')
            ? normalizeVoucherApprovalRoleKey($roleRow['role_key'] ?? $roleRow['role'] ?? '')
            : strtolower(trim((string) ($roleRow['role'] ?? '')));
        if ($roleKey === 'general manager' || !empty($roleRow['is_final_approval'])) {
            $finalizeAsGm = true;
            break;
        }
    }

    $signaturePath = null;
    $useProfile = isset($_POST['use_profile_signature']) && (string) $_POST['use_profile_signature'] === '1';
    if ($useProfile) {
        $s = getUserSignaturePathById($userId);
        if ($s) {
            $signaturePath = $s;
        }
    }

    if (!$signaturePath && !empty($_POST['signature'])) {
        $sigRaw = $_POST['signature'];
        if (strpos($sigRaw, 'data:image') === 0) {
            $parts = explode(',', $sigRaw, 2);
            $sigRaw = $parts[1] ?? '';
        }
        $sigRaw = str_replace(' ', '+', $sigRaw);
        $data = base64_decode($sigRaw);
        if ($data !== false) {
            $dir = __DIR__ . '/../assets/signatures/approvals';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $filename = 'voucher_' . $voucher_id . '_user_' . $userId . '_' . time() . '.png';
            $abs = $dir . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($abs, $data) !== false) {
                $signaturePath = 'assets/signatures/approvals/' . $filename;
            }
        }
    }

    if (!empty($approvalIds)) {
        $placeholders = implode(',', array_fill(0, count($approvalIds), '?'));
        $upd = $pdo->prepare(
            "UPDATE approvals SET status = 'approved', signature_path = ?, approved_at = NOW() WHERE voucher_id = ? AND status = 'pending' AND id IN ($placeholders)"
        );
        $upd->execute(array_merge([$signaturePath, $voucher_id], $approvalIds));
    } else {
        foreach ($rolesToApprove as $roleRow) {
            $roleKey = function_exists('normalizeVoucherApprovalRoleKey')
                ? normalizeVoucherApprovalRoleKey($roleRow['role_key'] ?? $roleRow['role'] ?? '')
                : strtolower(trim((string) ($roleRow['role'] ?? '')));
            if ($roleKey === '') {
                continue;
            }
            $upd = $pdo->prepare(
                "UPDATE approvals SET status = 'approved', signature_path = ?, approved_at = NOW() WHERE voucher_id = ? AND status = 'pending' AND LOWER(TRIM(role)) = ?"
            );
            $upd->execute(array($signaturePath, $voucher_id, $roleKey));
        }
    }

    if ($finalizeAsGm) {
        if (!function_exists('userCanVoucherGeneralManagerApprove')
            || !userCanVoucherGeneralManagerApprove($pdo, $voucherAssignees, $userId)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'General Manager approval is not available yet. Complete Applicant, Department Manager, and Checked By first.']);
            exit;
        }
        $gmName = function_exists('resolveVoucherApproverGeneralManagerName')
            ? resolveVoucherApproverGeneralManagerName($pdo, $userId)
            : $uname;
        if ($gmName === '') {
            $gmName = $uname;
        }
        $up = $pdo->prepare('UPDATE payment_vouchers SET status = ?, approved_by = ?, approved_at = NOW(), general_manager = ? WHERE id = ?');
        $up->execute(['approved', $userId, $gmName, $voucher_id]);
        if ($gmName !== '' && function_exists('erp_upsert_general_manager_approval')) {
            erp_upsert_general_manager_approval($pdo, $voucher_id, $gmName, $userId);
        }
    } else {
        $pendingEmployee = function_exists('countPendingEmployeeApprovalRoles')
            ? countPendingEmployeeApprovalRoles($pdo, $voucher_id)
            : 0;
        if ($pendingEmployee === 0) {
            $pstmt = $pdo->prepare('UPDATE payment_vouchers SET status = ? WHERE id = ?');
            $pstmt->execute(['pending', $voucher_id]);
        }
    }

    $pdo->commit();

    if ($finalizeAsGm) {
        try {
            notifyUserVoucherStatus($voucher_id, 'approved');
        } catch (Throwable $eNotify) {
        }
    }

    // Log after commit so approval_logs FK issues cannot roll back the approval.
    try {
        logVoucherAction($voucher_id, $userId, 'approved');
    } catch (Throwable $logEx) {
        error_log('approve_voucher log failed voucher ' . $voucher_id . ': ' . $logEx->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Approved successfully', 'signature_path' => $signaturePath]);
    file_put_contents($logFile, "Approval SUCCESS. Signature: $signaturePath\n", FILE_APPEND);
    exit;
} catch (Throwable $e) {
    file_put_contents($logFile, 'EXCEPTION: ' . $e->getMessage() . "\n", FILE_APPEND);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('approve_voucher error: ' . $e->getMessage());
    $friendly = function_exists('voucherWorkflowFriendlyError')
        ? voucherWorkflowFriendlyError($e->getMessage())
        : 'An error occurred while approving the voucher. Please try again.';
    echo json_encode(['success' => false, 'message' => $friendly]);
    exit;
}
