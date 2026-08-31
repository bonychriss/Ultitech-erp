<?php
// modules/email/api/block_sender.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

if (isset($_POST['email'])) {
    $blocked_email = trim(strtolower((string)$_POST['email']));
    if ($blocked_email === '') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
        exit;
    }
    
    // Retrieve current blocked senders list
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'email_blocked_senders'");
    $stmt->execute();
    $current = $stmt->fetchColumn() ?: '';
    
    $blocked_list = array_filter(array_map('trim', explode(',', strtolower($current))));
    
    if (!in_array($blocked_email, $blocked_list)) {
        $blocked_list[] = $blocked_email;
        $new_value = implode(',', $blocked_list);
        
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('email_blocked_senders', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$new_value, $new_value]);
    }
    
    // Also, update status of ALL emails from this sender to 'spam'
    $stmt = $pdo->prepare("UPDATE module_emails SET status = 'spam' WHERE LOWER(sender_email) LIKE ? OR LOWER(sender_email) = ?");
    // Handle both raw sender_email and any name prefixes (e.g. Sales <sales@ultimate.co.tz>)
    $sender_like = '%' . $blocked_email . '%';
    $stmt->execute([$sender_like, $blocked_email]);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Sender blocked and emails moved to Spam successfully.'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing email parameter']);
}
