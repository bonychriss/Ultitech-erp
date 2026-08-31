<?php

require_once __DIR__ . '/src/bootstrap.php';

JsonResponse::ok(array(
    'service' => 'ultitech-mail-bridge',
    'brand' => isset($CONFIG['brand']) ? $CONFIG['brand'] : '',
    'domain' => isset($CONFIG['domain']) ? $CONFIG['domain'] : '',
    'mailbox' => isset($CONFIG['mailbox_email']) ? $CONFIG['mailbox_email'] : '',
    'php' => PHP_VERSION,
    'endpoints' => array(
        'GET  /api/health.php' => 'Auth required. Tests IMAP + SMTP.',
        'GET  /api/messages.php?limit=50&since=2026-01-01' => 'Auth required. List recent emails.',
        'GET  /api/message.php?uid=12' => 'Auth required. Fetch one message.',
        'POST /api/send.php' => 'Auth required. JSON body: to, subject, body.',
    ),
    'auth' => 'Header Authorization: Bearer <key> (or X-Api-Key / ?api_key=)',
), 'Mail bridge is online');
