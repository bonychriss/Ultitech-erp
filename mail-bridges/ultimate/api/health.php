<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

try {
    $imap = new ImapService($CONFIG);
    $imap->connect();
    $imap->close();
    $imapOk = true;
    $imapError = null;
} catch (Throwable $e) {
    $imapOk = false;
    $imapError = $e->getMessage();
}

try {
    $smtp = new SmtpService($CONFIG);
    $smtp->test();
    $smtpOk = true;
    $smtpError = null;
} catch (Throwable $e) {
    $smtpOk = false;
    $smtpError = $e->getMessage();
}

JsonResponse::ok([
    'brand' => $CONFIG['brand'] ?? '',
    'domain' => $CONFIG['domain'] ?? '',
    'mailbox' => $CONFIG['mailbox_email'] ?? '',
    'checks' => [
        'imap' => ['ok' => $imapOk, 'error' => $imapError],
        'smtp' => ['ok' => $smtpOk, 'error' => $smtpError],
        'php_imap' => ['ok' => function_exists('imap_open')],
    ],
], 'health');
