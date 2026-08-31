<?php
// modules/email/view.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/includes/email_bootstrap.php';
requireLogin();

if (!isset($_GET['id'])) die("Invalid request");

$id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

$emailDb = email_module_pdo();
if (!($emailDb instanceof PDO)) {
    die("<div class='p-12 text-center text-gray-400'>Email storage unavailable.</div>");
}

$stmt = $emailDb->prepare("
    SELECT e.*, c.company_name as customer_name 
    FROM module_emails e 
    LEFT JOIN customers c ON e.customer_id = c.id 
    WHERE e.id = ? AND (e.user_id = ? OR e.user_id = 0)
");
$stmt->execute([$id, $user_id]);
$email = $stmt->fetch();

if (!$email) die("<div class='p-12 text-center text-gray-400'>Email not found or access denied.</div>");

$displayName = trim(email_decode_mime_header($email['customer_name'] ?: $email['sender_email']), " \t\n\r\0\x0B\"'");
$initials = strtoupper(substr($displayName, 0, 1));
$bgColors = ['bg-blue-100 text-blue-600', 'bg-emerald-100 text-emerald-600', 'bg-orange-100 text-orange-600', 'bg-purple-100 text-purple-600', 'bg-rose-100 text-rose-600'];
$avatarClass = $bgColors[ord($initials) % count($bgColors)];
$isStarred = !empty($email['is_starred']);
$folderLabel = 'Inbox';
if (($email['status'] ?? '') === 'archived') $folderLabel = 'Archive';
elseif (($email['status'] ?? '') === 'spam') $folderLabel = 'Spam';
elseif (($email['direction'] ?? '') === 'outbound') $folderLabel = 'Sent';
elseif (!empty($email['is_starred'])) $folderLabel = 'Starred';
?>
<style>
.attachment-card {
    position: relative;
    width: 220px;
    border: 1px solid #dadce0;
    border-radius: 8px;
    overflow: hidden;
    background: #ffffff;
    transition: box-shadow 0.2s;
    font-family: 'Outfit', sans-serif;
    display: flex;
    flex-direction: column;
}
.attachment-card:hover {
    box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
}
.attachment-thumbnail {
    height: 120px;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    border-bottom: 1px solid #dadce0;
}
.attachment-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.25);
    opacity: 0;
    transition: opacity 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    z-index: 10;
}
.attachment-thumbnail:hover .attachment-overlay {
    opacity: 1;
}
.attachment-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.9);
    color: #3c4043 !important;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none !important;
    transition: all 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.attachment-action-btn:hover {
    background: #ffffff;
    color: #000000 !important;
    transform: scale(1.1);
}
.attachment-footer {
    height: 48px;
    background: #f1f3f4;
    display: flex;
    align-items: center;
    padding: 0 12px;
    position: relative;
    font-size: 13px;
    font-weight: 500;
}
.attachment-footer:hover {
    background: #e8eaed;
}
.attachment-name {
    color: #3c4043;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex-grow: 1;
    padding-right: 16px;
}
.attachment-peel-bg {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 16px;
    height: 16px;
    clip-path: polygon(100% 0, 0 100%, 100% 100%);
    z-index: 1;
}
.attachment-peel-fold {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 16px;
    height: 16px;
    background: #c0c0c0;
    clip-path: polygon(0 0, 100% 0, 0 100%);
}
#replyBox.is-sending {
    box-shadow: 0 0 0 4px #f3e8ff;
    border-color: #e9d5ff;
}
.reply-input-wrap {
    position: relative;
    flex: 1;
    min-width: 0;
}
#replyBox.is-sending #replyMessage {
    opacity: 0.35;
    pointer-events: none;
}
.reply-sending-state {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 0 8px 0 4px;
    font-size: 14px;
    font-weight: 600;
    color: #9333ea;
    pointer-events: none;
    z-index: 2;
}
.reply-sending-state.hidden {
    display: none;
}
.reply-sending-dots {
    display: inline-flex;
    gap: 4px;
    align-items: center;
}
.reply-sending-dots span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #9333ea;
    animation: replySendingDot 1.2s infinite ease-in-out;
}
.reply-sending-dots span:nth-child(2) { animation-delay: 0.15s; }
.reply-sending-dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes replySendingDot {
    0%, 80%, 100% { transform: scale(0.65); opacity: 0.45; }
    40% { transform: scale(1); opacity: 1; }
}
    @media (max-width: 768px) {
    #email-detail-container .email-detail-toolbar {
        padding: 0.75rem 1rem !important;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    #email-detail-container .email-detail-toolbar-actions {
        gap: 0.35rem !important;
        flex-wrap: nowrap;
        min-width: max-content;
    }
    #email-detail-container .email-detail-toolbar-actions > button:first-child {
        display: none !important;
    }
    #email-detail-container .email-detail-toolbar-actions button {
        font-size: 13px !important;
        padding: 0.5rem 0.65rem !important;
        white-space: nowrap;
    }
    #email-detail-container .email-detail-toolbar-actions button .bi {
        font-size: 1rem !important;
    }
    #email-detail-container .flex-grow.overflow-y-auto.p-10 {
        padding: 1rem !important;
    }
    #email-detail-container h1.text-3xl {
        font-size: 1.35rem !important;
        line-height: 1.3 !important;
    }
    #email-detail-container .flex.items-center.justify-between.mb-10 {
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.5rem !important;
    }
    #email-detail-container .flex.items-center.gap-3:has(h1) {
        flex: 1 1 100%;
        align-items: flex-start;
    }
    #email-detail-container .w-16.h-16 {
        width: 3rem !important;
        height: 3rem !important;
        font-size: 1.125rem !important;
    }
    #email-detail-container .text-xl {
        font-size: 1rem !important;
    }
    #email-detail-container .text-base {
        font-size: 0.8125rem !important;
        word-break: break-all;
    }
    #email-detail-container .attachment-card {
        width: min(220px, 100%);
    }
    #email-detail-container #replyDock .px-8 {
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}
@media print {
    body * {
        visibility: hidden;
    }
    #emailPreview, #emailPreview * {
        visibility: visible;
    }
    #emailPreview {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: auto;
        overflow: visible;
    }
    #replyDock, #bottomActions, .px-8.py-4.border-b, .border-t.border-gray-50, button, .flex.gap-4, .attachment-overlay {
        display: none !important;
    }
}
</style>

