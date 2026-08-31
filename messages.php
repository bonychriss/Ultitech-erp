<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
ensureMessagesSchema();

$currentUserId = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$globalGroupId = ensureGlobalGroupAndMembership($currentUserId);

// Fetch all users for the sidebar
$stmt = $pdo->prepare("SELECT id, full_name, department, is_active, profile_photo FROM users WHERE is_active = 1 AND id != ? ORDER BY full_name ASC");
$stmt->execute([$currentUserId]);
$allUsers = $stmt->fetchAll();

// Get initial chat from URL or default to global group
$activeChatType = $_GET['type'] ?? 'group'; // 'group' or 'private'
$activeChatId = isset($_GET['id']) ? (int)$_GET['id'] : $globalGroupId;

if ($activeChatType === 'group' && $activeChatId <= 0) {
    $activeChatId = $globalGroupId;
}

// Helper to get initials
function getInitials($name) {
    $name = trim($name);
    if (!$name) return '?';
    return strtoupper(substr($name, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Ultimate General Trading</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Reset & Layout */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: #f3f4f6; }
        body { display: flex; flex-direction: column; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        
        /* Header overrides */
        .header { background: #ffffff !important; color: #111827 !important; padding: 10px 20px; flex-shrink: 0; z-index: 100; height: 60px; display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #f4b400; }
        .header .header-content { border: none !important; padding: 0 !important; width: 100%; max-width: none; }
        .header .header-logo { color: #111827; text-decoration: none; font-weight: bold; font-size: 16px; display: flex; align-items: center; gap: 10px; }
        .header .header-info { display: none; } /* Hide extra info to save space */
        .header-nav a { color: #4b5563; text-decoration: none; margin-left: 15px; font-size: 14px; opacity: 1; }
        .header-nav a:hover { color: #111827; text-decoration: underline; }

        /* Main App Container */
        .app-container { display: flex; flex: 1; height: calc(100% - 60px); position: relative; max-width: 1600px; margin: 0 auto; width: 100%; background: #fff; box-shadow: none; overflow: hidden; border-top: 1px solid #e5e7eb; }
        @media (min-width: 1441px) { .app-container { top: 0; height: calc(100% - 60px); margin-bottom: 0; border-radius: 0; } }

        /* Sidebar */
        .sidebar { width: 30%; max-width: 420px; min-width: 280px; display: flex; flex-direction: column; border-right: 1px solid #e5e7eb; background: #fff; }
        .sidebar-header { height: 60px; background: #fff; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #f3f4f6; color: #4b5563; display: flex; align-items: center; justify-content: center; font-weight: 600; cursor: pointer; border: 1px solid #e5e7eb; overflow: hidden; }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        .search-box { padding: 12px 16px; background: #fff; border-bottom: 1px solid #f3f4f6; flex-shrink: 0; }
        .search-input-wrap { background: #f9fafb; border-radius: 6px; padding: 8px 12px; display: flex; align-items: center; border: 1px solid #e5e7eb; }
        .search-input-wrap input { border: none; background: transparent; width: 100%; outline: none; font-size: 14px; margin-left: 10px; color: #111827; }
        
        .chat-list { flex: 1; overflow-y: auto; overflow-x: hidden; }
        .chat-item { display: flex; align-items: center; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background .2s; height: auto; min-height: 72px; }
        .chat-item:hover { background: #f9fafb; }
        .chat-item.active { background: #eff6ff; border-left: 3px solid #2563eb; }
        .chat-item .avatar { width: 48px; height: 48px; border-radius: 50%; background: #2563eb; color: white; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-right: 14px; flex-shrink: 0; overflow: hidden; }
        .chat-item .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .chat-item .info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .chat-item .name-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
        .chat-item .name { font-size: 15px; color: #111827; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chat-item .time { font-size: 11px; color: #6b7280; flex-shrink: 0; margin-left: 6px; }
        .chat-item .msg-preview { font-size: 13px; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: flex; align-items: center; }
        .chat-item .unread-badge { background: #2563eb; color: white; font-size: 11px; font-weight: 600; min-width: 20px; height: 20px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-left: 6px; padding: 0 4px; }

        /* Main Chat Area */
        .main-chat { flex: 1; display: flex; flex-direction: column; background: #fff; position: relative; }
        .main-chat::before { display: none; } /* Remove WhatsApp bg pattern */
        
        .chat-header { height: 60px; background: #fff; padding: 10px 16px; display: flex; align-items: center; border-bottom: 1px solid #e5e7eb; z-index: 10; flex-shrink: 0; }
        .chat-header .avatar { width: 40px; height: 40px; border-radius: 50%; background: #f3f4f6; margin-right: 14px; display: flex; align-items: center; justify-content: center; color: #4b5563; font-weight: 600; cursor: pointer; border: 1px solid #e5e7eb; overflow: hidden; }
        .chat-header .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .chat-header .info { flex: 1; cursor: pointer; }
        .chat-header .name { font-size: 16px; color: #111827; font-weight: 600; }
        .chat-header .status { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .chat-header .actions { display: flex; gap: 16px; color: #6b7280; }
        
        .messages-area { flex: 1; overflow-y: auto; padding: 20px 40px; z-index: 1; display: flex; flex-direction: column; gap: 12px; background: #fff; }
        @media (max-width: 900px) { .messages-area { padding: 20px 16px; } }
        
        .message-row { display: flex; margin-bottom: 4px; position: relative; }
        .message-row.sent { justify-content: flex-end; }
        .message-row.received { justify-content: flex-start; }
        
        .message-bubble { max-width: 70%; padding: 10px 14px; border-radius: 12px; position: relative; font-size: 14px; line-height: 1.5; color: #111827; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #f3f4f6; cursor: pointer; transition: background 0.2s; }
        .message-row.sent .message-bubble { background: #eff6ff; border-color: #dbeafe; border-bottom-right-radius: 2px; color: #1e3a8a; }
        .message-row.received .message-bubble { background: #f9fafb; border-color: #e5e7eb; border-bottom-left-radius: 2px; }
        
        .msg-sender { font-size: 12px; font-weight: 600; color: #2563eb; margin-bottom: 4px; cursor: pointer; }
        .msg-text { white-space: pre-wrap; word-wrap: break-word; }
        .msg-meta { display: flex; justify-content: flex-end; align-items: center; gap: 4px; margin-top: 4px; float: right; color: #9ca3af; font-size: 10px; margin-left: 8px; }
        
        .reply-preview {
            background: rgba(0,0,0,0.05);
            border-left: 3px solid #2563eb;
            padding: 4px 8px;
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 12px;
            color: #4b5563;
            cursor: pointer;
        }
        .reply-preview .reply-name { font-weight: 600; color: #2563eb; margin-bottom: 2px; }
        
        .reactions-row { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
        .reaction-pill { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 2px 6px; font-size: 11px; cursor: pointer; display: flex; align-items: center; gap: 2px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .reaction-pill:hover { background: #f3f4f6; }
        
        .attachment-preview { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; }
        .attachment-item { position: relative; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; max-width: 200px; }
        .attachment-item img { display: block; max-width: 100%; height: auto; }
        .attachment-file { padding: 10px; background: #f9fafb; display: flex; align-items: center; gap: 8px; text-decoration: none; color: #374151; font-size: 13px; }
        .attachment-file:hover { background: #f3f4f6; }
        
        .composer-container { background: #fff; border-top: 1px solid #e5e7eb; z-index: 10; display: flex; flex-direction: column; }
        .reply-banner { background: #f0f9ff; padding: 8px 16px; border-bottom: 1px solid #e0f2fe; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #0369a1; display: none; }
        .reply-banner.active { display: flex; }
        
        .composer { padding: 16px; display: flex; align-items: flex-end; gap: 12px; min-height: 72px; position: relative; }
        .composer button { background: none; border: none; padding: 8px; cursor: pointer; color: #6b7280; transition: color 0.2s; position: relative; }
        .composer button:hover { color: #2563eb; }
        .composer-input-wrap { flex: 1; background: #f9fafb; border-radius: 20px; padding: 10px 16px; display: flex; align-items: center; border: 1px solid #e5e7eb; }
        .composer-input-wrap:focus-within { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
        .composer textarea { width: 100%; border: none; outline: none; resize: none; max-height: 120px; font-family: inherit; font-size: 14px; line-height: 20px; padding: 0; margin: 0; background: transparent; color: #111827; }
        
        /* Context Menu */
        .context-menu { position: fixed; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; display: none; min-width: 160px; overflow: hidden; }
        .context-menu-item { padding: 10px 16px; font-size: 14px; color: #374151; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.1s; }
        .context-menu-item:hover { background: #f3f4f6; }
        .context-menu-item.danger { color: #ef4444; }
        .context-menu-item.danger:hover { background: #fef2f2; }
        
        /* Emoji Picker */
        .emoji-picker { position: fixed; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; display: none; width: 300px; height: 200px; overflow-y: auto; padding: 10px; grid-template-columns: repeat(8, 1fr); gap: 4px; }
        .emoji-picker.open { display: grid; }
        .emoji-item { cursor: pointer; font-size: 20px; text-align: center; padding: 4px; border-radius: 4px; }
        .emoji-item:hover { background: #f3f4f6; }
        
        /* Empty State */
        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; background: #fff; }
        .empty-state img { width: 120px; margin-bottom: 24px; opacity: 0.5; }
        .empty-state h2 { font-size: 24px; font-weight: 600; color: #111827; margin-bottom: 8px; }
        .empty-state p { font-size: 14px; color: #6b7280; max-width: 400px; line-height: 1.5; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { width: 100%; max-width: none; border-right: none; display: flex; }
            .main-chat { display: none; width: 100%; position: absolute; top: 0; left: 0; height: 100%; z-index: 200; }
            .app-container.chat-active .sidebar { display: none; }
            .app-container.chat-active .main-chat { display: flex; }
            .header { display: none; } /* Hide main header on mobile to save space */
            .app-container { height: 100%; top: 0; margin: 0; }
        }
        
        /* Utilities */
        .hidden { display: none !important; }
        .icon { width: 24px; height: 24px; fill: currentColor; }
        
        .recording-indicator { color: #ef4444; font-weight: 600; display: none; align-items: center; gap: 8px; margin-right: 10px; }
        .recording-indicator.active { display: flex; }
        .recording-dot { width: 10px; height: 10px; background: #ef4444; border-radius: 50%; animation: pulse 1s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>

    <!-- Top Header (Desktop) -->
    <header class="header">
        <a href="<?= $isAdmin ? 'admin/dashboard.php' : 'employee/dashboard.php' ?>" class="header-logo">
            <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Dashboard
        </a>
        <div class="header-nav">
            <span><?= htmlspecialchars($_SESSION['full_name']) ?></span>
        </div>
    </header>

    <div class="app-container" id="app">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="user-avatar" title="My Profile" style="display:flex !important; align-items:center !important; justify-content:center !important; position:relative !important; overflow:hidden !important; width: 40px !important; height: 40px !important; min-width: 40px !important; min-height: 40px !important; max-width: 40px !important; max-height: 40px !important; aspect-ratio: 1/1 !important; border-radius: 50% !important; flex-shrink: 0 !important;">
                    <i class="bi bi-person-fill" style="font-size: 20px;"></i>
                    <?php 
                    // Fetch current user photo
                    $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                    $stmt->execute([$currentUserId]);
                    $me = $stmt->fetch();
                    if (!empty($me['profile_photo'])) {
                        echo '<img src="'.htmlspecialchars($me['profile_photo']).'" alt="Me" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50% !important;" onerror="this.remove();">';
                    }
                    ?>
                </div>
                <div style="display:flex; gap:16px; color:#54656f;">
                    <button title="New Chat" style="background:none; border:none; cursor:pointer; color:inherit;">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <button title="Menu" style="background:none; border:none; cursor:pointer; color:inherit;">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                </div>
            </div>
            
            <div class="search-box">
                <div class="search-input-wrap">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#54656f" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="search-contacts" placeholder="Search or start new chat">
                </div>
            </div>
            
            <div class="chat-list" id="chat-list">
                <!-- Global Chat (Pinned) -->
                <div class="chat-item <?= ($activeChatType==='group' && $activeChatId==$globalGroupId) ? 'active' : '' ?>" onclick="switchChat('group', <?= $globalGroupId ?>)">
                    <div class="avatar">ðŸ“¢</div>
                    <div class="info">
                        <div class="name-row">
                            <span class="name">General Chat</span>
                            <span class="time" id="global-time"></span>
                        </div>
                        <div class="msg-preview" id="global-preview">
                            Tap to view messages
                        </div>
                    </div>
                </div>

                <!-- Users List -->
                <?php foreach ($allUsers as $u): ?>
                <div class="chat-item user-item <?= ($activeChatType==='private' && $activeChatId==$u['id']) ? 'active' : '' ?>" 
                     data-uid="<?= $u['id'] ?>" 
                     data-name="<?= htmlspecialchars($u['full_name']) ?>"
                     data-photo="<?= htmlspecialchars($u['profile_photo'] ?? '') ?>"
                     onclick="switchChat('private', <?= $u['id'] ?>)">
                    <div class="avatar" style="background:#dfe1e5; color:#54656f; display:flex !important; align-items:center !important; justify-content:center !important; position:relative !important; overflow:hidden !important; width: 48px !important; height: 48px !important; min-width: 48px !important; min-height: 48px !important; max-width: 48px !important; max-height: 48px !important; aspect-ratio: 1/1 !important; border-radius: 50% !important; flex-shrink: 0 !important;">
                        <i class="bi bi-person-fill" style="font-size: 24px;"></i>
                        <?php if (!empty($u['profile_photo'])): ?>
                            <img src="<?= htmlspecialchars($u['profile_photo']) ?>" alt="<?= htmlspecialchars($u['full_name']) ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50% !important;" onerror="this.remove();">
                        <?php endif; ?>
                    </div>
                    <div class="info">
                        <div class="name-row">
                            <span class="name"><?= htmlspecialchars($u['full_name']) ?></span>
                            <span class="time"></span>
                        </div>
                        <div class="msg-preview">
                            <?= htmlspecialchars($u['department'] ?? '') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main Chat Window -->
        <div class="main-chat" id="main-chat">
            <div class="chat-header">
                <button class="back-btn" onclick="closeChat()" style="margin-right:10px; background:none; border:none; cursor:pointer; display:none;">
                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </button>
                <div class="avatar" id="header-avatar">ðŸ“¢</div>
                <div class="info">
                    <div class="name" id="header-name">General Chat</div>
                    <div class="status" id="header-status">click here for group info</div>
                </div>
                <div class="actions">
                    <button style="background:none; border:none; cursor:pointer; color:inherit;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></button>
                    <button style="background:none; border:none; cursor:pointer; color:inherit;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg></button>
                </div>
            </div>

            <div class="messages-area" id="messages-area">
                <!-- Messages will be injected here -->
            </div>

            <div class="composer-container">
                <div class="reply-banner" id="reply-banner">
                    <div>
                        <span style="color:#6b7280; margin-right:4px;">Replying to</span>
                        <strong id="reply-to-name">Name</strong>
                    </div>
                    <button onclick="cancelReply()" style="background:none; border:none; cursor:pointer; color:#6b7280;">âœ•</button>
                </div>
                <div class="composer">
                    <button type="button" title="Emoji" data-emoji-btn onclick="toggleEmojiPicker(event)">😊</button>
                    <div class="emoji-picker" id="emoji-picker" onclick="event.stopPropagation()">
                        <!-- Emojis injected by JS -->
                    </div>
                    
                    <button type="button" title="Attach" data-attach-btn onclick="triggerFileSelect(event)">📎</button>
                    <input type="file" id="file-input" multiple style="display:none;" onchange="handleFileSelect(this)">
                    
                    <div class="composer-input-wrap">
                        <textarea id="msg-input" placeholder="Type a message" rows="1"></textarea>
                    </div>
                    
                    <div class="recording-indicator" id="recording-indicator">
                        <div class="recording-dot"></div>
                        <span>Recording...</span>
                    </div>
                    
                    <button title="Voice Note" id="mic-btn" onmousedown="startRecording()" onmouseup="stopRecording()" ontouchstart="startRecording()" ontouchend="stopRecording()">ðŸŽ¤</button>
                    
                    <button id="send-btn" title="Send" style="color:#2563eb;">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Empty State (Hidden by default, shown if no chat selected) -->
        <div class="empty-state hidden" id="empty-state">
            <img src="assets/images/chat-intro.png" alt="Welcome" style="max-width:300px; margin-bottom:20px;">
            <h2>Ultimate Chat</h2>
            <p>Send and receive messages without keeping your phone online.<br>Use Ultimate Chat on up to 4 linked devices and 1 phone.</p>
        </div>
    </div>

    <!-- Context Menu -->
    <div class="context-menu" id="context-menu">
        <div class="context-menu-item" onclick="handleContextAction('reply')">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg> Reply
        </div>
        <div class="context-menu-item" onclick="handleContextAction('copy')">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy
        </div>
        <div class="context-menu-item" onclick="handleContextAction('react', 'â¤ï¸')">
            <span>â¤ï¸</span> Love
        </div>
        <div class="context-menu-item" onclick="handleContextAction('react', 'ðŸ‘')">
            <span>ðŸ‘</span> Like
        </div>
        <div class="context-menu-item" onclick="handleContextAction('react', 'ðŸ˜‚')">
            <span>ðŸ˜‚</span> Haha
        </div>
        <div class="context-menu-item" id="ctx-edit" onclick="handleContextAction('edit')">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit
        </div>
        <div class="context-menu-item danger" id="ctx-delete" onclick="handleContextAction('delete')">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg> Delete
        </div>
    </div>

<script>
    // State
    let currentChatType = '<?= $activeChatType ?>';
    let currentChatId = <?= $activeChatId ?>;
    let currentUserId = <?= $currentUserId ?>;
    let lastMsgId = 0;
    let pollInterval = null;
    let isMobile = window.innerWidth <= 768;
    let typingTimeout = null;
    let replyTo = null; // { id, name, message }
    let contextMsgId = null;
    let contextMsgSenderId = null;
    
    // Recording
    let mediaRecorder = null;
    let audioChunks = [];
    let isRecording = false;

    // DOM Elements
    const app = document.getElementById('app');
    const msgArea = document.getElementById('messages-area');
    const msgInput = document.getElementById('msg-input');
    const sendBtn = document.getElementById('send-btn');
    const headerName = document.getElementById('header-name');
    const headerStatus = document.getElementById('header-status');
    const headerAvatar = document.getElementById('header-avatar');
    const backBtn = document.querySelector('.back-btn');
    const replyBanner = document.getElementById('reply-banner');
    const replyToName = document.getElementById('reply-to-name');
    const contextMenu = document.getElementById('context-menu');
    const emojiPicker = document.getElementById('emoji-picker');
    const recordingIndicator = document.getElementById('recording-indicator');

    // Init
    function init() {
        // Setup mobile view
        if (isMobile) {
            backBtn.style.display = 'block';
            if (currentChatId > 0) {
                app.classList.add('chat-active');
            }
        }

        // Auto-resize textarea
        msgInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            handleTyping();
        });

        // Send on Enter
        msgInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        sendBtn.addEventListener('click', () => sendMessage());

        // Initial load
        loadMessages(true);
        startPolling();
        
        // Search filter
        document.getElementById('search-contacts').addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.user-item').forEach(item => {
                const name = item.getAttribute('data-name').toLowerCase();
                item.style.display = name.includes(term) ? 'flex' : 'none';
            });
        });

        // Close menus on click outside
        document.addEventListener('click', function(e) {
            if (!contextMenu.contains(e.target)) contextMenu.style.display = 'none';
            if (!emojiPicker.contains(e.target) && !e.target.closest('[data-emoji-btn]')) {
                emojiPicker.classList.remove('open');
            }
        });
        
        // Init Emojis
        const emojis = ['ðŸ˜€','ðŸ˜‚','ðŸ˜','ðŸ˜­','ðŸ‘','ðŸ‘Ž','â¤ï¸','ðŸ”¥','ðŸŽ‰','ðŸ¤”','ðŸ‘€','ðŸš€','ðŸ’¯','ðŸ‘‹','ðŸ’©','ðŸ¤¡'];
        emojis.forEach(e => {
            const span = document.createElement('div');
            span.className = 'emoji-item';
            span.textContent = e;
            span.onclick = (ev) => {
                ev.stopPropagation();
                msgInput.value += e;
                emojiPicker.classList.remove('open');
                msgInput.focus();
            };
            emojiPicker.appendChild(span);
        });
    }

    function switchChat(type, id) {
        if (currentChatType === type && currentChatId === id) {
            if (isMobile) app.classList.add('chat-active');
            return;
        }

        currentChatType = type;
        currentChatId = id;
        lastMsgId = 0;
        cancelReply();
        
        // Update UI active state
        document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
        if (type === 'group') {
            document.querySelector('.chat-item:first-child').classList.add('active');
            headerName.textContent = 'General Chat';
            headerAvatar.innerHTML = 'ðŸ“¢';
            headerAvatar.style.background = '#2563eb';
            headerAvatar.style.color = '#fff';
            headerStatus.textContent = 'click here for group info';
        } else {
            const userItem = document.querySelector(`.user-item[data-uid="${id}"]`);
            if (userItem) {
                userItem.classList.add('active');
                headerName.textContent = userItem.getAttribute('data-name');
                const photo = userItem.getAttribute('data-photo');
                if (photo) {
                    headerAvatar.innerHTML = `<img src="${photo}" alt="Avatar">`;
                    headerAvatar.style.background = 'transparent';
                } else {
                    headerAvatar.innerHTML = userItem.querySelector('.avatar').innerHTML;
                    headerAvatar.style.background = '#dfe1e5';
                    headerAvatar.style.color = '#54656f';
                }
                headerStatus.textContent = 'Online'; // Placeholder
            }
        }

        // Clear messages
        msgArea.innerHTML = '';
        
        // Mobile transition
        if (isMobile) app.classList.add('chat-active');

        // Load new chat
        loadMessages(true);
    }

    function closeChat() {
        app.classList.remove('chat-active');
    }

    function loadMessages(isFullLoad = false) {
        const since = isFullLoad ? 0 : lastMsgId;
        fetch(`includes/messages_api.php?action=fetch&chat_type=${currentChatType}&chat_id=${currentChatId}&since=${since}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (data.messages.length > 0) {
                        renderMessages(data.messages);
                        lastMsgId = data.lastId;
                        if (isFullLoad) scrollToBottom();
                        else if (isNearBottom()) scrollToBottom();
                    }
                    
                    // Update typing status
                    if (data.typing && data.typing.length > 0) {
                        const names = data.typing.map(t => t.full_name).join(', ');
                        headerStatus.textContent = names + ' is typing...';
                        headerStatus.style.color = '#2563eb';
                        headerStatus.style.fontWeight = '600';
                    } else {
                        headerStatus.textContent = currentChatType === 'group' ? 'click here for group info' : 'Online';
                        headerStatus.style.color = '#6b7280';
                        headerStatus.style.fontWeight = 'normal';
                    }
                }
            });
    }

    function renderMessages(msgs) {
        msgs.forEach(m => {
            const isMe = m.sender_id === currentUserId;
            
            // Check if message already exists (for updates/edits)
            const existing = document.getElementById('msg-' + m.id);
            if (existing) {
                // Update content if edited
                const textEl = existing.querySelector('.msg-text');
                if (textEl.textContent !== m.message) {
                    textEl.innerHTML = linkify(esc(m.message));
                    existing.querySelector('.msg-meta').innerHTML += ' <span style="font-style:italic;">(edited)</span>';
                }
                return;
            }

            const div = document.createElement('div');
            div.className = `message-row ${isMe ? 'sent' : 'received'}`;
            div.id = 'msg-' + m.id;
            
            // Format time
            const date = new Date(m.created_at);
            const timeStr = date.getHours().toString().padStart(2,'0') + ':' + date.getMinutes().toString().padStart(2,'0');
            
            // Sender name color (hash)
            const color = isMe ? '' : getColorForName(m.full_name);
            
            // Read status ticks
            let ticks = '';
            if (isMe) {
                const isRead = m.read_by && m.read_by.length > 0;
                const tickColor = '#facc15'; // Yellow-400
                if (isRead) {
                    ticks = `<span class="ticks" style="color:${tickColor}; font-weight:bold;">âœ“âœ“</span>`;
                } else {
                    ticks = `<span class="ticks" style="color:${tickColor}; font-weight:bold;">âœ“</span>`;
                }
            }
            
            // Avatar for received messages in group
            let senderHtml = '';
            if (!isMe && currentChatType === 'group') {
                senderHtml = `<div class="msg-sender" style="color:${color}">${esc(m.full_name)}</div>`;
            }

            // Reply preview
            let replyHtml = '';
            if (m.reply) {
                replyHtml = `
                    <div class="reply-preview">
                        <div class="reply-name">${esc(m.reply.full_name)}</div>
                        <div class="reply-text">${esc(m.reply.message)}</div>
                    </div>
                `;
            }

            // Attachments
            let attachHtml = '';
            if (m.attachments && m.attachments.length > 0) {
                attachHtml = '<div class="attachment-preview">';
                m.attachments.forEach(a => {
                    if (a.mime_type.startsWith('image/')) {
                        attachHtml += `
                            <div class="attachment-item">
                                <a href="${a.file_path}" target="_blank"><img src="${a.file_path}" alt="Image"></a>
                            </div>
                        `;
                    } else if (a.mime_type.startsWith('audio/')) {
                        attachHtml += `
                            <div class="attachment-item" style="width:250px;">
                                <audio controls src="${a.file_path}" style="width:100%;"></audio>
                            </div>
                        `;
                    } else {
                        attachHtml += `
                            <a href="${a.file_path}" target="_blank" class="attachment-file">
                                ðŸ“„ ${esc(a.file_name)}
                            </a>
                        `;
                    }
                });
                attachHtml += '</div>';
            }

            // Reactions
            let reactionsHtml = '';
            if (m.reactions && m.reactions.length > 0) {
                reactionsHtml = '<div class="reactions-row">';
                // Group reactions by type
                const counts = {};
                m.reactions.forEach(r => {
                    counts[r.reaction] = (counts[r.reaction] || 0) + 1;
                });
                for (const [react, count] of Object.entries(counts)) {
                    reactionsHtml += `<div class="reaction-pill">${react} ${count}</div>`;
                }
                reactionsHtml += '</div>';
            }

            div.innerHTML = `
                <div class="message-bubble" oncontextmenu="showContextMenu(event, ${m.id}, ${m.sender_id}, '${esc(m.full_name).replace(/'/g, "\\'")}', '${esc(m.message).replace(/'/g, "\\'")}')">
                    ${replyHtml}
                    ${senderHtml}
                    ${attachHtml}
                    <div class="msg-text">${linkify(esc(m.message))}</div>
                    <div class="msg-meta">
                        ${timeStr}
                        ${ticks}
                    </div>
                    ${reactionsHtml}
                </div>
            `;
            msgArea.appendChild(div);
        });
    }

    function sendMessage(files = null) {
        const text = msgInput.value.trim();
        if (!text && !files) return;

        // Optimistic UI (skip for files for now)
        if (!files) {
            const tempId = Date.now();
            renderMessages([{
                id: tempId,
                sender_id: currentUserId,
                full_name: 'Me',
                message: text,
                created_at: new Date().toISOString()
            }]);
            scrollToBottom();
        }
        
        // Send API
        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('message', text);
        if (currentChatType === 'private') {
            formData.append('recipient_id', currentChatId);
        } else {
            formData.append('group_id', currentChatId);
        }
        if (replyTo) {
            formData.append('reply_to_id', replyTo.id);
        }
        
        if (files) {
            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
        }

        fetch('includes/messages_api.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.ok) {
                cancelReply();
                loadMessages(false); // Immediate fetch
                scrollToBottom();
            } else {
                alert('Error sending message: ' + data.error);
            }
        });
        
        msgInput.value = '';
        msgInput.style.height = 'auto';
    }

    function handleFileSelect(input) {
        if (input.files && input.files.length > 0) {
            sendMessage(input.files);
            input.value = ''; // Reset
        }
    }

    // Voice Recording
    function startRecording() {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
                mediaRecorder = new MediaRecorder(stream);
                mediaRecorder.start();
                isRecording = true;
                recordingIndicator.classList.add('active');
                
                audioChunks = [];
                mediaRecorder.addEventListener("dataavailable", event => {
                    audioChunks.push(event.data);
                });
                
                mediaRecorder.addEventListener("stop", () => {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const file = new File([audioBlob], "voice_note.webm", { type: 'audio/webm' });
                    sendMessage([file]);
                    isRecording = false;
                    recordingIndicator.classList.remove('active');
                });
            });
        } else {
            alert('Microphone not supported in this browser.');
        }
    }

    function stopRecording() {
        if (mediaRecorder && isRecording) {
            mediaRecorder.stop();
        }
    }

    function handleTyping() {
        if (typingTimeout) clearTimeout(typingTimeout);
        
        // Send typing start
        fetch('includes/messages_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=typing&typing=1`
        });

        typingTimeout = setTimeout(() => {
            // Send typing stop
            fetch('includes/messages_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=typing&typing=0`
            });
        }, 2000);
    }

    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => loadMessages(false), 3000);
    }

    function scrollToBottom() {
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function isNearBottom() {
        return msgArea.scrollHeight - msgArea.scrollTop - msgArea.clientHeight < 100;
    }

    // Context Menu Logic
    function showContextMenu(e, msgId, senderId, senderName, msgText) {
        e.preventDefault();
        contextMsgId = msgId;
        contextMsgSenderId = senderId;
        
        // Show/Hide Edit/Delete based on ownership
        const isMe = senderId === currentUserId;
        document.getElementById('ctx-edit').style.display = isMe ? 'flex' : 'none';
        document.getElementById('ctx-delete').style.display = isMe ? 'flex' : 'none';
        
        // Store data for actions
        contextMenu.dataset.msgId = msgId;
        contextMenu.dataset.senderName = senderName;
        contextMenu.dataset.msgText = msgText;

        // Position menu
        contextMenu.style.display = 'block';
        contextMenu.style.left = e.pageX + 'px';
        contextMenu.style.top = e.pageY + 'px';
        
        // Adjust if off screen
        const rect = contextMenu.getBoundingClientRect();
        if (rect.right > window.innerWidth) contextMenu.style.left = (window.innerWidth - rect.width - 10) + 'px';
        if (rect.bottom > window.innerHeight) contextMenu.style.top = (window.innerHeight - rect.height - 10) + 'px';
    }

    function handleContextAction(action, param) {
        contextMenu.style.display = 'none';
        const msgId = contextMenu.dataset.msgId;
        const name = contextMenu.dataset.senderName;
        const text = contextMenu.dataset.msgText;

        if (action === 'reply') {
            replyTo = { id: msgId, name: name, message: text };
            replyToName.textContent = name;
            replyBanner.classList.add('active');
            msgInput.focus();
        } else if (action === 'copy') {
            navigator.clipboard.writeText(text);
        } else if (action === 'react') {
            fetch('includes/messages_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=add_reaction&message_id=${msgId}&reaction=${param}`
            }).then(() => loadMessages(false));
        } else if (action === 'delete') {
            if (confirm('Delete this message?')) {
                fetch('includes/messages_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=delete_message&message_id=${msgId}`
                }).then(() => {
                    document.getElementById('msg-' + msgId).remove();
                });
            }
        } else if (action === 'edit') {
            const newText = prompt('Edit message:', text);
            if (newText && newText !== text) {
                fetch('includes/messages_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=edit_message&message_id=${msgId}&message=${encodeURIComponent(newText)}`
                }).then(() => loadMessages(false));
            }
        }
    }

    function cancelReply() {
        replyTo = null;
        replyBanner.classList.remove('active');
    }

    function triggerFileSelect(e) {
        if (e) e.stopPropagation();
        document.getElementById('file-input').click();
    }

    function toggleEmojiPicker(e) {
        if (e) e.stopPropagation();
        const btn = document.querySelector('[data-emoji-btn]');
        if (!btn) return;

        const isOpen = emojiPicker.classList.contains('open');
        if (isOpen) {
            emojiPicker.classList.remove('open');
            return;
        }

        const rect = btn.getBoundingClientRect();
        emojiPicker.style.left = Math.max(8, rect.left) + 'px';
        emojiPicker.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
        emojiPicker.classList.add('open');
    }

    // Utilities
    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function linkify(text) {
        return text.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" style="color:#027eb5;text-decoration:underline;">$1</a>');
    }
    function getColorForName(name) {
        const colors = ['#2563eb', '#0891b2', '#0d9488', '#4f46e5', '#7c3aed'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
        return colors[Math.abs(hash) % colors.length];
    }

    init();
</script>
</body>
</html>
