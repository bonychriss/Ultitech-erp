<?php
/**
 * Admin API: search suggestions for voucher search bar (all table columns).
 */
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = min(20, max(5, (int) ($_GET['limit'] ?? 12)));

$suggestions = [];
$seen = [];

$pushSuggestion = static function (string $type, string $value, ?string $label = null) use (&$suggestions, &$seen, $limit): void {
    $value = trim($value);
    if ($value === '' || count($suggestions) >= $limit) {
        return;
    }
    $key = $type . '|' . strtolower($value);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $suggestions[] = [
        'type' => $type,
        'value' => $value,
        'label' => $label ?? $value,
    ];
};

try {
    $where = [];
    $params = [];
    if (function_exists('companyScopeSql')) {
        list($scopeFrag, $scopeParams) = companyScopeSql('payment_vouchers', 'pv');
        if ($scopeFrag !== '') {
            $where[] = ltrim($scopeFrag, " \t\n\r\0\x0BAND");
            $params = array_merge($params, $scopeParams);
        }
    } else {
        $companySql = getCompanySql('pv');
        if ($companySql !== '') {
            $where[] = ltrim($companySql, ' AND ');
            $params = array_merge($params, getCompanyParam());
        }
    }

    if ($q !== '') {
        $searchSql = buildPaymentVoucherSearchSql($q, $params, 'pv', 'u');
        if ($searchSql !== '') {
            $where[] = $searchSql;
        }
    }

    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT
            pv.id,
            pv.voucher_no,
            pv.payee_name,
            pv.description,
            pv.total_amount,
            pv.currency,
            pv.status,
            pv.date_created,
            pv.created_at,
            IFNULL(pv.is_paid, 0) AS is_paid,
            IFNULL(pv.is_posted, 0) AS is_posted,
            COALESCE(NULLIF(TRIM(pv.prepared_by), ''), u.full_name, '') AS prepared_by,
            (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count
        FROM payment_vouchers pv
        LEFT JOIN users u ON pv.created_by = u.id
        $whereClause
        ORDER BY pv.date_created DESC
        LIMIT " . (int) max($limit * 2, 20);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $pushSuggestion('sn', (string) ($row['id'] ?? ''), 'S/N ' . (string) ($row['id'] ?? ''));
        $pushSuggestion('voucher_no', (string) ($row['voucher_no'] ?? ''));
        $pushSuggestion('payee', (string) ($row['payee_name'] ?? ''));
        $pushSuggestion('prepared_by', (string) ($row['prepared_by'] ?? ''));
        $desc = trim((string) ($row['description'] ?? ''));
        if ($desc !== '') {
            $pushSuggestion('description', mb_strlen($desc) > 80 ? mb_substr($desc, 0, 80) . '…' : $desc);
        }
        $amount = (float) ($row['total_amount'] ?? 0);
        $currency = trim((string) ($row['currency'] ?? 'TZS'));
        $pushSuggestion('amount', (string) $amount, $currency . ' ' . number_format($amount, 2));
        if (!empty($row['date_created'])) {
            $pushSuggestion('date', date('d/m/Y', strtotime((string) $row['date_created'])));
        }
        $statusLabel = (string) ($row['status'] ?? '');
        if ((int) ($row['is_posted'] ?? 0) === 1) {
            $statusLabel = 'Posted';
        } elseif ((int) ($row['is_paid'] ?? 0) === 1) {
            $statusLabel = 'Paid';
        } else {
            $statusLabel = ucfirst($statusLabel);
        }
        $pushSuggestion('status', $statusLabel);
        $att = (int) ($row['attachment_count'] ?? 0);
        if ($att > 0) {
            $pushSuggestion('docs', (string) $att, $att . ' attachment' . ($att === 1 ? '' : 's'));
        }
        if (count($suggestions) >= $limit) {
            break;
        }
    }
} catch (Throwable $e) {
    $suggestions = [];
}

echo json_encode(['suggestions' => $suggestions]);
