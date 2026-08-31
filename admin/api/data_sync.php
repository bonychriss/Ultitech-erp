<?php
require_once '../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

// 1. Fetch settings from DB
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_imap_%'");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (empty($settings['email_imap_host'])) {
    echo json_encode(['status' => 'error', 'message' => 'IMAP Config Missing']);
    exit;
}

// 2. Connect to IMAP
$host = $settings['email_imap_host'];
$port = $settings['email_imap_port'] ?: '993';
$user = $settings['email_imap_user'];
$pass = $settings['email_imap_pass'];
$ssl  = $settings['email_imap_ssl'] ?: 'ssl';

$mbox_path = "{" . $host . ":" . $port . "/imap/" . $ssl . "/novalidate-cert}INBOX";
$mbox = @imap_open($mbox_path, $user, $pass);

if (!$mbox) {
    echo json_encode(['status' => 'error', 'message' => 'IMAP Fail']);
    exit;
}

// 3. Process 5 emails
$emails = imap_search($mbox, 'ALL');
$count = 0;
if ($emails) {
    rsort($emails);
    $emails = array_slice($emails, 0, 5); 
    foreach ($emails as $m) {
        $ov = imap_fetch_overview($mbox, $m, 0)[0];
        $msg_id = $ov->message_id ?? null;
        
        $check = $pdo->prepare("SELECT id FROM module_emails WHERE message_id = ?");
        $check->execute([$msg_id]);
        if (!$check->fetch()) {
            $body = imap_fetchbody($mbox, $m, 1);
            $ins = $pdo->prepare("INSERT INTO module_emails (user_id, sender_email, subject, body, direction, status, created_at, message_id) VALUES (0, ?, ?, ?, 'inbound', 'unread', NOW(), ?)");
            $ins->execute([$ov->from, imap_utf8($ov->subject), $body, $msg_id]);
            $count++;
        }
    }
}
imap_close($mbox);
echo json_encode(['status' => 'success', 'message' => "Synced $count emails", 'count' => $count]);
