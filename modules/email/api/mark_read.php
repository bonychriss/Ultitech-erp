<?php
// modules/email/api/mark_read.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("UPDATE module_emails SET status = 'read' WHERE id = ? AND (user_id = ? OR user_id = 0) AND status = 'unread'");
    $stmt->execute([$id, $user_id]);
}
