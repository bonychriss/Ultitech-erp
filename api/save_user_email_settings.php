<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/email/includes/email_bootstrap.php';

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

try {
    $pdo = email_module_pdo();
    if (!$pdo) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    
    // Sanitize inputs
    $imap_host = trim($_POST['imap_host'] ?? '');
    $imap_port = trim($_POST['imap_port'] ?? '993');
    $imap_user = trim($_POST['imap_user'] ?? '');
    $imap_pass = trim($_POST['imap_pass'] ?? '');
    $imap_ssl  = trim($_POST['imap_ssl'] ?? 'ssl');

    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = trim($_POST['smtp_port'] ?? '465');
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = trim($_POST['smtp_pass'] ?? '');
    $smtp_ssl  = trim($_POST['smtp_ssl'] ?? 'ssl');

    if (empty($imap_host) || empty($imap_user) || empty($smtp_host) || empty($smtp_user)) {
        echo json_encode(['status' => 'error', 'message' => 'Host and Username fields are required.']);
        exit;
    }

    // Insert or update
    $sql = "INSERT INTO module_email_user_settings 
            (user_id, imap_host, imap_port, imap_user, imap_pass, imap_ssl, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_ssl) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            imap_host = VALUES(imap_host), imap_port = VALUES(imap_port), imap_user = VALUES(imap_user), 
            imap_pass = VALUES(imap_pass), imap_ssl = VALUES(imap_ssl), smtp_host = VALUES(smtp_host), 
            smtp_port = VALUES(smtp_port), smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass), smtp_ssl = VALUES(smtp_ssl)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $user_id, $imap_host, $imap_port, $imap_user, $imap_pass, $imap_ssl,
        $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_ssl
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully.']);
} catch (Throwable $e) {
    error_log("Save Email Settings Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An error occurred while saving: ' . $e->getMessage()]);
}
