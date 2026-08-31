<?php
/**
 * JSON single message for email-ui reader pane.
 * Opening a message marks it read in the DB.
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

if (!function_exists('email_api_json')) {
    function email_api_json(array $payload): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($payload, $flags);
        if ($json === false) {
            if (isset($payload['email']['body'])) {
                $payload['email']['body'] = preg_replace(
                    '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
                    '',
                    (string) $payload['email']['body']
                );
                if (function_exists('mb_convert_encoding')) {
                    $payload['email']['body'] = mb_convert_encoding(
                        (string) $payload['email']['body'],
                        'UTF-8',
                        'UTF-8'
                    );
                }
            }
            $json = json_encode($payload, $flags);
        }
        if ($json === false) {
            $json = '{"status":"error","message":"Could not encode message"}';
        }
        echo $json;
        exit;
    }
}

if (!function_exists('email_safe_display_body')) {
    function email_safe_display_body(string $body): string
    {
        $body = (string) $body;
        if ($body === '') {
            return '<p>(empty)</p>';
        }

        if (preg_match_all('/=[0-9A-Fa-f]{2}/', $body) > 3) {
            $decoded = @quoted_printable_decode($body);
            if (is_string($decoded) && $decoded !== '') {
                $body = $decoded;
            }
        }

        $looksHtml = (bool) preg_match('/<(p|div|br|table|html|body|span|a)\b/i', $body);
        if ($looksHtml) {
            // Drop embedded CSS/JS so message styles cannot restyle the ERP sidebar.
            $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $body) ?? $body;
            $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body) ?? $body;
            $body = preg_replace('/<link\b[^>]*>/i', '', $body) ?? $body;
            return $body;
        }

        return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    require_once __DIR__ . '/../includes/email_remote_bridges.php';
    requireLogin();

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        email_api_json(['status' => 'error', 'message' => 'Missing id']);
    }

    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        email_api_json(['status' => 'error', 'message' => 'Email database unavailable.']);
    }

    $stmt = $emailDb->prepare(
        'SELECT id, user_id, sender_email, recipient_email, subject, body, direction, status, is_starred, created_at, message_id
         FROM module_emails WHERE id = ? AND (user_id = ? OR user_id = 0) LIMIT 1'
    );
    $stmt->execute([$id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        email_api_json(['status' => 'error', 'message' => 'Email not found']);
    }

    $wasUnread = strtolower(trim((string) ($row['status'] ?? ''))) === 'unread';
    if ($wasUnread) {
        try {
            $upd = $emailDb->prepare(
                "UPDATE module_emails SET status = 'read' WHERE id = ? AND (user_id = ? OR user_id = 0) AND status = 'unread'"
            );
            $upd->execute([$id, $user_id]);
            $row['status'] = 'read';
        } catch (Throwable $e) {
            error_log('email mark read: ' . $e->getMessage());
        }
    }

    $attachments = [];
    try {
        if (function_exists('email_connection_has_table') && email_connection_has_table($emailDb, 'module_email_attachments')) {
            $ast = $emailDb->prepare(
                'SELECT id, file_name, file_path, file_size, file_type FROM module_email_attachments WHERE email_id = ?'
            );
            $ast->execute([$id]);
            $attachments = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
    }

    $messageId = (string) ($row['message_id'] ?? '');
    if ($attachments === [] && $messageId !== '' && function_exists('email_backfill_attachments_from_bridge')) {
        try {
            email_backfill_attachments_from_bridge($emailDb, $id, $messageId);
            $ast = $emailDb->prepare(
                'SELECT id, file_name, file_path, file_size, file_type FROM module_email_attachments WHERE email_id = ?'
            );
            $ast->execute([$id]);
            $attachments = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
        }
    }

    $rawBody = (string) ($row['body'] ?? '');
    if ($rawBody !== '' && stripos($rawBody, 'cid:') !== false) {
        if (function_exists('email_repair_body_inline_images')) {
            $rawBody = email_repair_body_inline_images($rawBody, $id, $messageId);
        }
    } elseif (function_exists('parse_email_body_mime')) {
        list(, $parsedBody) = parse_email_body_mime($rawBody, $id, $messageId);
        if (is_string($parsedBody) && $parsedBody !== '') {
            $rawBody = $parsedBody;
        }
    }

    $body = email_safe_display_body($rawBody);

    email_api_json([
        'status' => 'success',
        'was_unread' => $wasUnread,
        'email' => [
            'id' => (int) $row['id'],
            'sender' => (string) ($row['sender_email'] ?? ''),
            'recipient' => (string) ($row['recipient_email'] ?? ''),
            'subject' => (string) ($row['subject'] ?? '(no subject)'),
            'body' => $body,
            'direction' => (string) ($row['direction'] ?? 'inbound'),
            'status' => 'read',
            'is_starred' => !empty($row['is_starred']),
            'unread' => false,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'attachments' => $attachments,
        ],
    ]);
} catch (Throwable $e) {
    email_api_json(['status' => 'error', 'message' => 'Could not open message.']);
}
