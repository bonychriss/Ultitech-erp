<?php
/**
 * JSON inbox list + counts for email-ui React desk.
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    require_once __DIR__ . '/../includes/email_remote_bridges.php';
    requireLogin();

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $folder = trim((string) ($_GET['folder'] ?? 'inbox'));
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));

    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Email database unavailable.']);
        exit;
    }
    ensure_email_module_schema($emailDb);

    $userSettings = null;
    try {
        $st = $emailDb->prepare('SELECT imap_host, imap_user FROM module_email_user_settings WHERE user_id = ?');
        $st->execute([$user_id]);
        $userSettings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }

    $mailConfigured = email_source_is_configured(is_array($userSettings) ? $userSettings : null);
    if (function_exists('email_share_company_mailbox_rows')) {
        email_share_company_mailbox_rows($emailDb);
    }

    $where = ['(e.user_id = ? OR e.user_id = 0)'];
    $params = [$user_id];

    $mailboxFilter = function_exists('email_company_mailbox_where_sql')
        ? email_company_mailbox_where_sql('e')
        : ['sql' => '1=1', 'params' => []];
    $where[] = $mailboxFilter['sql'];
    foreach ($mailboxFilter['params'] as $p) {
        $params[] = $p;
    }

    switch ($folder) {
        case 'sent':
            $where[] = "e.direction = 'outbound' AND e.status != 'trash'";
            break;
        case 'drafts':
            $where[] = "e.status = 'draft'";
            break;
        case 'trash':
            $where[] = "e.status = 'trash'";
            break;
        case 'archive':
            $where[] = "e.status = 'archived'";
            break;
        case 'spam':
            $where[] = "e.status = 'spam'";
            break;
        case 'starred':
            $where[] = 'e.is_starred = 1 AND e.status != \'trash\'';
            break;
        case 'inbox':
        default:
            $where[] = "e.direction = 'inbound' AND e.status != 'trash' AND e.status != 'archived' AND e.status != 'spam'";
            break;
    }

    if ($q !== '') {
        $where[] = '(e.sender_email LIKE ? OR e.recipient_email LIKE ? OR e.subject LIKE ? OR e.body LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT e.id, e.user_id, e.sender_email, e.recipient_email, e.subject,
                   LEFT(e.body, 1200) AS body, e.direction, e.status, e.is_starred, e.created_at, e.message_id,
                   (SELECT COUNT(*) FROM module_email_attachments a WHERE a.email_id = e.id) AS attachment_count
            FROM module_emails e
            WHERE $whereSql
            ORDER BY e.created_at DESC
            LIMIT $limit";
    $stmt = $emailDb->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $emails = [];
    foreach ($rows as $row) {
        $sender = (string) ($row['sender_email'] ?? '');
        $subject = (string) ($row['subject'] ?? '(no subject)');
        if (function_exists('email_decode_mime_header')) {
            $sender = email_decode_mime_header($sender);
            $subject = email_decode_mime_header($subject);
        }
        $snippet = function_exists('email_inbox_preview_text')
            ? email_inbox_preview_text((string) ($row['body'] ?? ''), 140)
            : mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['body'] ?? '')))), 0, 140);
        $emails[] = [
            'id' => (int) $row['id'],
            'sender' => $sender,
            'recipient' => (string) ($row['recipient_email'] ?? ''),
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'snippet' => $snippet,
            'direction' => (string) ($row['direction'] ?? 'inbound'),
            'status' => (string) ($row['status'] ?? 'read'),
            'is_starred' => !empty($row['is_starred']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'unread' => (($row['status'] ?? '') === 'unread'),
            'has_attachments' => ((int) ($row['attachment_count'] ?? 0)) > 0,
            'attachments' => [],
        ];
    }

    $emailIds = array_values(array_map(static fn($e) => (int) $e['id'], array_filter($emails, static fn($e) => $e['has_attachments'])));
    if ($emailIds !== []) {
        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
        try {
            $ast = $emailDb->prepare(
                "SELECT email_id, id, file_name, file_type
                 FROM module_email_attachments
                 WHERE email_id IN ($placeholders)
                 ORDER BY id ASC"
            );
            $ast->execute($emailIds);
            $byEmail = [];
            foreach ($ast->fetchAll(PDO::FETCH_ASSOC) ?: [] as $att) {
                $eid = (int) ($att['email_id'] ?? 0);
                if (!isset($byEmail[$eid])) {
                    $byEmail[$eid] = [];
                }
                if (count($byEmail[$eid]) >= 3) {
                    continue;
                }
                $byEmail[$eid][] = [
                    'id' => (int) ($att['id'] ?? 0),
                    'file_name' => (string) ($att['file_name'] ?? 'file'),
                    'file_type' => (string) ($att['file_type'] ?? ''),
                ];
            }
            foreach ($emails as &$email) {
                $email['attachments'] = $byEmail[(int) $email['id']] ?? [];
            }
            unset($email);
        } catch (Throwable $e) {
        }
    }

    $countSql = 'SELECT
        SUM(CASE WHEN direction = \'inbound\' AND status = \'unread\' THEN 1 ELSE 0 END) AS unread,
        SUM(CASE WHEN status = \'draft\' THEN 1 ELSE 0 END) AS drafts,
        SUM(CASE WHEN status = \'spam\' THEN 1 ELSE 0 END) AS spam,
        SUM(CASE WHEN is_starred = 1 AND status != \'trash\' THEN 1 ELSE 0 END) AS starred,
        SUM(CASE WHEN direction = \'inbound\' AND status NOT IN (\'trash\',\'archived\',\'spam\') THEN 1 ELSE 0 END) AS inbox
        FROM module_emails e WHERE (e.user_id = ? OR e.user_id = 0) AND ' . $mailboxFilter['sql'];
    $cst = $emailDb->prepare($countSql);
    $countParams = array_merge([$user_id], $mailboxFilter['params']);
    $cst->execute($countParams);
    $counts = $cst->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 'success',
        'configured' => $mailConfigured,
        'folder' => $folder,
        'emails' => $emails,
        'counts' => [
            'unread' => (int) ($counts['unread'] ?? 0),
            'drafts' => (int) ($counts['drafts'] ?? 0),
            'spam' => (int) ($counts['spam'] ?? 0),
            'starred' => (int) ($counts['starred'] ?? 0),
            'inbox' => (int) ($counts['inbox'] ?? 0),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
