<?php
require_once 'functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        markAllNotificationsReadForCurrentUser();
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'mark_read') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
        }
        // Only mark readable notifications for current viewer to avoid leaking
        if (isAdmin()) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience IN ('admin','all')");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience IN ('user','all') AND (user_id = ? OR audience='all')");
            $stmt->execute([$id, $_SESSION['user_id']]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
} else {
    // GET -> list
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $data = [
        'unread' => getUnreadCountForCurrentUser(),
        'items' => getNotificationsForCurrentUser($limit),
    ];
    echo json_encode($data);
    exit;
}
?>
