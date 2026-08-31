<?php
/**
 * Serve inline MIME images referenced by cid: in email HTML.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../includes/email_bootstrap.php';
requireLogin();

$email_id = (int) ($_GET['email_id'] ?? 0);
$cid = trim((string) ($_GET['cid'] ?? ''), '<> ');
$user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($email_id <= 0 || $cid === '') {
    http_response_code(400);
    exit('Bad request');
}

$pdo = email_module_pdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Email storage unavailable');
}

$stmt = $pdo->prepare('SELECT body, message_id FROM module_emails WHERE id = ? AND (user_id = ? OR user_id = 0) LIMIT 1');
$stmt->execute(array($email_id, $user_id));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || ($row['body'] ?? '') === '') {
    http_response_code(404);
    exit('Email not found');
}

$body = (string) $row['body'];
$messageId = (string) ($row['message_id'] ?? '');

$img = email_get_inline_image_from_mime($body, $cid);
if ($img === null && $messageId !== '') {
    $imapImages = email_fetch_inline_images_from_imap($messageId);
    $img = email_find_inline_image($imapImages, $cid);
}
if ($img === null) {
    http_response_code(404);
    exit('Image not found');
}

$binary = base64_decode((string) ($img['data'] ?? ''), true);
if ($binary === false) {
    http_response_code(500);
    exit('Invalid image data');
}

header('Content-Type: ' . ($img['mime'] ?? 'image/png'));
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($binary));
echo $binary;