<div id="email-detail-container" class="flex flex-col h-full bg-white"
     data-message-id="<?= htmlspecialchars($email['message_id'] ?: 'N/A') ?>"
     data-sender="<?= htmlspecialchars($email['sender_email']) ?>"
     data-recipient="<?= htmlspecialchars($email['recipient_email']) ?>"
     data-date="<?= htmlspecialchars($email['created_at']) ?>"
     data-subject="<?= htmlspecialchars(email_decode_mime_header($email['subject'] ?? '')) ?>"
     data-direction="<?= htmlspecialchars($email['direction'] ?? '') ?>"
     data-status="<?= htmlspecialchars($email['status'] ?? '') ?>">
    <!-- 1. Fixed Top Toolbar -->
    <div class="email-detail-toolbar px-8 py-4 border-b border-gray-50 flex items-center justify-between bg-white z-10 flex-shrink-0">
        <div class="email-detail-toolbar-actions flex items-center gap-6 text-black">
            <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="showEmailEmptyState(); document.querySelectorAll('.email-item').forEach(el => el.classList.remove('active'));">
                <i class="bi bi-arrow-left text-xl"></i> Back
            </button>
            <?php if ($email['status'] === 'archived'): ?>
                <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="updateEmailStatus(<?= $id ?>, 'read')">
                    <i class="bi bi-archive-fill text-xl"></i> Unarchive
                </button>
            <?php else: ?>
                <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="updateEmailStatus(<?= $id ?>, 'archived')">
                    <i class="bi bi-archive text-xl"></i> Archive
                </button>
            <?php endif; ?>
            <?php if ($email['status'] === 'trash'): ?>
                <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="updateEmailStatus(<?= $id ?>, 'read')">
                    <i class="bi bi-arrow-counterclockwise text-xl"></i> Restore
                </button>
            <?php else: ?>
                <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="updateEmailStatus(<?= $id ?>, 'trash')">
                    <i class="bi bi-trash text-xl"></i> Delete
                </button>
            <?php endif; ?>
            <button class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg" onclick="updateEmailStatus(<?= $id ?>, 'unread')">
                <i class="bi bi-envelope text-xl"></i> Mark as unread
            </button>
            <div class="relative inline-block text-left">
                <button id="moreBtn" onclick="toggleMoreDropdown(event)" class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 hover:text-blue-600 transition-all text-[15px] font-semibold border-none bg-transparent rounded-lg cursor-pointer">
                    <i class="bi bi-three-dots text-xl"></i> More
                </button>
                <div id="moreDropdown" class="hidden absolute left-0 mt-1 w-48 bg-white border border-gray-100 rounded-xl shadow-xl z-30 py-1 font-sans">
                    <button onclick="printEmail()" class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 flex items-center gap-2 border-none bg-transparent cursor-pointer font-semibold">
                        <i class="bi bi-printer text-base"></i> Print email
                    </button>
                    <button onclick="markAsSpam(<?= $id ?>)" class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 flex items-center gap-2 border-none bg-transparent cursor-pointer font-semibold">
                        <i class="bi bi-shield-exclamation text-base"></i> Mark as spam
                    </button>
                    <button onclick="blockSender(<?= htmlspecialchars(json_encode($email['sender_email'])) ?>)" class="w-full text-left px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 hover:text-red-700 flex items-center gap-2 border-none bg-transparent cursor-pointer font-semibold">
                        <i class="bi bi-slash-circle text-base"></i> Block sender
                    </button>
                    <div class="border-t border-gray-50 my-1"></div>
                    <button onclick="showOriginal()" class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 flex items-center gap-2 border-none bg-transparent cursor-pointer font-semibold">
                        <i class="bi bi-code-slash text-base"></i> Show original
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Scrollable Content Area -->
    <div class="flex-grow overflow-y-auto p-10">
        <div class="max-w-5xl mx-auto w-full">
    <!-- 2. Subject Line -->
    <div class="flex items-center justify-between mb-10">
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold text-black m-0 leading-tight"><?= htmlspecialchars(email_decode_mime_header($email['subject']) ?: '(No Subject)') ?></h1>
            <span class="email-folder-badge bg-gray-100 text-black text-[12px] px-3 py-1 rounded font-bold uppercase tracking-widest"><?= htmlspecialchars($folderLabel) ?></span>
        </div>
        <button type="button"
            id="emailStarBtn"
            class="text-black hover:text-yellow-400 transition-all bg-transparent border-none p-0 cursor-pointer"
            data-starred="<?= $isStarred ? '1' : '0' ?>"
            title="<?= $isStarred ? 'Unstar' : 'Star' ?>"
            onclick="toggleEmailStar(<?= $id ?>, event)">
            <i class="bi <?= $isStarred ? 'bi-star-fill text-yellow-400' : 'bi-star' ?> text-2xl"></i>
        </button>
    </div>
 
    <!-- 3. Sender Info -->
    <div class="flex items-center justify-between mb-10">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full flex items-center justify-center font-bold text-2xl ring-4 ring-gray-50 <?= $avatarClass ?>">
                <?= $initials ?>
            </div>
            <div>
                <div class="flex items-center gap-3">
                    <span class="font-bold text-black text-xl"><?= htmlspecialchars(email_decode_mime_header($email['customer_name'] ?: $email['sender_email'])) ?></span>
                    <span class="text-base text-black">&lt;<?= htmlspecialchars(email_decode_mime_header($email['sender_email'])) ?>&gt;</span>
                </div>
                <div class="text-sm text-black mt-1 font-medium">
                    To: <span class="text-black font-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Recipient') ?></span>
                </div>
            </div>
        </div>
        <div class="flex flex-col items-end gap-3">
            <span class="text-sm font-semibold text-black"><?= date('H:i A', strtotime($email['created_at'])) ?></span>
            <div class="flex gap-1">
                <button onclick="scrollToReply()" class="flex items-center gap-1.5 px-3 py-1.5 hover:bg-purple-50 rounded-lg text-purple-600 transition-all bg-transparent border-none font-bold text-[13px]">
                    <i class="bi bi-reply text-lg"></i> Reply
                </button>
                <button onclick="forwardEmail(<?= htmlspecialchars(json_encode([
                    'subject' => email_decode_mime_header($email['subject']) ?: '(No Subject)',
                    'from' => email_decode_mime_header($email['sender_email']),
                    'date' => date('D, d M Y \a\t H:i', strtotime($email['created_at'])),
                    'to' => email_decode_mime_header($email['recipient_email']),
                    'body' => strip_tags(parse_email_body_mime($email['body'])[1])
                ])) ?>)" class="flex items-center gap-1.5 px-3 py-1.5 hover:bg-purple-50 rounded-lg text-purple-600 transition-all bg-transparent border-none font-bold text-[13px]">
                    <i class="bi bi-share text-lg"></i> Forward
                </button>
                <button class="p-1.5 hover:bg-gray-50 rounded-lg text-black transition-all bg-transparent border-none">
                    <i class="bi bi-three-dots-vertical text-lg"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- 4. Email Body -->
    <div class="mb-14">
        <div class="email-metadata-block mb-8 p-6 bg-gray-50/50 rounded-2xl border border-gray-100 text-[15px] leading-relaxed text-black">
            <div class="mb-1"><span class="font-bold text-black w-20 inline-block">From:</span> <span class="text-black font-semibold"><?= htmlspecialchars(email_decode_mime_header($email['customer_name'] ?: $email['sender_email'])) ?></span> &lt;<?= htmlspecialchars(email_decode_mime_header($email['sender_email'])) ?>&gt;</div>
            <div class="mb-1"><span class="font-bold text-black w-20 inline-block">Date:</span> <span class="text-black"><?= date('D, d M Y \a\t H:i', strtotime($email['created_at'])) ?></span></div>
            <div class="mb-1"><span class="font-bold text-black w-20 inline-block">Subject:</span> <span class="text-black font-bold"><?= htmlspecialchars(email_decode_mime_header($email['subject']) ?: '(No Subject)') ?></span></div>
            <div><span class="font-bold text-black w-20 inline-block">To:</span> <span class="text-black font-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Recipient') ?></span> &lt;<?= htmlspecialchars(email_decode_mime_header($email['recipient_email'])) ?>&gt;</div>
        </div>
        
        <?php
        list($is_html, $clean_body) = parse_email_body_mime($email['body'], $id, (string) ($email['message_id'] ?? ''));
        $display_body = $is_html ? $clean_body : nl2br(htmlspecialchars($clean_body));
        ?>
        <div class="email-html-body text-[15px] leading-relaxed text-black break-words">
            <?= $display_body ?>
        </div>
    </div>

    <?php
    // Fetch attachments
    $att_stmt = $emailDb->prepare("SELECT * FROM module_email_attachments WHERE email_id = ?");
    $att_stmt->execute([$id]);
    $attachments = $att_stmt->fetchAll();
    ?>

    <?php if ($attachments): ?>
        <!-- 5. Attachments -->
        <div class="mt-14 pt-10 border-t border-gray-50">
            <!-- Gmail-style Attachments Header -->
            <div class="flex items-center justify-between border-b border-gray-100 pb-3 mb-4 max-w-5xl">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    <span class="font-bold text-gray-800"><?= count($attachments) === 1 ? 'One attachment' : count($attachments) . ' attachments' ?></span>
                    <span>•</span>
                    <span>Scanned by System</span>
                    <i class="bi bi-info-circle text-[11px] cursor-pointer" title="All attachments were scanned for viruses."></i>
                </div>
                <div class="flex gap-4">
                    <button onclick="downloadAllAttachments()" class="text-sm font-semibold text-purple-600 hover:text-purple-700 bg-transparent border-none p-0 cursor-pointer flex items-center gap-1">
                        <i class="bi bi-download"></i> Download All
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-4">
                <?php foreach ($attachments as $att): 
                    $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                    $isBlocked = ($att['file_path'] === 'blocked_virus');
                    
                    // Determine file type badge and peel color
                    $peelColor = '#70757a';
                    $typeBadge = 'FILE';
                    
                    if ($isBlocked) {
                        $peelColor = '#ef4444';
                        $typeBadge = 'BLOCKED';
                    } elseif ($ext === 'pdf') {
                        $peelColor = '#ea4335';
                        $typeBadge = 'PDF';
                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $peelColor = '#4285f4';
                        $typeBadge = 'IMG';
                    } elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) {
                        $peelColor = '#0f9d58';
                        $typeBadge = 'XLS';
                    } elseif (in_array($ext, ['doc', 'docx'])) {
                        $peelColor = '#1a73e8';
                        $typeBadge = 'DOC';
                    }
                    
                    $fileUrl = $isBlocked ? '#' : htmlspecialchars(function_exists('app_url') ? app_url('/' . $att['file_path']) : '/' . $att['file_path']);
                ?>
                    <div class="attachment-card" style="<?= $isBlocked ? 'border-color: #fca5a5;' : '' ?>">
                        <!-- Thumbnail Area -->
                        <div class="attachment-thumbnail" style="<?= $isBlocked ? 'background: #fff5f5;' : '' ?>">
                            <?php if ($isBlocked): ?>
                                <!-- Render a Shield-X warning icon representing malware protection block -->
                                <div class="flex flex-col items-center justify-center h-full w-full bg-red-50 text-red-500 p-3 text-center gap-1">
                                    <i class="bi bi-shield-fill-x text-4xl text-red-600 animate-pulse"></i>
                                    <span class="text-[11px] font-bold tracking-tight text-red-700 leading-tight">Blocked: Threat Detected</span>
                                    <span class="text-[9px] text-red-500 leading-tight max-w-[180px]">Virus scanner prevented download.</span>
                                </div>
                            <?php elseif ($ext === 'pdf'): ?>
                                <!-- Render container for PDF.js canvas preview with fallback icon -->
                                <div class="relative w-full h-full flex items-center justify-center bg-gray-50 overflow-hidden">
                                    <i class="bi bi-file-earmark-pdf-fill text-5xl text-red-500"></i>
                                    <canvas id="pdf-canvas-<?= $att['id'] ?>" class="pdf-canvas absolute top-0 left-0 w-full h-full object-cover hidden" data-pdf-url="<?= $fileUrl ?>"></canvas>
                                </div>
                            <?php elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                <!-- Render image preview directly -->
                                <img src="<?= $fileUrl ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <!-- Fallback large icon for other files -->
                                <?php 
                                $largeIcon = 'bi-file-earmark-fill';
                                $largeIconColor = 'text-gray-400';
                                if (in_array($ext, ['doc', 'docx'])) { $largeIcon = 'bi-file-earmark-word-fill'; $largeIconColor = 'text-blue-500'; }
                                elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) { $largeIcon = 'bi-file-earmark-excel-fill'; $largeIconColor = 'text-emerald-500'; }
                                ?>
                                <i class="bi <?= $largeIcon ?> text-5xl <?= $largeIconColor ?>"></i>
                            <?php endif; ?>

                            <!-- Actions Overlay -->
                            <?php if (!$isBlocked): ?>
                                <div class="attachment-overlay">
                                    <a href="<?= $fileUrl ?>" download="<?= htmlspecialchars($att['file_name']) ?>" class="attachment-action-btn attachment-card-link" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <a href="<?= $fileUrl ?>" target="_blank" class="attachment-action-btn" title="Open in new tab">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="attachment-overlay flex items-center justify-center" style="background: rgba(220,38,38,0.25);">
                                    <span class="text-white text-[10px] font-bold px-2 py-1 bg-red-600 rounded shadow flex items-center gap-1">
                                        <i class="bi bi-shield-lock-fill"></i> Secured
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer -->
                        <div class="attachment-footer" style="<?= $isBlocked ? 'background: #fef2f2;' : '' ?>">
                            <span style="background: <?= $peelColor ?>; color: white; padding: 2.5px 5px; border-radius: 3px; font-size: 8px; font-weight: 800; margin-right: 6px; line-height: 1;"><?= $typeBadge ?></span>
                            <span class="attachment-name" title="<?= htmlspecialchars($att['file_name']) ?>" style="<?= $isBlocked ? 'color: #991b1b;' : '' ?>"><?= htmlspecialchars($att['file_name']) ?></span>
                            
                            <!-- Folded Peel Corner -->
                            <div class="attachment-peel-bg" style="background: <?= $peelColor ?>;"></div>
                            <div class="attachment-peel-fold"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 6. Bottom Action Bar -->
    <div id="bottomActions" class="mt-14 mb-8">
        <div class="flex flex-wrap gap-3">
            <button onclick="scrollToReply()" class="px-8 py-3 bg-white border border-gray-200 rounded-2xl text-[14px] font-bold text-purple-600 hover:bg-purple-50 hover:border-purple-100 transition-all flex items-center gap-2 shadow-sm">
                <i class="bi bi-reply"></i> Reply
            </button>
            <button onclick="forwardEmail(<?= htmlspecialchars(json_encode([
                'subject' => email_decode_mime_header($email['subject']) ?: '(No Subject)',
                'from' => email_decode_mime_header($email['sender_email']),
                'date' => date('D, d M Y \a\t H:i', strtotime($email['created_at'])),
                'to' => email_decode_mime_header($email['recipient_email']),
                'body' => strip_tags(parse_email_body_mime($email['body'])[1])
            ])) ?>)" class="px-8 py-3 bg-white border border-gray-200 rounded-2xl text-[14px] font-bold text-purple-600 hover:bg-purple-50 hover:border-purple-100 transition-all flex items-center gap-2 shadow-sm">
                <i class="bi bi-share"></i> Forward
            </button>
        </div>
    </div>
    </div> <!-- Closes max-w-5xl container -->
    </div> <!-- Closes scrollable content container -->

    <!-- 3. Fixed Bottom Reply Dock -->
    <div id="replyDock" class="p-6 border-t border-gray-50 bg-white flex-shrink-0 relative z-20" style="display: none; overflow: visible;">
        <div class="max-w-5xl mx-auto w-full">
            <div id="replyBox" class="bg-white rounded-2xl p-4 flex flex-col gap-3 border border-gray-100 shadow-sm ring-4 ring-gray-50 transition-all overflow-visible">
                <div class="flex items-center gap-5 w-full min-w-0">
                    <div class="w-12 h-12 rounded-full bg-yellow-400 text-gray-800 flex items-center justify-center font-bold text-sm flex-shrink-0">
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'SA', 0, 1)) ?>
                    </div>
                    <div class="reply-input-wrap">
                        <input type="text" id="replyMessage" placeholder="Write a reply.." class="w-full min-w-0 bg-transparent border-none outline-none py-3 text-[14px] text-black font-medium" onkeydown="if(event.key === 'Enter' && !document.getElementById('replyBox')?.classList.contains('is-sending')) sendReply(<?= $id ?>)">
                        <div id="replySendingState" class="reply-sending-state hidden" aria-live="polite" aria-hidden="true">
                            <i class="bi bi-arrow-repeat text-lg animate-spin"></i>
                            <span>Sending</span>
                            <span class="reply-sending-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 pr-2 flex-shrink-0">
                        <button type="button" onclick="triggerReplyAttachmentSelect()" class="p-2.5 text-gray-300 hover:text-purple-600 transition-all bg-transparent border-none outline-none shadow-none cursor-pointer flex items-center justify-center" title="Attach files">
                            <i class="bi bi-paperclip text-lg"></i>
                        </button>
                        <div class="relative inline-block flex items-center">
                            <button type="button" onclick="toggleReplyEmojiPicker(event)" class="p-2.5 text-gray-300 hover:text-purple-600 transition-all bg-transparent border-none outline-none shadow-none cursor-pointer"><i class="bi bi-emoji-smile text-lg"></i></button>
                            <div id="replyEmojiPicker" class="emoji-picker hidden" onclick="event.stopPropagation()"></div>
                        </div>
                        <button type="button" id="sendReplyBtn" onclick="sendReply(<?= $id ?>)" class="p-2.5 text-gray-300 hover:text-purple-600 transition-all bg-transparent border-none outline-none shadow-none cursor-pointer"><i class="bi bi-send text-lg"></i></button>
                        <button type="button" onclick="hideReplyBox()" class="p-2.5 text-gray-300 hover:text-red-500 transition-all bg-transparent border-none outline-none shadow-none ms-2 cursor-pointer" title="Cancel reply"><i class="bi bi-x-lg text-lg"></i></button>
                    </div>
                </div>
                <!-- Reply Attachments Preview List -->
                <div id="replyAttachmentPreviewList" class="flex flex-wrap gap-2 pt-2 border-t border-gray-50 hidden"></div>
            </div>
            <input type="file" id="replyAttachments" multiple style="display: none;" onchange="handleReplyAttachmentFiles(this)">
        </div>
    </div>
</div>


