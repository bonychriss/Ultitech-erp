/**
 * Optimized Email Sync API (GET based)
 */
header('Content-Type: application/json');
error_reporting(0); // Suppress any warnings that might break JSON

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';

    // Safe check for login
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];

    // 1. Fetch IMAP settings
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_imap_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (empty($settings['email_imap_host']) || empty($settings['email_imap_user'])) {
        echo json_encode(['status' => 'error', 'message' => 'IMAP settings not configured.']);
        exit;
    }

    // 2. Connect to IMAP
    $host = $settings['email_imap_host'];
    $user = $settings['email_imap_user'];
    $pass = $settings['email_imap_pass'];
    $port = $settings['email_imap_port'] ?: '993';
    $ssl  = $settings['email_imap_ssl'] ?: 'ssl';

    $mbox_path = "{" . $host . ":" . $port . "/imap/" . $ssl . "/novalidate-cert}INBOX";
    $mbox = @imap_open($mbox_path, $user, $pass);

    if (!$mbox) {
        echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . imap_last_error()]);
        exit;
    }

    // 3. Sync Logic
    ini_set('memory_limit', '256M');
    set_time_limit(60);

    $emails = imap_search($mbox, 'ALL');
    $count = 0;

    if ($emails) {
        rsort($emails);
        // Scan up to 100 most recent emails (balanced for speed)
        $emails = array_slice($emails, 0, 100);
        $overviews = imap_fetch_overview($mbox, implode(',', $emails), 0);
        
        foreach ($overviews as $ov) {
            if ($count >= 10) break; // Batch of 10
            
            $subject = imap_utf8($ov->subject);
            $sender = $ov->from;
            $msg_id = $ov->message_id ?? null;
            $db_date = date('Y-m-d H:i:s', $ov->udate);
            
            preg_match('/[a-z0-9_\-\+]+@[a-z0-9\-]+\.([a-z]{2,3})(?:\.[a-z]{2})?/i', $sender, $matches);
            $sender_email = $matches[0] ?? $sender;

            // Deduplicate
            if ($msg_id) {
                $check = $pdo->prepare("SELECT id FROM module_emails WHERE message_id = ?");
                $check->execute([$msg_id]);
            } else {
                $check = $pdo->prepare("SELECT id FROM module_emails WHERE sender_email = ? AND created_at = ? AND subject = ?");
                $check->execute([$sender_email, $db_date, $subject]);
            }

            if (!$check->fetch()) {
                list(, $body) = email_build_body_from_imap_message($mbox, $ov->msgno);
                if ($body === '') {
                    $mail_data = parse_full_structure($mbox, $ov->msgno);
                    $body = !empty($mail_data['html']) ? $mail_data['html'] : $mail_data['text'];
                }

                $ins = $pdo->prepare("
                    INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id)
                    VALUES (0, ?, ?, ?, ?, 'inbound', 'unread', ?, ?)
                ");
                $ins->execute([$sender_email, $user, $subject, $body, $db_date, $msg_id]);
                $count++;
            }
        }
    }

    imap_close($mbox);
    echo json_encode(['status' => 'success', 'message' => "Successfully synced $count messages.", 'new_count' => $count]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'API Error: ' . $e->getMessage()]);
}

/**
 * Robust but fast structure parser
 */
function parse_full_structure($mbox, $msg_id, $structure = false, $part_number = false) {
    if (!$structure) $structure = imap_fetchstructure($mbox, $msg_id);
    $data = ['html' => '', 'text' => ''];

    if ($structure) {
        $primary = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");
        $mime = $primary[(int)$structure->type] . '/' . ($structure->subtype ?? 'PLAIN');

        if ($structure->type == 0) { // TEXT
            $content = imap_fetchbody($mbox, $msg_id, $part_number ?: "1");
            if ($structure->encoding == 3) $content = base64_decode($content);
            else if ($structure->encoding == 4) $content = quoted_printable_decode($content);
            
            if ($mime == "TEXT/HTML") $data['html'] .= $content;
            else $data['text'] .= $content;
        }

        if ($structure->type == 1 && isset($structure->parts)) { // MULTIPART
            foreach ($structure->parts as $index => $sub) {
                $prefix = $part_number ? $part_number . "." : "";
                $sub_data = parse_full_structure($mbox, $msg_id, $sub, $prefix . ($index + 1));
                $data['html'] .= $sub_data['html'];
                $data['text'] .= $sub_data['text'];
            }
        }
    }
    return $data;
}

