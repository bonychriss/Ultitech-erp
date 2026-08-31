<?php
// modules/email/api/send.php
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
    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Email database unavailable.']);
        exit;
    }

    $recipients = explode(',', (string) ($_POST['recipient_email'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    $cc = trim((string) ($_POST['cc'] ?? ''));
    $bcc = trim((string) ($_POST['bcc'] ?? ''));
    $customer_id = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;

    $recipients = array_values(array_filter(array_map('trim', $recipients)));
    if ($recipients === [] || $subject === '' || trim(strip_tags($body)) === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
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
        // Normalize single-file uploads to multi-file shape.
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
                        'message' => 'Security Blocked: Malware detected in file "' . htmlspecialchars($filename) . '"! Upload cancelled.',
                    ]);
                    exit;
                }
            }

            $unique_name = uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename);
            $target_path = $upload_dir . $unique_name;

            if (move_uploaded_file($tmpPath, $target_path)) {
                $totalSize += $size;
                $relative_path = 'uploads/email_attachments/' . $unique_name;
                $attachments[] = [
                    'path' => $target_path,
                    'name' => $filename,
                ];
                $db_attachments[] = [
                    'name' => $filename,
                    'path' => $relative_path,
                    'size' => $size,
                    'type' => (string) ($files['type'][$index] ?? 'application/octet-stream'),
                ];
            }
        }
    }

    $smtp_settings = null;
    try {
        $stmtS = $emailDb->prepare('SELECT * FROM module_email_user_settings WHERE user_id = ?');
        if ($stmtS->execute([$user_id])) {
            $smtp_settings = $stmtS->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $smtp_settings = null;
    }

    $preferredBridge = '';
    if (function_exists('email_resolve_preferred_bridge')) {
        $preferredBridge = email_resolve_preferred_bridge();
    } else {
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? '') . ' ' . ($_SERVER['HTTP_HOST'] ?? ''));
        if (strpos($uri, 'roadmaster') !== false) {
            $preferredBridge = 'roadmaster';
        } elseif (strpos($uri, 'ultimate') !== false) {
            $preferredBridge = 'ultimate';
        }
    }

    $fromName = function_exists('email_outbound_from_name')
        ? email_outbound_from_name($preferredBridge)
        : (string) ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Staff'));

    if (function_exists('email_wrap_body_with_company_logo')) {
        $body = email_wrap_body_with_company_logo($body);
    }

    $success_count = 0;
    $lastError = '';

    foreach ($recipients as $recipient) {
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $lastError = 'Invalid recipient address.';
            continue;
        }

        $mailSent = false;
        $from_email = '';

        // Fast path: company mailbox SMTP (Ultimate/Roadmaster package) — skip slow fallbacks.
        if (
            !$mailSent
            && $preferredBridge !== ''
            && function_exists('email_local_bridge_smtp_config')
            && function_exists('email_smtp_send_simple')
        ) {
            $local = email_local_bridge_smtp_config($preferredBridge);
            if ($local) {
                try {
                    $from_email = $local['mailbox'] !== '' ? $local['mailbox'] : $local['user'];
                    $connectTimeout = $attachments !== [] ? 15 : 5;
                    $readTimeout = $attachments !== [] ? 90 : 20;
                    $mailSent = email_smtp_send_simple(
                        $local['host'],
                        $local['port'],
                        $local['user'],
                        $local['pass'],
                        $local['secure'],
                        $from_email,
                        $fromName !== '' ? $fromName : (string) $local['from_name'],
                        $recipient,
                        $subject,
                        $body,
                        $attachments,
                        $connectTimeout,
                        $readTimeout
                    );
                    if (!$mailSent) {
                        $lastError = 'Mailbox SMTP send failed.';
                    }
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
        }

        // 1) Personal SMTP
        if (!$mailSent && !empty($smtp_settings['smtp_host']) && !empty($smtp_settings['smtp_user'])) {
            try {
                $from_email = (string) $smtp_settings['smtp_user'];
                $mailSent = email_smtp_send_simple(
                    (string) $smtp_settings['smtp_host'],
                    $smtp_settings['smtp_port'] ?: 587,
                    (string) $smtp_settings['smtp_user'],
                    (string) ($smtp_settings['smtp_pass'] ?? ''),
                    (string) ($smtp_settings['smtp_ssl'] ?: 'tls'),
                    $from_email,
                    $fromName,
                    $recipient,
                    $subject,
                    $body,
                    $attachments
                );
                if (!$mailSent) {
                    $lastError = 'Personal SMTP send failed.';
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        // 2) Company SMTP (admin Email settings)
        if (!$mailSent && function_exists('email_get_company_smtp_settings')) {
            $companySmtp = email_get_company_smtp_settings($emailDb);
            if ($companySmtp) {
                try {
                    $from_email = $companySmtp['user'];
                    $mailSent = email_smtp_send_simple(
                        $companySmtp['host'],
                        $companySmtp['port'],
                        $companySmtp['user'],
                        $companySmtp['pass'],
                        $companySmtp['secure'],
                        $from_email,
                        $fromName,
                        $recipient,
                        $subject,
                        $body,
                        $attachments
                    );
                    if (!$mailSent) {
                        $lastError = 'Company SMTP send failed.';
                    }
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
        }

        // 3) Company remote bridge / local package SMTP (supports attachments via local SMTP)
        if (!$mailSent && function_exists('email_bridge_send_mail')) {
            $bridgeResult = email_bridge_send_mail($recipient, $subject, $body, $fromName, $preferredBridge, $attachments);
            if (!empty($bridgeResult['ok'])) {
                $mailSent = true;
                $from_email = (string) ($bridgeResult['from'] ?? '');
                if ($from_email === '') {
                    $from_email = $preferredBridge !== ''
                        ? ('sales@' . $preferredBridge . '.co.tz')
                        : (defined('COMPANY_EMAIL') ? (string) COMPANY_EMAIL : 'noreply@local');
                }
            } else {
                $err = (string) ($bridgeResult['error'] ?? '');
                if ($attachments !== [] && $err === '') {
                    $err = 'Attachments require working SMTP settings.';
                }
                $lastError = $err !== '' ? $err : $lastError;
            }
        }

        // 4) PHP mail() last resort (usually fails on XAMPP)
        if (!$mailSent && $attachments === [] && empty($smtp_settings['smtp_host'])) {
            $fallbackFrom = defined('COMPANY_EMAIL') && COMPANY_EMAIL
                ? (string) COMPANY_EMAIL
                : 'noreply@localhost';
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n";
            $headers .= 'From: ' . $fromName . ' <' . $fallbackFrom . ">\r\n";
            if ($cc !== '') {
                $headers .= 'Cc: ' . $cc . "\r\n";
            }
            if ($bcc !== '') {
                $headers .= 'Bcc: ' . $bcc . "\r\n";
            }
            $mailSent = @mail($recipient, $subject, $body, $headers);
            if ($mailSent) {
                $from_email = $fallbackFrom;
            } elseif ($lastError === '') {
                $lastError = 'No SMTP or remote bridge available.';
            }
        }

        if (!$mailSent) {
            continue;
        }

        $success_count++;
        if ($from_email === '') {
            $from_email = defined('COMPANY_EMAIL') && COMPANY_EMAIL
                ? (string) COMPANY_EMAIL
                : 'noreply@local';
        }

        // Company mailbox sends are shared (user_id=0) so they show in Sent for all staff.
        $storeUserId = ($preferredBridge !== '') ? 0 : $user_id;

        try {
            $stmt = $emailDb->prepare("
                INSERT INTO module_emails (user_id, customer_id, sender_email, recipient_email, subject, body, direction, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'outbound', 'read', NOW())
            ");
            $stmt->execute([$storeUserId, $customer_id, $from_email, $recipient, $subject, $body]);
            $email_id = $emailDb->lastInsertId();

            if ($email_id && $db_attachments !== []) {
                $stmtA = $emailDb->prepare("
                    INSERT INTO module_email_attachments (email_id, file_name, file_path, file_size, file_type)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($db_attachments as $db_att) {
                    $stmtA->execute([
                        $email_id,
                        $db_att['name'],
                        $db_att['path'],
                        $db_att['size'],
                        $db_att['type'],
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('Database Insertion Error: ' . $e->getMessage());
            // Still report send success if SMTP delivered; surface DB issue in message.
            if ($success_count > 0 && $lastError === '') {
                $lastError = 'Sent, but could not save to Sent folder: ' . $e->getMessage();
            }
        }
    }

    if ($success_count > 0) {
        echo json_encode([
            'status' => 'success',
            'message' => "Email sent successfully to $success_count recipient(s)",
        ]);
        exit;
    }

    $msg = $lastError !== ''
        ? $lastError
        : 'Failed to send email. Set personal SMTP in My Account, or enable a Remote Bridge.';
    echo json_encode(['status' => 'error', 'message' => $msg]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
