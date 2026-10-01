<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/invoices-lib.php';
require_once __DIR__ . '/../../includes/invoice-corrections-lib.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

global $pdo;
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Sales database is not available.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$admin = function_exists('isAdmin') && isAdmin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'ok' => true,
            'is_admin' => $admin,
            'pending' => salesInvoiceCorrectionsPendingCount($salesDb),
            'reports' => salesInvoiceCorrectionsList($salesDb, $admin, $userId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    if ($action === 'report') {
        $attachment = salesInvoiceCorrectionsStoreAttachment(
            isset($_FILES['attachment']) && is_array($_FILES['attachment']) ? $_FILES['attachment'] : null
        );
        $result = salesInvoiceCorrectionsReport(
            $salesDb,
            (int) ($_POST['invoice_id'] ?? 0),
            $userId,
            (string) ($_POST['reason'] ?? ''),
            (string) ($_POST['reported_on'] ?? ''),
            $attachment
        );
    } elseif ($action === 'approve') {
        if (!$admin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Only an administrator can reverse an invoice.']);
            exit;
        }
        $result = salesInvoiceCorrectionsApprove($salesDb, (int) ($_POST['id'] ?? 0), $userId);
    } elseif ($action === 'reject') {
        if (!$admin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Only an administrator can reject a report.']);
            exit;
        }
        $result = salesInvoiceCorrectionsReject(
            $salesDb,
            (int) ($_POST['id'] ?? 0),
            $userId,
            (string) ($_POST['note'] ?? '')
        );
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
        exit;
    }

    if (empty($result['ok'])) {
        http_response_code(400);
    }
    $result['pending'] = salesInvoiceCorrectionsPendingCount($salesDb);
    $result['reports'] = salesInvoiceCorrectionsList($salesDb, $admin, $userId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
