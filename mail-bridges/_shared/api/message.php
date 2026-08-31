<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

$uid = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
$messageId = isset($_GET['message_id']) ? trim((string) $_GET['message_id']) : '';

try {
    $imap = new ImapService($CONFIG);
    $imap->connect();
    if ($uid > 0) {
        $message = $imap->fetchMessage($uid, true);
    } elseif ($messageId !== '') {
        $message = $imap->findByMessageId($messageId);
        if ($message === null) {
            JsonResponse::error('Message not found for message_id.', 404);
        }
    } else {
        JsonResponse::error('Provide uid or message_id.');
    }
    $imap->close();
    JsonResponse::ok(['message' => $message], 'message');
} catch (Throwable $e) {
    JsonResponse::error($e->getMessage(), 500);
}
