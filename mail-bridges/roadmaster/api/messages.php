<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$since = isset($_GET['since']) ? trim((string) $_GET['since']) : null;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$includeBody = !isset($_GET['include_body']) || $_GET['include_body'] !== '0';

try {
    $imap = new ImapService($CONFIG);
    $imap->connect();
    $messages = $imap->listMessages($limit, $since ? $since : null, $offset);
    if (!$includeBody) {
        foreach ($messages as &$m) {
            unset($m['body'], $m['body_html'], $m['body_text'], $m['attachments']);
        }
        unset($m);
    }
    $imap->close();
    JsonResponse::ok(array(
        'count' => count($messages),
        'mailbox' => isset($CONFIG['mailbox_email']) ? $CONFIG['mailbox_email'] : '',
        'brand' => isset($CONFIG['brand']) ? $CONFIG['brand'] : '',
        'messages' => $messages,
    ), 'messages');
} catch (Exception $e) {
    JsonResponse::error($e->getMessage(), 500);
}
