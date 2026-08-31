<?php
// modules/email/api/send_reply.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../../../includes/SimpleSMTP.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    require_once __DIR__ . '/../includes/email_remote_bridges.php';
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
        exit;
    }

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $email_id = (int) ($_POST['id'] ?? 0);
    $reply_body = trim((string) ($_POST['message'] ?? ''));

    if ($email_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing original email ID']);
        exit;
    }
    if ($reply_body === '') {
        echo json_encode(['status' => 'error', 'message' => 'Reply message is required']);
        exit;
    }

    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Email database unavailable.']);
        exit;
    }

    $stmt = $emailDb->prepare(
        "SELECT id, sender_email, recipient_email, body, subject, message_id
         FROM module_emails
         WHERE id = ? AND (user_id = ? OR user_id = 0)
         LIMIT 1"
    );
    $stmt->execute([$email_id, $user_id]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$original) {
        echo json_encode(['status' => 'error', 'message' => 'Original email not found']);
        exit;
    }

    $subject = (stripos((string) ($original['subject'] ?? ''), 're:') === 0)
        ? (string) $original['subject']
        : ('Re: ' . (($original['subject'] ?? '') !== '' ? $original['subject'] : '(No Subject)'));

    $to = (string) ($original['sender_email'] ?? '');
    if (preg_match('/<([^>]+)>/', $to, $matches)) {
        $to = trim($matches[1]);
    }
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $original['sender_email'], $m)) {
            $to = $m[0];
        }
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not determine reply address.']);
        exit;
    }

    $attachments = [];
    $db_attachments = [];
    if (!empty($_FILES['attachments']['tmp_name'])) {
        $upload_dir = __DIR__ . '/../../../uploads/email_attachments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $files = $_FILES['attachments'];
        if (!is_array($files['tmp_name'])) {
            $files = [
                'tmp_name' => [$files['tmp_name']],
                'name' => [$files['name']],
                'error' => [$files['error']],
                'size' => [$files['size']],
                'type' => [$files['type']],
            ];
        }

        $maxEach = 12 * 1024 * 1024;
        $maxTotal = 25 * 1024 * 1024;
        $totalSize = 0;

        foreach ($files['tmp_name'] as $index => $tmpPath) {
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
                continue;
            }
            $filename = basename((string) ($files['name'][$index] ?? 'file'));
            $size = (int) ($files['size'][$index] ?? 0);
            if ($size > $maxEach) {
                echo json_encode(['status' => 'error', 'message' => 'File too large: ' . $filename . ' (max 12MB).']);
                exit;
            }
            if (($totalSize + $size) > $maxTotal) {
                echo json_encode(['status' => 'error', 'message' => 'Total attachments exceed 25MB.']);
                exit;
            }

            if (function_exists('email_scan_file_for_virus')) {
                $scan = email_scan_file_for_virus($tmpPath);
                if (($scan['status'] ?? '') === 'infected') {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Security Blocked: Malware detected in file "' . htmlspecialchars($filename) . '"!',
                    ]);
                    exit;
                }
            }

            $unique_name = uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename);
            $target_path = $upload_dir . $unique_name;
            if (move_uploaded_file($tmpPath, $target_path)) {
                $totalSize += $size;
                $attachments[] = [
                    'path' => $target_path,
                    'name' => $filename,
                ];
                $db_attachments[] = [
                    'name' => $filename,
                    'path' => 'uploads/email_attachments/' . $unique_name,
                    'size' => $size,
                    'type' => (string) ($files['type'][$index] ?? 'application/octet-stream'),
                ];
            }
        }
    }

    $sent = false;
    $fromEmail = '';
    $sendError = '';

    $preferred = '';
    if (function_exists('email_resolve_preferred_bridge')) {
        $preferred = email_resolve_preferred_bridge((string) ($original['recipient_email'] ?? ''));
    } else {
        $recipientHint = strtolower((string) ($original['recipient_email'] ?? ''));
        $uriHint = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (strpos($recipientHint, 'roadmaster') !== false || strpos($uriHint, 'roadmaster') !== false) {
            $preferred = 'roadmaster';
        } elseif (strpos($recipientHint, 'ultimate') !== false || strpos($uriHint, 'ultimate') !== false) {
            $preferred = 'ultimate';
        }
    }

    $fromName = function_exists('email_outbound_from_name')
        ? email_outbound_from_name($preferred)
        : (string) ($_SESSION['company_name'] ?? 'Staff');

    $safeReply = nl2br(htmlspecialchars($reply_body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $origBody = (string) ($original['body'] ?? '');
    $origBody = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $origBody) ?? $origBody;
    $html_body = $safeReply
        . '<br><br><hr style="border:none;border-top:1px solid #e2e8f0;margin:1.25rem 0">'
        . '<p style="color:#64748b;font-size:12px;margin:0 0 8px"><strong>From:</strong> '
        . htmlspecialchars((string) $original['sender_email'], ENT_QUOTES, 'UTF-8')
        . '<br><strong>Subject:</strong> '
        . htmlspecialchars((string) ($original['subject'] ?? ''), ENT_QUOTES, 'UTF-8')
        . '</p>'
        . $origBody;

    if (function_exists('email_wrap_body_with_company_logo')) {
        $html_body = email_wrap_body_with_company_logo($html_body);
    }

    $connectTimeout = $attachments !== [] ? 15 : 5;
    $readTimeout = $attachments !== [] ? 90 : 20;

    // Fast path: company mailbox SMTP (local package / bridge helper)
    if (!$sent && function_exists('email_bridge_send_mail')) {
        $bridgeResult = email_bridge_send_mail($to, $subject, $html_body, $fromName, $preferred, $attachments);
        if (!empty($bridgeResult['ok'])) {
            $sent = true;
            $fromEmail = (string) ($bridgeResult['from'] ?? '');
        } else {
            $sendError = (string) ($bridgeResult['error'] ?? '');
        }
    }

    // Personal SMTP
    $settings = null;
    try {
        $st = $emailDb->prepare('SELECT * FROM module_email_user_settings WHERE user_id = ?');
        $st->execute([$user_id]);
        $settings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $settings = null;
    }

    if (!$sent && !empty($settings['smtp_host']) && !empty($settings['smtp_user'])) {
        try {
            $fromEmail = (string) $settings['smtp_user'];
            $sent = function_exists('email_smtp_send_simple')
                ? email_smtp_send_simple(
                    (string) $settings['smtp_host'],
                    $settings['smtp_port'] ?: 587,
                    (string) $settings['smtp_user'],
                    (string) ($settings['smtp_pass'] ?? ''),
                    (string) ($settings['smtp_ssl'] ?: 'tls'),
                    $fromEmail,
                    $fromName,
                    $to,
                    $subject,
                    $html_body,
                    $attachments,
                    $connectTimeout,
                    $readTimeout
                )
                : false;
            if (!$sent) {
                $sendError = 'Personal SMTP send failed.';
            }
        } catch (Throwable $e) {
            $sendError = $e->getMessage();
        }
    }

    // Company SMTP
    if (!$sent && function_exists('email_get_company_smtp_settings') && function_exists('email_smtp_send_simple')) {
        $companySmtp = email_get_company_smtp_settings($emailDb);
        if ($companySmtp) {
            try {
                $fromEmail = $companySmtp['user'];
                $sent = email_smtp_send_simple(
                    $companySmtp['host'],
                    $companySmtp['port'],
                    $companySmtp['user'],
                    $companySmtp['pass'],
                    $companySmtp['secure'],
                    $fromEmail,
                    $fromName,
                    $to,
                    $subject,
                    $html_body,
                    $attachments,
                    $connectTimeout,
                    $readTimeout
                );
                if (!$sent) {
                    $sendError = 'Company SMTP send failed.';
                }
            } catch (Throwable $e) {
                $sendError = $e->getMessage();
            }
        }
    }

    if (!$sent) {
        $msg = $sendError !== ''
            ? $sendError
            : 'No mail send method configured. Set personal SMTP in My Account, or enable a Remote Bridge.';
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }

    if ($fromEmail === '' || strpos($fromEmail, '@bridge.local') !== false) {
        $fromEmail = (string) ($original['recipient_email'] ?? 'noreply@local');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromEmail, $m)) {
            $fromEmail = $m[0];
        }
    }

    $storeUserId = ($preferred !== '') ? 0 : $user_id;
    $ins = $emailDb->prepare("
        INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'outbound', 'read', NOW())
    ");
    $ins->execute([$storeUserId, $fromEmail, $to, $subject, $html_body]);
    $newId = (int) $emailDb->lastInsertId();

    if ($newId > 0 && $db_attachments !== []) {
        try {
            $stmtA = $emailDb->prepare("
                INSERT INTO module_email_attachments (email_id, file_name, file_path, file_size, file_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($db_attachments as $db_att) {
                $stmtA->execute([
                    $newId,
                    $db_att['name'],
                    $db_att['path'],
                    $db_att['size'],
                    $db_att['type'],
                ]);
            }
        } catch (Throwable $e) {
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Reply sent successfully',
        'to' => $to,
        'subject' => $subject,
        'attachment_count' => count($db_attachments),
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not send reply.']);
}
