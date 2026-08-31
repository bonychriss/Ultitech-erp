<?php
// modules/email/api/toggle_star.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../includes/email_bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$id = (int) $_POST['id'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$emailDb = email_module_pdo();

if (!($emailDb instanceof PDO)) {
    echo json_encode(['status' => 'error', 'message' => 'Email storage unavailable']);
    exit;
}

ensure_email_module_schema($emailDb);
email_ensure_starred_column($emailDb);

$stmt = $emailDb->prepare('SELECT is_starred FROM module_emails WHERE id = ? AND (user_id = ? OR user_id = 0) LIMIT 1');
$stmt->execute([$id, $user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'Email not found']);
    exit;
}

if (isset($_POST['starred'])) {
    $newStarred = (int) ((int) $_POST['starred'] === 1);
} else {
    $newStarred = (int) !((int) ($row['is_starred'] ?? 0));
}

$upd = $emailDb->prepare('UPDATE module_emails SET is_starred = ? WHERE id = ? AND (user_id = ? OR user_id = 0)');
if (!$upd->execute([$newStarred, $id, $user_id])) {
    echo json_encode(['status' => 'error', 'message' => 'Could not update star status']);
    exit;
}

$countStmt = $emailDb->prepare('SELECT COUNT(*) FROM module_emails WHERE (user_id = ? OR user_id = 0) AND is_starred = 1 AND status != \'trash\'');
$countStmt->execute([$user_id]);
$starredCount = (int) $countStmt->fetchColumn();

echo json_encode([
    'status' => 'success',
    'is_starred' => $newStarred,
    'starred_count' => $starredCount,
    'message' => $newStarred ? 'Email starred' : 'Star removed',
]);
