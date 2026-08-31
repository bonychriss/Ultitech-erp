<?php
require_once __DIR__ . '/functions.php';

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
        $rawId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        if ($rawId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
        }
        if (preg_match('/^s(\d+)$/i', $rawId, $m)) {
            markNotificationRead((int) $m[1]);
        } elseif (preg_match('/^c(\d+)$/i', $rawId, $m)) {
            markCoreNotificationRead((int) $m[1]);
        } elseif (ctype_digit($rawId)) {
            markNotificationRead((int) $rawId);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
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
