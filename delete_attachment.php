<?php
// Suppress all errors/notices to ensure a clean JSON response
error_reporting(0);
ini_set('display_errors', 0);

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$attachmentId = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
if ($attachmentId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid attachment ID']);
    exit;
}

$result = deleteVoucherAttachment($attachmentId, $_SESSION['user_id']);
echo json_encode($result);
exit;
