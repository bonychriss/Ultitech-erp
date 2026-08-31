<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

try {
    $imap = new ImapService($CONFIG);
    $imap->connect();
    $imap->close();
    $imapOk = true;
    $imapError = null;
} catch (Exception $e) {
    $imapOk = false;
    $imapError = $e->getMessage();
}

try {
    $smtp = new SmtpService($CONFIG);
    $smtp->test();
    $smtpOk = true;
    $smtpError = null;
} catch (Exception $e) {
    $smtpOk = false;
    $smtpError = $e->getMessage();
}

JsonResponse::ok(array(
    'brand' => isset($CONFIG['brand']) ? $CONFIG['brand'] : '',
    'domain' => isset($CONFIG['domain']) ? $CONFIG['domain'] : '',
    'mailbox' => isset($CONFIG['mailbox_email']) ? $CONFIG['mailbox_email'] : '',
    'php' => PHP_VERSION,
    'checks' => array(
        'imap' => array('ok' => $imapOk, 'error' => $imapError),
        'smtp' => array('ok' => $smtpOk, 'error' => $smtpError),
        'php_imap' => array('ok' => function_exists('imap_open')),
    ),
), 'health');
