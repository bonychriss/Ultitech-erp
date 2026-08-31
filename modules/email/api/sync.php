<?php
/**
 * Hardened Sync API - Final Version with robust deduplication
 */
header('Content-Type: application/json');
error_reporting(0);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    require_once __DIR__ . '/../includes/email_remote_bridges.php';

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
        exit;
    }

    $emailDb = function_exists('email_module_pdo') ? email_module_pdo() : null;
    if (!($emailDb instanceof PDO)) {
        $emailDb = $pdo;
    }
    if ($emailDb instanceof PDO) {
        ensure_email_module_schema($emailDb);
    }

    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_imap_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    set_time_limit(180);
    $count = 0;
    $imapError = null;

    if (!empty($settings['email_imap_host'])) {
        $imap_user = $settings['email_imap_user'];
        $mbox_path = "{" . $settings['email_imap_host'] . ":" . ($settings['email_imap_port'] ?: '993') . "/imap/" . ($settings['email_imap_ssl'] ?: 'ssl') . "/novalidate-cert}INBOX";
        $mbox = @imap_open($mbox_path, $imap_user, $settings['email_imap_pass']);

        if (!$mbox) {
            if (function_exists('imap_errors')) {
                @imap_errors();
                @imap_alerts();
            }
            $imapError = 'IMAP Connection Fail: ' . imap_last_error();
        } else {
            $emails = imap_search($mbox, 'ALL');
            if ($emails) {
                rsort($emails);
                $emails = array_slice($emails, 0, 500); // Scan top 500 most recent
                foreach ($emails as $m) {
                    if ($count >= 50) break; // Fetch up to 50 at once

                    $ov = imap_fetch_overview($mbox, $m, 0)[0];
                    $msg_id = $ov->message_id ?? null;
                    $db_date = isset($ov->udate) ? date('Y-m-d H:i:s', $ov->udate) : date('Y-m-d H:i:s', strtotime($ov->date));
                    $subject = trim(imap_utf8($ov->subject));
                    $sender = trim(imap_utf8($ov->from));

                    $already_exists = false;

                    // 1. Primary Check by message_id
                    if (!empty($msg_id)) {
                        $check = $emailDb->prepare("SELECT id FROM module_emails WHERE message_id = ?");
                        $check->execute([$msg_id]);
                        if ($check->fetch()) $already_exists = true;
                    }

                    // 2. Secondary Check by Fingerprint (Sender + Subject + Date)
                    if (!$already_exists) {
                        $check = $emailDb->prepare("SELECT id FROM module_emails WHERE sender_email = ? AND subject = ? AND created_at = ?");
                        $check->execute([$sender, $subject, $db_date]);
                        if ($check->fetch()) $already_exists = true;
                    }

                    if (!$already_exists) {
                        list(, $body) = email_build_body_from_imap_message($mbox, $m);
                        if ($body === '') {
                            $body = imap_body($mbox, $m);
                        }

                        $status = email_is_sender_blocked($sender, $emailDb) ? 'spam' : 'unread';
                        $ins = $emailDb->prepare("INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id) VALUES (0, ?, ?, ?, ?, 'inbound', ?, ?, ?)");
                        $ins->execute([$sender, $imap_user, $subject, $body, $status, $db_date, $msg_id]);
                        $new_id = $emailDb->lastInsertId();
                        if ($status !== 'spam') {
                            email_imap_download_attachments($mbox, $m, $new_id, $emailDb);
                        }
                        $count++;
                    }
                }
            }
            imap_close($mbox);
        }
    }

    // Also pull from remote cPanel mail bridges (Ultimate / Roadmaster).
    $bridgeResult = email_sync_remote_bridges($emailDb, 50, 0);
    $bridgeNew = (int) ($bridgeResult['new_count'] ?? 0);
    $bridgeErrors = $bridgeResult['errors'] ?? array();
    $count += $bridgeNew;

    if ($count === 0 && $imapError && empty($bridgeResult['bridges']) && empty($bridgeErrors)) {
        echo json_encode(['status' => 'error', 'message' => $imapError ?: 'No IMAP settings and no remote bridges configured.']);
        exit;
    }

    $msg = "Successfully synced $count emails";
    if ($bridgeNew > 0) {
        $msg .= " (including $bridgeNew from remote bridges)";
    }
    if ($imapError) {
        $msg .= '. Local IMAP warning: ' . $imapError;
    }
    if (!empty($bridgeErrors)) {
        $msg .= '. Bridge warnings: ' . implode('; ', array_map(function ($k, $v) {
            return $k . ': ' . $v;
        }, array_keys($bridgeErrors), array_values($bridgeErrors)));
    }
    echo json_encode(['status' => 'success', 'message' => $msg, 'new_count' => $count, 'bridge_new' => $bridgeNew, 'bridge_errors' => $bridgeErrors]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Critical Error: ' . $e->getMessage()]);
}

/**
 * Robust structure parser
 */
function parse_full_structure($mbox, $msg_no, $structure = false, $part_number = false) {
    if (!$structure) $structure = imap_fetchstructure($mbox, $msg_no);
    $data = ['html' => '', 'text' => ''];
    if ($structure) {
        if ($structure->type == 0) { // TEXT
            $content = imap_fetchbody($mbox, $msg_no, $part_number ?: "1");
            if ($structure->encoding == 3) $content = base64_decode($content);
            else if ($structure->encoding == 4) $content = quoted_printable_decode($content);
            
            $primary = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");
            $mime = $primary[(int)$structure->type] . '/' . ($structure->subtype ?? 'PLAIN');
            
            if ($mime == "TEXT/HTML") $data['html'] .= $content;
            else $data['text'] .= $content;
        }
        if ($structure->type == 1 && isset($structure->parts)) { // MULTIPART
            foreach ($structure->parts as $index => $sub) {
                $prefix = $part_number ? $part_number . "." : "";
                $sub_data = parse_full_structure($mbox, $msg_no, $sub, $prefix . ($index + 1));
                $data['html'] .= $sub_data['html'];
                $data['text'] .= $sub_data['text'];
            }
        }
    }
    return $data;
}
