<?php
/**
 * Chatbot Call directory: active users for the current company/organization.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required', 'users' => []]);
    exit;
}

$companyId = (int) ($_SESSION['company_id'] ?? 0);
if ($companyId <= 0 && function_exists('currentCompanyId')) {
    $companyId = (int) currentCompanyId();
}

/**
 * @param array<string,mixed> $row
 */
function chatbot_call_user_first_name(array $row): string
{
    $full = trim((string) ($row['full_name'] ?? ''));
    if ($full !== '') {
        $parts = preg_split('/\s+/', $full) ?: [];
        $first = trim((string) ($parts[0] ?? ''));
        if ($first !== '') {
            return $first;
        }
    }
    $username = trim((string) ($row['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }
    return 'User';
}

try {
    $db = $pdo ?? null;
    if (!$db instanceof PDO && function_exists('erp_data_pdo')) {
        $db = erp_data_pdo();
    }
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $select = ['id', 'username'];
    foreach (['full_name', 'phone', 'department', 'role', 'is_active', 'company_id', 'status'] as $col) {
        if (in_array($col, $cols, true)) {
            $select[] = $col;
        }
    }

    $where = ['1=1'];
    $params = [];
    if (in_array('is_active', $cols, true)) {
        $where[] = 'COALESCE(is_active, 1) = 1';
    }
    if (in_array('status', $cols, true)) {
        $where[] = "(status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('active', 'approved', '1'))";
    }
    if ($companyId > 0 && in_array('company_id', $cols, true)) {
        $where[] = 'company_id = ?';
        $params[] = $companyId;
    }

    $order = in_array('full_name', $cols, true) ? 'full_name ASC, username ASC' : 'username ASC';
    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM users WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $order
        . ' LIMIT 300';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Presence (online if heartbeat within last 45s)
    $onlineMap = [];
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS webrtc_presence (
            user_id INT NOT NULL PRIMARY KEY,
            company_id INT NOT NULL DEFAULT 0,
            last_seen DATETIME NOT NULL,
            KEY idx_webrtc_presence_company_seen (company_id, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $onlineSql = 'SELECT user_id FROM webrtc_presence WHERE last_seen >= (NOW() - INTERVAL 45 SECOND)';
        $onlineParams = [];
        if ($companyId > 0) {
            $onlineSql .= ' AND company_id = ?';
            $onlineParams[] = $companyId;
        }
        $ost = $db->prepare($onlineSql);
        $ost->execute($onlineParams);
        foreach ($ost->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $onlineMap[(int) $oid] = true;
        }
    } catch (Throwable $e) {
        $onlineMap = [];
    }

    $users = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        // Skip the protected system admin account from the call list when obvious
        $username = strtolower(trim((string) ($row['username'] ?? '')));
        if ($username === 'admin' || $username === '@admin') {
            continue;
        }
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            continue;
        }

        $first = chatbot_call_user_first_name($row);
        $phone = trim((string) ($row['phone'] ?? ''));
        $phoneDigits = preg_replace('/[^\d+]/', '', $phone) ?: '';

        $users[] = [
            'id' => $id,
            'first_name' => $first,
            'full_name' => trim((string) ($row['full_name'] ?? $first)),
            'department' => trim((string) ($row['department'] ?? '')),
            'phone' => $phone,
            'tel' => $phoneDigits !== '' ? ('tel:' . $phoneDigits) : '',
            'has_phone' => $phoneDigits !== '',
            'online' => !empty($onlineMap[$id]),
        ];
    }

    // Stable A�Z by first name
    usort($users, static function ($a, $b) {
        return strcasecmp((string) $a['first_name'], (string) $b['first_name']);
    });

    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'count' => count($users),
        'users' => $users,
    ]);
} catch (Throwable $e) {
    error_log('chatbot_call_users.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load users', 'users' => []]);
}
