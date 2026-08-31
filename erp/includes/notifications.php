<?php
// erp/includes/notifications.php

function get_unread_notifications($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM erp_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_unread_count($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

function mark_notification_read($notification_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE erp_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    return $stmt->execute([$notification_id, $user_id]);
}

function create_notification($user_id, $title, $message, $type = 'info', $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO erp_notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $title, $message, $type, $link]);
}

// Handle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'mark_read' && isset($_POST['id'])) {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        exit;
    }
    require_once '../../includes/db_connect.php'; // Adjust path if needed based on where this is included
    
    if (mark_notification_read($_POST['id'], $_SESSION['user_id'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}
?>
