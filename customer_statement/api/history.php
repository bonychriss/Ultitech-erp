<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/statement-lib.php';
customerStatementDeskRequireAccess();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = customer_statement_pdo();
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Cannot determine the current company.');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS customer_statement_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        customer_name VARCHAR(255) NOT NULL,
        date_from DATE NULL,
        date_to DATE NULL,
        total_sales DECIMAL(20,2) NOT NULL,
        total_payments DECIMAL(20,2) NOT NULL,
        balance DECIMAL(20,2) NOT NULL,
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        snapshot LONGTEXT NOT NULL,
        INDEX (company_id, id),
        INDEX (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid statement ID.']);
                exit;
            }
            $query = $db->prepare('SELECT snapshot FROM customer_statement_history WHERE id = ? AND company_id = ?');
            $query->execute([$id, $companyId]);
            $snapshot = $query->fetchColumn();
            if ($snapshot === false) {
                http_response_code(404);
                echo json_encode(['error' => 'Saved statement not found.']);
                exit;
            }
            echo json_encode(['statement' => json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
            exit;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 10;
        $countQuery = $db->prepare('SELECT COUNT(*) FROM customer_statement_history WHERE company_id = ?');
        $countQuery->execute([$companyId]);
        $total = (int) $countQuery->fetchColumn();
        $page = min($page, max(1, (int) ceil($total / $pageSize)));
        $query = $db->prepare('SELECT id, customer_name, date_from, date_to, total_sales, total_payments, balance, generated_at
            FROM customer_statement_history WHERE company_id = :company_id ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $query->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();
        echo json_encode(['results' => $query->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'page_size' => $pageSize], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !verify_csrf($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Session expired. Reload the page and try again.']);
        exit;
    }
    $ids = $input['customer_ids'] ?? null;
    $from = $input['date_from'] ?? '';
    $to = $input['date_to'] ?? '';
    $validDate = static function ($value): bool {
        if (!is_string($value)) return false;
        if ($value === '') return true;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };
    if (!is_array($ids) || count($ids) === 0 || count($ids) > 1000 || !$validDate($from) || !$validDate($to) || ($from !== '' && $to !== '' && $from > $to)) {
        http_response_code(400);
        echo json_encode(['error' => 'Select customers and enter a valid date range.']);
        exit;
    }
    foreach ($ids as $id) {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid customer selection.']);
            exit;
        }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $normalized = customer_statement_parse_filters(['customer_ids' => $ids, 'date_from' => $from, 'date_to' => $to]);
    $from = $normalized['date_from'];
    $to = $normalized['date_to'];
    $insert = $db->prepare('INSERT INTO customer_statement_history
        (company_id, customer_id, customer_name, date_from, date_to, total_sales, total_payments, balance, snapshot)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $db->beginTransaction();
    foreach ($ids as $id) {
        $statement = customer_statement_build($db, [
            'customer_ids' => [$id], 'date_from' => $from, 'date_to' => $to, 'period' => '',
        ], true);
        if (count($statement['selected_customers']) !== 1) {
            throw new RuntimeException('A selected customer could not be found.');
        }
        $insert->execute([
            $companyId, $id, $statement['selected_customers'][0]['company_name'], $from ?: null, $to ?: null,
            $statement['grand_total'], $statement['sum_paid'], $statement['closing_balance'],
            json_encode($statement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
    $db->commit();
    echo json_encode(['saved' => count($ids)], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('customer statement history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load or save statement history. Please try again.']);
}
