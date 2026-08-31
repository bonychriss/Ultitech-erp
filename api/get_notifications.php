<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'poll';

if ($action === 'poll') {
    $out = [];
    foreach (getUnreadCoreNotificationsForPoll($userId, 15) as $r) {
        $vid = isset($r['voucher_id']) ? (int) $r['voucher_id'] : 0;
        $out[] = [
            'id' => 'c' . (int) $r['id'],
            'title' => $r['title'] ?? '',
            'message' => $r['message'] ?? '',
            'type' => $r['type'] ?? 'info',
            'link' => $vid > 0 ? app_url('/employee/view-voucher.php?id=' . $vid) : null,
        ];
    }
    foreach (getUnreadNotifications($userId) as $r) {
        $rawLink = isset($r['link']) ? trim((string) $r['link']) : '';
        $link = null;
        if ($rawLink !== '') {
            if (preg_match('#^https?://#i', $rawLink)) {
                $link = $rawLink;
            } elseif (isset($rawLink[0]) && $rawLink[0] === '/') {
                $link = app_url($rawLink);
            } else {
                $link = app_url('/' . ltrim($rawLink, '/'));
            }
        }
        $out[] = [
            'id' => 's' . (int) $r['id'],
            'title' => $r['title'] ?? '',
            'message' => $r['message'] ?? '',
            'type' => $r['type'] ?? 'info',
            'link' => $link,
        ];
    }
    echo json_encode(['success' => true, 'notifications' => $out]);
    exit;
}

if ($action === 'read' && isset($_GET['id'])) {
    $raw = trim((string) $_GET['id']);
    if (preg_match('/^c(\d+)$/', $raw, $m)) {
        markCoreNotificationRead((int) $m[1]);
    } elseif (preg_match('/^s(\d+)$/', $raw, $m)) {
        markNotificationRead((int) $m[1]);
    } elseif (ctype_digit($raw)) {
        // Legacy: numeric id treated as system_notifications
        markNotificationRead((int) $raw);
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
