<?php
/**
 * Update email status (single or bulk): read, unread, archived, trash, spam.
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
        exit;
    }

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $statusRaw = trim((string) ($_POST['status'] ?? ''));

    // "inbox" restores a message to the inbox as read.
    $statusMap = [
        'inbox' => 'read',
        'read' => 'read',
        'unread' => 'unread',
        'archived' => 'archived',
        'archive' => 'archived',
        'trash' => 'trash',
        'spam' => 'spam',
    ];
    if (!isset($statusMap[$statusRaw])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
        exit;
    }
    $status = $statusMap[$statusRaw];

    $ids = [];
    if (isset($_POST['ids'])) {
        $decoded = json_decode((string) $_POST['ids'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    }
    if (isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        echo json_encode(['status' => 'error', 'message' => 'No emails selected']);
        exit;
    }

    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Email database unavailable.']);
        exit;
    }
    ensure_email_module_schema($emailDb);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$status], $ids, [$user_id]);
    $stmt = $emailDb->prepare(
        "UPDATE module_emails
         SET status = ?
         WHERE id IN ($placeholders) AND (user_id = ? OR user_id = 0)"
    );
    $stmt->execute($params);
    $updated = (int) $stmt->rowCount();

    $friendly = [
        'read' => 'moved to Inbox',
        'unread' => 'marked as unread',
        'archived' => 'archived',
        'trash' => 'moved to Trash',
        'spam' => 'marked as spam',
    ];

    echo json_encode([
        'status' => 'success',
        'updated' => $updated,
        'new_status' => $status,
        'message' => ($updated === 1 ? 'Email ' : $updated . ' emails ') . ($friendly[$status] ?? $status),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
