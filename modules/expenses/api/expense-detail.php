<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A valid expense ID is required.']);
    exit;
}

function expenses_detail_user_names(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function ($id) {
        return $id > 0;
    })));
    if ($userIds === [] || !erp_connection_has_table($pdo, 'users')) {
        return [];
    }

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $nameColumns = array_values(array_filter(['full_name', 'name', 'username'], static function ($column) use ($columns) {
        return in_array($column, $columns, true);
    }));
    if ($nameColumns === []) {
        return [];
    }

    $hasCompanyId = in_array('company_id', $columns, true);
    $hasSignaturePath = in_array('signature_path', $columns, true);
    $hasIsActive = in_array('is_active', $columns, true);
    $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
    if (($hasCompanyId && $companyId <= 0)
        || (!$hasCompanyId && defined('IS_SHARED_TRIAL_DB') && IS_SHARED_TRIAL_DB)) {
        return [];
    }

    $nameParts = array_map(static function ($column) {
        return "NULLIF(TRIM(`{$column}`), '')";
    }, $nameColumns);
    $signatureExpr = $hasSignaturePath
        ? ($hasIsActive ? 'CASE WHEN is_active = 1 THEN signature_path ELSE NULL END' : 'signature_path')
        : 'NULL';
    $sql = 'SELECT id, COALESCE(' . implode(', ', $nameParts) . ", '') AS display_name, "
        . $signatureExpr . ' AS signature_path'
        . ' FROM users WHERE id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
    $params = $userIds;
    if ($hasCompanyId) {
        $sql .= ' AND company_id = ?';
        $params[] = $companyId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $user) {
        $signaturePath = trim((string) ($user['signature_path'] ?? ''));
        $names[(int) $user['id']] = [
            'display_name' => trim((string) ($user['display_name'] ?? '')),
            'signature_url' => $signaturePath !== '' && function_exists('mediaUrlFromPath')
                ? mediaUrlFromPath($signaturePath)
                : '',
        ];
    }

    return $names;
}

try {
    expenses_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT e.* FROM erp_expenses e WHERE e.id = ? AND ' . expenses_scope_sql('e') . ' LIMIT 1'
    );
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
        exit;
    }

    $expense = expenses_enrich_list_row($pdo, $expense);
    $userNames = expenses_detail_user_names($pdo, [
        $expense['created_by'] ?? 0,
        $expense['approved_by'] ?? 0,
    ]);
    $creatorDetails = $userNames[(int) ($expense['created_by'] ?? 0)] ?? [];
    $approverDetails = $userNames[(int) ($expense['approved_by'] ?? 0)] ?? [];
    $expense['created_by_name'] = $creatorDetails['display_name'] ?? '';
    $expense['created_by_signature_url'] = $creatorDetails['signature_url'] ?? '';
    $expense['approved_by_name'] = $approverDetails['display_name'] ?? '';
    $attachment = trim((string) ($expense['attachment'] ?? ''));
    $expense['attachment_name'] = $attachment !== ''
        ? basename(str_replace('\\', '/', $attachment))
        : '';
    $proxyUrl = function_exists('app_url') ? app_url('/proxy_pdf.php') : '/proxy_pdf.php';
    $expense['attachment_url'] = $attachment !== ''
        ? $proxyUrl . '?file=' . rawurlencode(ltrim(str_replace('\\', '/', $attachment), '/'))
        : '';
    unset($expense['attachment']);

    echo json_encode([
        'ok' => true,
        'data' => $expense,
        'csrf_token' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('expense-detail: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load expense details.']);
}
