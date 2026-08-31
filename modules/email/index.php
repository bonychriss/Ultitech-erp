<?php
/**
 * Email inbox — React desk shell (stock-style layout).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/includes/email_bootstrap.php';
require_once __DIR__ . '/includes/email_remote_bridges.php';
requireLogin();

$_SESSION['active_module'] = 'email';

$emailUpdateBadgePath = __DIR__ . '/includes/update-badge.php';
if (is_file($emailUpdateBadgePath)) {
    require_once $emailUpdateBadgePath;
    if (function_exists('email_module_mark_update_visited')) {
        email_module_mark_update_visited();
    }
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$emailDb = email_module_pdo();
if ($emailDb instanceof PDO) {
    ensure_email_module_schema($emailDb);
}

$userSettings = null;
if ($emailDb instanceof PDO) {
    try {
        $stmt = $emailDb->prepare('SELECT imap_host, imap_user FROM module_email_user_settings WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $userSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }
}

$mailConfigured = function_exists('email_source_is_configured')
    ? email_source_is_configured(is_array($userSettings) ? $userSettings : null)
    : false;

$companyName = (string) ($_SESSION['company_name'] ?? 'Mail');
$companySlug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
if ($companySlug === '' && function_exists('getRequestedCompanySlug')) {
    $companySlug = strtolower(trim((string) getRequestedCompanySlug()));
}
if ($companySlug === '' && function_exists('email_resolve_preferred_bridge')) {
    $companySlug = email_resolve_preferred_bridge();
}
$companyLogo = function_exists('getCompanyLogoUrl') ? trim((string) getCompanyLogoUrl()) : '';
$companyMailbox = '';
if ($companySlug === 'roadmaster' || stripos($companyName, 'roadmaster') !== false) {
    $companyMailbox = 'sales@roadmasterspares.com';
    if ($companySlug === '') {
        $companySlug = 'roadmaster';
    }
} elseif ($companySlug === 'ultimate' || stripos($companyName, 'ultimate') !== false) {
    $companyMailbox = 'sales@ultimate.co.tz';
    if ($companySlug === '') {
        $companySlug = 'ultimate';
    }
}
$allowedFolders = ['inbox', 'starred', 'sent', 'drafts', 'archive', 'spam', 'trash'];
if (!isset($_GET['folder'])) {
    $redir = (function_exists('company_url')
        ? rtrim(company_url('modules/email/index.php'), '/')
        : (function_exists('app_url') ? rtrim(app_url('/modules/email/index.php'), '/') : '/modules/email/index.php'))
        . '?module=email&folder=inbox';
    header('Location: ' . $redir);
    exit;
}
$folder = strtolower(trim((string) ($_GET['folder'] ?? 'inbox')));
if (!in_array($folder, $allowedFolders, true)) {
    $folder = 'inbox';
}

$emails = [];
$counts = [
    'unread' => 0,
    'drafts' => 0,
    'spam' => 0,
    'starred' => 0,
    'inbox' => 0,
];

if ($emailDb instanceof PDO && $mailConfigured) {
    try {
        if (function_exists('email_share_company_mailbox_rows')) {
            email_share_company_mailbox_rows($emailDb);
        }
        // Serve cached DB rows for the requested folder (remote sync is background-only).
        $folderWhere = "e.direction = 'inbound' AND e.status NOT IN ('trash', 'archived', 'spam')";
        switch ($folder) {
            case 'sent':
                $folderWhere = "e.direction = 'outbound' AND e.status != 'trash'";
                break;
            case 'drafts':
                $folderWhere = "e.status = 'draft'";
                break;
            case 'trash':
                $folderWhere = "e.status = 'trash'";
                break;
            case 'archive':
                $folderWhere = "e.status = 'archived'";
                break;
            case 'spam':
                $folderWhere = "e.status = 'spam'";
                break;
            case 'starred':
                $folderWhere = "e.is_starred = 1 AND e.status != 'trash'";
                break;
            case 'inbox':
            default:
                $folderWhere = "e.direction = 'inbound' AND e.status NOT IN ('trash', 'archived', 'spam')";
                break;
        }

        $mailboxFilter = function_exists('email_company_mailbox_where_sql')
            ? email_company_mailbox_where_sql('e')
            : ['sql' => '1=1', 'params' => []];

        $stmt = $emailDb->prepare("
            SELECT e.id, e.sender_email, e.recipient_email, e.subject, LEFT(e.body, 400) AS body,
                   e.direction, e.status, e.is_starred, e.created_at,
                   (SELECT COUNT(*) FROM module_email_attachments a WHERE a.email_id = e.id) AS attachment_count
            FROM module_emails e
            WHERE (e.user_id = ? OR e.user_id = 0)
              AND {$folderWhere}
              AND {$mailboxFilter['sql']}
            ORDER BY e.created_at DESC
            LIMIT 100
        ");
        $stmt->execute(array_merge([$user_id], $mailboxFilter['params']));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sender = (string) ($row['sender_email'] ?? '');
            $subject = (string) ($row['subject'] ?? '(no subject)');
            if (function_exists('email_decode_mime_header')) {
                $sender = email_decode_mime_header($sender);
                $subject = email_decode_mime_header($subject);
            }
            $snippet = function_exists('email_inbox_preview_text')
                ? email_inbox_preview_text((string) ($row['body'] ?? ''), 140)
                : (function_exists('mb_substr')
                    ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['body'] ?? '')))), 0, 140)
                    : substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['body'] ?? '')))), 0, 140));
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

        $emailIds = array_values(array_map(static fn($e) => (int) $e['id'], array_filter($emails, static fn($e) => !empty($e['has_attachments']))));
        if ($emailIds !== [] && ($emailDb instanceof PDO)) {
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

        $cst = $emailDb->prepare("SELECT
            SUM(CASE WHEN direction = 'inbound' AND status = 'unread' THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
            SUM(CASE WHEN status = 'spam' THEN 1 ELSE 0 END) AS spam,
            SUM(CASE WHEN is_starred = 1 AND status != 'trash' THEN 1 ELSE 0 END) AS starred,
            SUM(CASE WHEN direction = 'inbound' AND status NOT IN ('trash','archived','spam') THEN 1 ELSE 0 END) AS inbox
            FROM module_emails e WHERE (e.user_id = ? OR e.user_id = 0) AND {$mailboxFilter['sql']}");
        $cst->execute(array_merge([$user_id], $mailboxFilter['params']));
        $c = $cst->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = [
            'unread' => (int) ($c['unread'] ?? 0),
            'drafts' => (int) ($c['drafts'] ?? 0),
            'spam' => (int) ($c['spam'] ?? 0),
            'starred' => (int) ($c['starred'] ?? 0),
            'inbox' => (int) ($c['inbox'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('email index bootstrap: ' . $e->getMessage());
    }
}

$modBase = function_exists('company_url')
    ? rtrim(company_url('modules/email'), '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/modules/email'), '/') . '/' : '/modules/email/');
// Same-origin relative API base (avoids company_url host/scheme mismatches that return HTML login pages)
$apiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/modules/email/index.php')), '/') . '/';
$assetBase = function_exists('app_url')
    ? rtrim(app_url('/modules/email'), '/') . '/'
    : $modBase;

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/email-ui/dist/assets/email-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/email-ui/dist/assets/email-ui.css') ?: 0),
    1
);

$page_title = 'Mail';
$employeeHeaderTitle = null;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';

$payload = [
    'page' => 'inbox',
    'data' => [
        'company_name' => $companyName,
        'company_slug' => $companySlug,
        'company_logo' => $companyLogo,
        'company_mailbox' => $companyMailbox,
        'folder' => $folder,
        'configured' => $mailConfigured,
        'emails' => $emails,
        'counts' => $counts,
        'links' => [
            'inbox_api' => $apiBase . 'api/inbox.php',
            'message_api' => $apiBase . 'api/message.php',
            'sync_api' => $apiBase . 'sync.php',
            'toggle_star_api' => $apiBase . 'api/toggle_star.php',
            'update_status_api' => $apiBase . 'api/update_status.php',
            'reply_api' => $apiBase . 'api/send_reply.php',
            'attachment_api' => $apiBase . 'api/download_attachment.php?id=',
            'compose' => $modBase . 'compose.php',
            'compose_reply' => $modBase . 'compose.php?reply_to=',
            'compose_forward' => $modBase . 'compose.php?forward=',
            'settings' => function_exists('company_url')
                ? company_url('admin/email-settings.php?module=settings#bridges')
                : app_url('/admin/email-settings.php#bridges'),
            'account' => function_exists('app_url') ? app_url('/employee/account.php') : '/employee/account.php',
        ],
    ],
];

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$bootstrapJson = json_encode($payload, $jsonFlags);
if ($bootstrapJson === false) {
    $bootstrapJson = '{"page":"inbox","data":{"configured":false,"emails":[],"counts":{},"links":{}}}';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | <?= htmlspecialchars($companyName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : '/assets/css/style.css') ?>?v=<?= time() ?>" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>
    <style>
        body.page-email-desk.dashboard .layout-main-wrapper { align-items: stretch; }
        body.page-email-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        body.page-email-desk,
        body.page-email-desk.dashboard,
        body.page-email-desk .layout-main-wrapper,
        body.page-email-desk .layout-main-wrapper > .flex-grow-1 {
            background: #fff !important;
        }
        body.page-email-desk .employee-header.employee-header--products-desk {
            background: #fff !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 1.25rem !important;
            margin-bottom: 0;
            height: auto !important;
            min-height: 0;
            position: sticky !important;
            top: 0 !important;
            z-index: 1020 !important;
        }
        body.page-email-desk .employee-header--products-desk::after { display: none !important; }
        body.page-email-desk .employee-header--products-desk .header-content {
            display: flex !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            padding: 0.65rem 0 !important;
            min-height: 0;
            width: 100%;
            background: transparent !important;
            gap: 0.5rem;
        }
        body.page-email-desk .employee-header--products-desk .header-right.header-actions-tray {
            margin-left: auto !important;
        }
        main.main-content.email-desk-react-root {
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            padding: 0 !important;
            margin: 0 !important;
            overflow: hidden !important;
            box-sizing: border-box;
            background: #fff !important;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        main.main-content.email-desk-react-root #root {
            width: 100%;
            max-width: none;
            margin: 0;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
        }
        main.main-content.email-desk-react-root #root > .email-desk {
            flex: 1 1 auto;
            width: 100%;
        }
        @media (max-width: 767.98px) {
            body.page-email-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
            main.main-content.email-desk-react-root { padding: 0 !important; }
        }
        html[data-theme="dark"] body.page-email-desk,
        html[data-theme="dark"] body.page-email-desk.dashboard,
        html[data-theme="dark"] body.page-email-desk .layout-main-wrapper,
        html[data-theme="dark"] body.page-email-desk .layout-main-wrapper > .flex-grow-1,
        html[data-theme="dark"] body.page-email-desk main.main-content.email-desk-react-root {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.page-email-desk .employee-header.employee-header--products-desk {
            background: #0f172a !important;
        }
        /* Force pill Reply — email HTML / Bootstrap can reset button radius */
        body.page-email-desk button.email-reply-pill,
        body.page-email-desk .email-reply-pill {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.4rem !important;
            min-height: 2.35rem !important;
            padding: 0.5rem 1.4rem !important;
            border: 0 !important;
            border-radius: 9999px !important;
            background: #7c3aed !important;
            color: #fff !important;
            box-shadow: none !important;
            appearance: none !important;
            -webkit-appearance: none !important;
        }
        body.page-email-desk button.email-reply-pill:hover,
        body.page-email-desk .email-reply-pill:hover {
            background: #6d28d9 !important;
            border-radius: 9999px !important;
            color: #fff !important;
        }
        body.page-email-desk a.email-forward-pill,
        body.page-email-desk .email-forward-pill {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.4rem !important;
            min-height: 2.35rem !important;
            padding: 0.5rem 1.25rem !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 9999px !important;
            background: #fff !important;
            color: #0f172a !important;
            text-decoration: none !important;
            box-shadow: none !important;
        }
        body.page-email-desk a.email-forward-pill:hover,
        body.page-email-desk .email-forward-pill:hover {
            background: #f1f5f9 !important;
            border-color: #d8b4fe !important;
            color: #7c3aed !important;
            text-decoration: none !important;
        }
    </style>
</head>
<body class="dashboard page-email-desk">
<?php require_once __DIR__ . '/../../includes/header_employee.php'; ?>

<main class="main-content email-desk-react-root" role="main">
    <script>
        window.__EMAIL_PAGE__ = <?= $bootstrapJson ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>email-ui/dist/assets/email-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root">
        <div class="email-desk" role="status" aria-live="polite" aria-busy="true" style="padding-top:1rem">
            <div style="width:min(12rem,45%);height:2.1rem;border-radius:10px;background:#e2e8f0"></div>
            <div style="width:min(28rem,80%);height:0.9rem;border-radius:10px;background:#e2e8f0;margin-top:0.75rem"></div>
        </div>
    </div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>email-ui/dist/assets/email-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>

<?php
if (file_exists(__DIR__ . '/../../includes/footer.php')) {
    require_once __DIR__ . '/../../includes/footer.php';
}
?>
</body>
</html>
