<?php
// modules/email/compose.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/includes/email_bootstrap.php';
requireLogin();

$_SESSION['active_module'] = 'email';

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$emailDb = function_exists('email_module_pdo') ? email_module_pdo() : null;
if (!($emailDb instanceof PDO)) {
    $emailDb = $pdo;
}

$customers = [];
try {
    $customers = $pdo->query("SELECT id, company_name, email FROM customers ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customers = [];
}

// Autocomplete suggestions: customers + recent mail addresses
$emailSuggestions = [];
$seenEmails = [];
foreach ($customers as $c) {
    $em = strtolower(trim((string) ($c['email'] ?? '')));
    if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL) || isset($seenEmails[$em])) {
        continue;
    }
    $seenEmails[$em] = true;
    $emailSuggestions[] = [
        'email' => trim((string) $c['email']),
        'name' => trim((string) ($c['company_name'] ?? '')),
        'customer_id' => (string) ($c['id'] ?? ''),
        'source' => 'customer',
    ];
}
if ($emailDb instanceof PDO) {
    try {
        $st = $emailDb->prepare(
            "SELECT sender_email, recipient_email
             FROM module_emails
             WHERE (user_id = ? OR user_id = 0)
             ORDER BY created_at DESC
             LIMIT 200"
        );
        $st->execute([$user_id]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach (['sender_email', 'recipient_email'] as $col) {
                $raw = (string) ($row[$col] ?? '');
                if ($raw === '') {
                    continue;
                }
                if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m)) {
                    foreach ($m[0] as $emRaw) {
                        $em = strtolower(trim($emRaw));
                        if ($em === '' || isset($seenEmails[$em])) {
                            continue;
                        }
                        $seenEmails[$em] = true;
                        $emailSuggestions[] = [
                            'email' => $emRaw,
                            'name' => '',
                            'customer_id' => '',
                            'source' => 'recent',
                        ];
                        if (count($emailSuggestions) >= 120) {
                            break 3;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }
}

$reply_to = null;
$forward_of = null;
$subject = '';
$recipient = '';
$customer_id = '';
$editorHtml = '';

function email_compose_load_message(PDO $db, int $id, int $userId)
{
    $stmt = $db->prepare('SELECT * FROM module_emails WHERE id = ? AND (user_id = ? OR user_id = 0) LIMIT 1');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function email_compose_plain_quote(array $msg): string
{
    $from = htmlspecialchars((string) ($msg['sender_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $date = htmlspecialchars((string) ($msg['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subj = htmlspecialchars((string) ($msg['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
    $body = (string) ($msg['body'] ?? '');
    if (strip_tags($body) === $body) {
        $body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    }
    return '<p><br></p><p>---------- Forwarded message ----------</p>'
        . '<p><strong>From:</strong> ' . $from . '<br>'
        . '<strong>Date:</strong> ' . $date . '<br>'
        . '<strong>Subject:</strong> ' . $subj . '</p>'
        . '<blockquote>' . $body . '</blockquote>';
}

if (isset($_GET['reply_to']) && $emailDb instanceof PDO) {
    $reply_to = email_compose_load_message($emailDb, (int) $_GET['reply_to'], $user_id);
    if ($reply_to) {
        $rawSubject = (string) ($reply_to['subject'] ?? '');
        $subject = preg_match('/^\s*re\s*:/i', $rawSubject) ? $rawSubject : ('Re: ' . $rawSubject);
        $recipient = (string) ($reply_to['sender_email'] ?? '');
        $customer_id = $reply_to['customer_id'] ?? '';
    }
}

if (isset($_GET['forward']) && $emailDb instanceof PDO) {
    $forward_of = email_compose_load_message($emailDb, (int) $_GET['forward'], $user_id);
    if ($forward_of) {
        $rawSubject = (string) ($forward_of['subject'] ?? '');
        $subject = preg_match('/^\s*fwd?\s*:/i', $rawSubject) ? $rawSubject : ('Fwd: ' . $rawSubject);
        $recipient = '';
        $customer_id = $forward_of['customer_id'] ?? '';
        $editorHtml = email_compose_plain_quote($forward_of);
    }
}

$page_title = $forward_of ? 'Forward Email' : ($reply_to ? 'Reply' : 'Compose Email');
$heading = $forward_of ? 'Forward' : ($reply_to ? 'Reply' : 'New Message');

if (!$embed) {
    include __DIR__ . '/includes/header.php';
} else {
    ?><!DOCTYPE html>
<html lang="en" style="height:100%">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="email-compose-embed" style="height:100%">
<?php
}
?>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<style>
    body { font-family: 'Outfit', sans-serif; background-color: #f8fafc; margin: 0; }
    body.email-compose-embed {
        background: #fff;
        overflow: hidden;
        height: 100%;
    }
    html:has(body.email-compose-embed) { height: 100%; }
    .compose-card { border-radius: 24px; border: 1px solid #f1f5f9; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.05); }
    body.email-compose-embed .compose-card {
        border: 0;
        border-radius: 0;
        box-shadow: none;
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    body.email-compose-embed .compose-shell {
        max-width: none;
        margin: 0;
        padding: 0.65rem 0.9rem 0.75rem;
        height: 100%;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    body.email-compose-embed .compose-shell > div {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    body.email-compose-embed .compose-card-inner { padding: 0; }
    body.email-compose-embed #composeForm {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }
    body.email-compose-embed .compose-grid {
        gap: 0.55rem 0.75rem;
        flex-shrink: 0;
    }
    body.email-compose-embed .compose-field { margin-bottom: 0; flex-shrink: 0; }
    body.email-compose-embed .compose-field--message {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        margin-bottom: 0;
    }
    body.email-compose-embed .compose-editor-wrap {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }
    body.email-compose-embed .compose-editor-wrap .ql-toolbar.ql-snow {
        border: 0;
        border-bottom: 1px solid #f1f5f9;
        border-radius: 0;
        padding: 6px 8px;
        flex-shrink: 0;
    }
    body.email-compose-embed .compose-editor-wrap .ql-container.ql-snow {
        border: 0;
        border-radius: 0;
        flex: 1 1 auto;
        min-height: 0;
        height: auto !important;
        overflow: auto;
        font-size: 14px;
    }
    body.email-compose-embed .form-input-custom {
        padding: 0.5rem 0.7rem;
        border-radius: 8px;
        font-size: 0.875rem;
    }
    body.email-compose-embed .compose-label {
        font-size: 0.72rem;
        margin-bottom: 0.2rem;
        letter-spacing: 0.01em;
    }
    body.email-compose-embed .compose-actions {
        padding-top: 0.55rem;
        margin-top: 0;
        flex-shrink: 0;
    }
    body.email-compose-embed .compose-send {
        padding: 0.5rem 1.1rem;
        font-size: 0.875rem;
    }
    body.email-compose-embed .compose-ghost {
        padding: 0.4rem 0.7rem;
        font-size: 0.875rem;
    }
    .ql-toolbar.ql-snow { border-top-left-radius: 12px; border-top-right-radius: 12px; border-color: #f1f5f9; background: #f8fafc; padding: 12px; }
    .ql-container.ql-snow { border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; border-color: #f1f5f9; font-family: 'Outfit', sans-serif; font-size: 15px; }
    .form-input-custom {
        width: 100%;
        padding: 12px 16px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #fff;
        font-size: 14px;
        outline: none;
        box-sizing: border-box;
        transition: border-color 0.2s;
    }
    .form-input-custom:focus { border-color: #7c3aed; }
    .compose-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .compose-field { margin-bottom: 1rem; }
    .compose-label { display: block; font-size: 0.82rem; font-weight: 600; color: #64748b; margin-bottom: 0.4rem; }
    .compose-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding-top: 1rem;
        border-top: 1px solid #f1f5f9;
        margin-top: 0.5rem;
    }
    .compose-actions-left {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        min-width: 0;
    }
    .compose-attach {
        flex-shrink: 0;
    }
    .compose-attach-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        margin-top: 0.35rem;
    }
    .compose-attach-list[hidden] { display: none !important; }
    .compose-attach-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        max-width: 100%;
        padding: 0.3rem 0.55rem;
        border: 1px solid #e2e8f0;
        border-radius: 9999px;
        background: #f8fafc;
        font-size: 0.75rem;
        color: #334155;
    }
    .compose-attach-chip span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 12rem;
    }
    .compose-attach-chip button {
        border: 0;
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
        padding: 0;
        line-height: 1;
        font-size: 0.95rem;
    }
    .compose-attach-chip button:hover { color: #dc2626; }
    .compose-attach-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: 0;
        background: transparent;
        color: #64748b;
        font: inherit;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.4rem 0.7rem;
        border-radius: 9999px;
        cursor: pointer;
    }
    .compose-attach-btn:hover {
        background: #f1f5f9;
        color: #7c3aed;
    }
    body.email-compose-embed .compose-attach-list {
        max-height: 4.5rem;
        overflow: auto;
    }
    .compose-send {
        background: #7c3aed;
        color: #fff;
        border: 0;
        border-radius: 9999px;
        font-weight: 600;
        padding: 0.65rem 1.35rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .compose-send:hover { background: #6d28d9; }
    .compose-send:disabled { opacity: 0.6; cursor: not-allowed; }
    .compose-ghost {
        background: transparent;
        border: 0;
        color: #64748b;
        font-weight: 600;
        padding: 0.55rem 0.9rem;
        border-radius: 9999px;
        cursor: pointer;
    }
    .compose-ghost:hover { background: #f1f5f9; color: #0f172a; }
    .compose-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .compose-title { margin: 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; }
    .compose-suggest-wrap { position: relative; }
    .compose-suggest-list {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 4px);
        z-index: 40;
        max-height: 14rem;
        overflow: auto;
        margin: 0;
        padding: 0.35rem;
        list-style: none;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
    }
    .compose-suggest-list.is-open { display: block; }
    .compose-suggest-item {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
        width: 100%;
        padding: 0.5rem 0.65rem;
        border: 0;
        border-radius: 8px;
        background: transparent;
        text-align: left;
        cursor: pointer;
        font: inherit;
        color: #0f172a;
    }
    .compose-suggest-item:hover,
    .compose-suggest-item.is-active {
        background: #f3e8ff;
    }
    .compose-suggest-email {
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .compose-suggest-meta {
        font-size: 0.72rem;
        color: #64748b;
        line-height: 1.2;
    }
    @media (max-width: 720px) {
        .compose-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="<?= $embed ? 'compose-shell' : 'main-content min-h-screen pb-12 pt-8' ?>">
    <div class="<?= $embed ? '' : 'max-w-4xl mx-auto px-4' ?>">
        <?php if (!$embed): ?>
        <div class="compose-top">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <a href="index.php" class="compose-ghost" style="text-decoration:none;padding:0.4rem;">
                    <i class="bi bi-arrow-left" style="font-size:1.15rem;"></i>
                </a>
                <h1 class="compose-title"><?= htmlspecialchars($heading) ?></h1>
            </div>
            <button type="button" onclick="closeCompose()" class="compose-ghost">Discard</button>
        </div>
        <?php endif; ?>

        <div class="compose-card <?= $embed ? 'compose-card-inner' : 'p-8' ?>" style="<?= $embed ? '' : 'background:#fff;padding:2rem;' ?>">
            <form id="composeForm">
                <div class="compose-grid">
                    <div class="compose-field">
                        <label class="compose-label">Recipient</label>
                        <div class="compose-suggest-wrap">
                            <input type="email" name="recipient_email" id="recipientEmail" required
                                   class="form-input-custom"
                                   placeholder="customer@email.com"
                                   value="<?= htmlspecialchars($recipient) ?>"
                                   autocomplete="off"
                                   spellcheck="false"
                                   role="combobox"
                                   aria-autocomplete="list"
                                   aria-expanded="false"
                                   aria-controls="recipientSuggests">
                            <ul class="compose-suggest-list" id="recipientSuggests" role="listbox" hidden></ul>
                        </div>
                    </div>
                    <div class="compose-field">
                        <label class="compose-label">Link Customer</label>
                        <select name="customer_id" id="customerLink" class="form-input-custom">
                            <option value="">-- No Association --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"
                                        data-email="<?= htmlspecialchars((string) ($c['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        <?= (string) $customer_id === (string) $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($c['company_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="compose-field">
                    <label class="compose-label">Subject</label>
                    <input type="text" name="subject" required
                           class="form-input-custom"
                           placeholder="What is this regarding?" value="<?= htmlspecialchars($subject) ?>">
                </div>

                <div class="compose-field compose-field--message">
                    <label class="compose-label">Message</label>
                    <div class="compose-editor-wrap">
                        <div id="editor"<?= $embed ? '' : ' style="height: 350px;"' ?>></div>
                    </div>
                    <input type="hidden" name="body" id="bodyInput">
                </div>

                <div class="compose-attach" id="composeAttachBlock">
                    <input type="file" id="composeAttachments" name="attachments[]" multiple hidden
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar">
                    <div class="compose-attach-list" id="attachmentPreviewList" hidden></div>
                </div>

                <div class="compose-actions">
                    <div class="compose-actions-left">
                        <button type="button" class="compose-attach-btn" id="composeAttachBtn" title="Attach files">
                            <i class="bi bi-paperclip"></i>
                            <span>Attach</span>
                        </button>
                        <button type="button" onclick="closeCompose()" class="compose-ghost">Cancel</button>
                    </div>
                    <button type="submit" class="compose-send">
                        <i class="bi bi-send-fill"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
    var EMAIL_COMPOSE_EMBED = <?= $embed ? 'true' : 'false' ?>;
    var EMAIL_SUGGESTIONS = <?= json_encode($emailSuggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function notifyParent(type, payload) {
        if (!EMAIL_COMPOSE_EMBED) return;
        try {
            window.parent.postMessage(Object.assign({ source: 'email-compose', type: type }, payload || {}), '*');
        } catch (e) {}
    }

    function closeCompose() {
        if (EMAIL_COMPOSE_EMBED) {
            notifyParent('close');
            return;
        }
        window.location.href = 'index.php';
    }

    (function initRecipientSuggest() {
        var input = document.getElementById('recipientEmail');
        var list = document.getElementById('recipientSuggests');
        var customerSelect = document.getElementById('customerLink');
        if (!input || !list) return;

        var activeIndex = -1;
        var currentItems = [];

        function extractEmail(raw) {
            var s = String(raw || '').trim();
            var m = s.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i);
            return m ? m[0] : s;
        }

        function closeList() {
            list.classList.remove('is-open');
            list.hidden = true;
            list.innerHTML = '';
            activeIndex = -1;
            currentItems = [];
            input.setAttribute('aria-expanded', 'false');
        }

        function setCustomerById(id) {
            if (!customerSelect) return;
            var value = id ? String(id) : '';
            if (!value) return;
            for (var i = 0; i < customerSelect.options.length; i++) {
                if (String(customerSelect.options[i].value) === value) {
                    customerSelect.value = value;
                    return;
                }
            }
        }

        function setCustomerByEmail(email) {
            if (!customerSelect) return;
            var needle = String(email || '').toLowerCase();
            for (var i = 0; i < customerSelect.options.length; i++) {
                var opt = customerSelect.options[i];
                var em = String(opt.getAttribute('data-email') || '').toLowerCase();
                if (em && em === needle) {
                    customerSelect.value = opt.value;
                    return;
                }
            }
        }

        function applySuggestion(item) {
            if (!item) return;
            input.value = item.email || '';
            if (item.customer_id) {
                setCustomerById(item.customer_id);
            } else {
                setCustomerByEmail(item.email);
            }
            closeList();
            input.focus();
        }

        function render(items) {
            currentItems = items.slice(0, 8);
            activeIndex = currentItems.length ? 0 : -1;
            list.innerHTML = '';
            if (!currentItems.length) {
                closeList();
                return;
            }
            currentItems.forEach(function (item, idx) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'compose-suggest-item' + (idx === activeIndex ? ' is-active' : '');
                btn.innerHTML =
                    '<span class="compose-suggest-email"></span>' +
                    '<span class="compose-suggest-meta"></span>';
                btn.querySelector('.compose-suggest-email').textContent = item.email;
                var meta = item.name
                    ? item.name + (item.source === 'customer' ? ' · customer' : '')
                    : (item.source === 'recent' ? 'Recent contact' : '');
                btn.querySelector('.compose-suggest-meta').textContent = meta;
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    applySuggestion(item);
                });
                li.appendChild(btn);
                list.appendChild(li);
            });
            list.hidden = false;
            list.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
        }

        function filterSuggestions(q) {
            var query = String(q || '').trim().toLowerCase();
            if (query.length < 1) {
                closeList();
                return;
            }
            var scored = [];
            (EMAIL_SUGGESTIONS || []).forEach(function (item) {
                var email = String(item.email || '').toLowerCase();
                var name = String(item.name || '').toLowerCase();
                if (email.indexOf(query) === -1 && name.indexOf(query) === -1) return;
                var score = 0;
                if (email.indexOf(query) === 0) score += 40;
                if (name.indexOf(query) === 0) score += 30;
                if (email.indexOf(query) !== -1) score += 10;
                if (item.source === 'customer') score += 5;
                scored.push({ item: item, score: score });
            });
            scored.sort(function (a, b) { return b.score - a.score; });
            render(scored.map(function (x) { return x.item; }));
        }

        function moveActive(delta) {
            if (!currentItems.length) return;
            var buttons = list.querySelectorAll('.compose-suggest-item');
            if (!buttons.length) return;
            activeIndex = (activeIndex + delta + currentItems.length) % currentItems.length;
            buttons.forEach(function (btn, idx) {
                btn.classList.toggle('is-active', idx === activeIndex);
            });
            buttons[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('input', function () {
            filterSuggestions(input.value);
            setCustomerByEmail(extractEmail(input.value));
        });
        input.addEventListener('focus', function () {
            if (String(input.value || '').trim()) filterSuggestions(input.value);
        });
        input.addEventListener('blur', function () {
            window.setTimeout(closeList, 120);
        });
        input.addEventListener('keydown', function (e) {
            if (!list.classList.contains('is-open')) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveActive(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveActive(-1);
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                applySuggestion(currentItems[activeIndex]);
            } else if (e.key === 'Escape') {
                closeList();
            }
        });

        if (customerSelect) {
            customerSelect.addEventListener('change', function () {
                var opt = customerSelect.options[customerSelect.selectedIndex];
                if (!opt) return;
                var em = String(opt.getAttribute('data-email') || '').trim();
                if (em && (!input.value.trim() || String(input.value).indexOf('@') === -1)) {
                    input.value = em;
                }
            });
        }
    })();

    var quill = new Quill('#editor', {
        theme: 'snow',
        placeholder: 'Write your message...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, false] }],
                ['bold', 'italic', 'underline'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link', 'clean']
            ]
        }
    });

    <?php if ($editorHtml !== ''): ?>
    quill.root.innerHTML = <?= json_encode($editorHtml, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    <?php endif; ?>

    notifyParent('ready');

    var composeFiles = [];
    var MAX_ATTACH_BYTES = 12 * 1024 * 1024; // 12MB per file
    var MAX_ATTACH_TOTAL = 25 * 1024 * 1024; // 25MB total

    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function syncAttachmentInput() {
        var input = document.getElementById('composeAttachments');
        if (!input) return;
        try {
            var dt = new DataTransfer();
            composeFiles.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        } catch (err) {
            // Some browsers may not allow assigning FileList; FormData append handles it.
        }
    }

    function renderAttachmentPreviews() {
        var list = document.getElementById('attachmentPreviewList');
        if (!list) return;
        list.innerHTML = '';
        if (!composeFiles.length) {
            list.hidden = true;
            return;
        }
        list.hidden = false;
        composeFiles.forEach(function (file, index) {
            var chip = document.createElement('div');
            chip.className = 'compose-attach-chip';
            chip.innerHTML = '<i class="bi bi-paperclip"></i><span></span><button type="button" aria-label="Remove">&times;</button>';
            chip.querySelector('span').textContent = file.name + ' (' + formatBytes(file.size) + ')';
            chip.querySelector('button').addEventListener('click', function () {
                composeFiles.splice(index, 1);
                syncAttachmentInput();
                renderAttachmentPreviews();
            });
            list.appendChild(chip);
        });
    }

    (function initAttachments() {
        var btn = document.getElementById('composeAttachBtn');
        var input = document.getElementById('composeAttachments');
        if (!btn || !input) return;
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            var added = Array.prototype.slice.call(input.files || []);
            var total = composeFiles.reduce(function (s, f) { return s + (f.size || 0); }, 0);
            added.forEach(function (file) {
                if ((file.size || 0) > MAX_ATTACH_BYTES) {
                    Swal.fire({ icon: 'warning', title: 'File too large', text: file.name + ' exceeds 12MB.' });
                    return;
                }
                if (total + (file.size || 0) > MAX_ATTACH_TOTAL) {
                    Swal.fire({ icon: 'warning', title: 'Attachments too large', text: 'Total attachments must stay under 25MB.' });
                    return;
                }
                composeFiles.push(file);
                total += file.size || 0;
            });
            syncAttachmentInput();
            renderAttachmentPreviews();
            input.value = '';
            syncAttachmentInput();
        });
    })();

    document.getElementById('composeForm').onsubmit = function(e) {
        e.preventDefault();

        const body = quill.root.innerHTML;
        if (quill.getText().trim().length === 0) {
            Swal.fire({ icon: 'warning', title: 'Empty Body', text: 'Please write a message before sending.' });
            return;
        }

        document.getElementById('bodyInput').value = body;

        const btn = e.target.querySelector('button[type="submit"]');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sending...';

        const formData = new FormData(this);
        // Ensure attachments are included even if FileList sync failed
        formData.delete('attachments[]');
        composeFiles.forEach(function (file) {
            formData.append('attachments[]', file, file.name);
        });

        fetch('api/send.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                if (EMAIL_COMPOSE_EMBED) {
                    notifyParent('sent');
                    return;
                }
                Swal.fire({ icon: 'success', title: 'Email Sent', text: 'Message delivered successfully.', showConfirmButton: false, timer: 2000 });
                setTimeout(() => window.location.href = 'index.php?success=sent', 2000);
            } else {
                Swal.fire({ icon: 'error', title: 'Send Failure', text: data.message || 'Could not send.' });
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        })
        .catch(() => {
            Swal.fire({ icon: 'error', title: 'Send Failure', text: 'Network error.' });
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    };
</script>

<?php if ($embed): ?>
</body>
</html>
<?php else: ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
