<?php

require_once __DIR__ . '/src/bootstrap.php';

JsonResponse::ok([
    'service' => 'ultitech-mail-bridge',
    'brand' => $CONFIG['brand'] ?? '',
    'domain' => $CONFIG['domain'] ?? '',
    'mailbox' => $CONFIG['mailbox_email'] ?? '',
    'endpoints' => [
        'GET  /api/health.php' => 'Auth required. Tests IMAP + SMTP.',
        'GET  /api/messages.php?limit=50&since=2026-01-01' => 'Auth required. List recent emails.',
        'GET  /api/message.php?uid=12' => 'Auth required. Fetch one message.',
        'POST /api/send.php' => 'Auth required. JSON body: to, subject, body.',
    ],
    'auth' => 'Header X-Api-Key: <your api key>',
], 'Mail bridge is online');
