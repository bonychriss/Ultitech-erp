<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                if (localStorage.getItem('emailTheme') === 'dark') {
                    document.documentElement.setAttribute('data-email-theme', 'dark');
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Email' : 'Customer Email | Staff'; ?></title>
    <!-- Assets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        tailwind.config = { 
            corePlugins: { preflight: false },
            theme: {
                extend: {
                    colors: {
                        primary: '#2563eb',
                        secondary: '#64748b',
                    }
                }
            }
        };
    </script>
    <style>
        body { font-family: 'Outfit', sans-serif; background: #fff; margin: 0; padding: 0; overflow: hidden; }
        .top-nav { height: 70px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; padding: 0 32px; background: #fff; justify-content: space-between; z-index: 1000; position: relative; }
        .logo-box { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        .search-container { position: relative; }
        .search-input { width: 100%; background: #f8fafc; border: none; padding: 14px 16px 14px 52px; border-radius: 0; font-size: 14px; outline: none; transition: all 0.2s; border: 1px solid transparent; }
        .search-input:focus { background: #fff; border-color: #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); }
        .header-right-section { display: flex; align-items: center; gap: 32px; flex-shrink: 0; }
        .user-profile { display: flex; align-items: center; gap: 14px; cursor: pointer; padding-left: 32px; border-left: 1px solid #f1f5f9; }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: #fff; font-size: 10px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; font-weight: 700; }
        .logo-img { height: 44px; width: auto; object-fit: contain; max-width: 160px; display: block; }

        /* Compose Modal Styles (Centered - High Fidelity Refinement) */
        #composeModal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 780px; background: #fff; border-radius: 24px; shadow: 0 25px 50px -12px rgb(0 0 0 / 0.15); z-index: 2000; border: 1px solid #f1f5f9; display: none; flex-direction: column; overflow: hidden; }
        .compose-header { background: #fff; padding: 24px 32px; display: flex; justify-content: space-between; align-items: center; }
        .compose-header span { font-size: 18px; font-weight: 700; color: #1e293b; }
        .compose-body { padding: 0; display: flex; flex-direction: column; }
        .compose-input-group { border-bottom: 1px solid #f8fafc; padding: 12px 32px; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .compose-label { width: 40px; color: #94a3b8; font-size: 14px; font-weight: 500; }
        .chip-container { display: flex; flex-wrap: wrap; gap: 8px; flex: 1; align-items: center; }
        .email-chip { background: #f8fafc; border-radius: 50px; padding: 4px 12px 4px 4px; display: flex; align-items: center; gap: 8px; border: 1px solid #f1f5f9; }
        .chip-avatar { width: 24px; height: 24px; background: #94a3b8; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; }
        .chip-text { font-size: 14px; color: #475569; font-weight: 500; }
        .chip-input { border: none; outline: none; flex: 1; min-width: 120px; font-size: 15px; color: #1e293b; padding: 8px 0; font-weight: 500; }
        .subject-input { border: none; outline: none; width: 100%; font-size: 18px; color: #1e293b; font-weight: 800; padding: 20px 32px; border-bottom: 1px solid #f8fafc; }
        .compose-textarea { width: 100%; min-height: 340px; border: none; outline: none; padding: 32px; font-size: 16px; resize: none; color: #475569; line-height: 1.6; }
        .compose-footer { padding: 24px 32px; display: flex; align-items: center; gap: 16px; background: #fff; position: relative; overflow: visible; }
        .btn-send { background: #a855f7; hover:bg-purple-600; color: #fff; font-weight: 800; padding: 12px 48px; border-radius: 50px; transition: all 0.2s; border: 0; outline: none; font-size: 16px; flex-shrink: 0; }
        .compose-footer-tools { display: flex; gap: 6px; align-items: center; flex: 1; min-width: 0; }
        .footer-icon-btn { background: none; border: none; padding: 6px; cursor: pointer; color: #94a3b8; font-size: 22px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; transition: color 0.2s; }
        .footer-icon-btn:hover { color: #64748b; }
        .footer-icon-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .compose-tool-menu {
            position: fixed;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            z-index: 2300;
            min-width: 160px;
            padding: 6px;
        }
        .compose-tool-menu.hidden { display: none; }
        .compose-tool-menu-item {
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .compose-tool-menu-item:hover { background: #faf5ff; color: #7c3aed; }
        .compose-footer-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            margin-right: 4px;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 1;
        }
        .compose-footer-status.hidden { display: none; }
        .compose-footer-status.status-sending { color: #9333ea; background: #faf5ff; border: 1px solid #f3e8ff; }
        .compose-footer-status.status-warning { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; }
        .compose-footer-status.status-error { color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; }
        .compose-footer-status.status-success { color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; }
        .compose-sending-dots { display: inline-flex; gap: 4px; align-items: center; }
        .compose-sending-dots span {
            width: 6px; height: 6px; border-radius: 50%; background: currentColor;
            animation: composeSendingDot 1.2s infinite ease-in-out;
        }
        .compose-sending-dots span:nth-child(2) { animation-delay: 0.15s; }
        .compose-sending-dots span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes composeSendingDot {
            0%, 80%, 100% { transform: scale(0.65); opacity: 0.45; }
            40% { transform: scale(1); opacity: 1; }
        }
        .compose-field-missing {
            box-shadow: inset 0 0 0 2px #fca5a5 !important;
            background: #fff5f5 !important;
        }
        #composeModal.is-sending .compose-textarea,
        #composeModal.is-sending .subject-input,
        #composeModal.is-sending .chip-input { opacity: 0.55; pointer-events: none; }
        .footer-icon { color: #94a3b8; font-size: 22px; cursor: pointer; transition: color 0.2s; }
        .footer-icon:hover { color: #64748b; }
        
        #composeOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.05); backdrop-filter: blur(8px); z-index: 1999; display: none; }

        /* Custom Autocomplete Suggestions Dropdown */
        .suggest-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            width: 380px;
            max-height: 250px;
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 2100;
            margin-top: 4px;
        }
        .suggest-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .suggest-item:hover, .suggest-item.active {
            background: #faf5ff; /* Sleek light purple background matching purple-50/100 */
        }
        .suggest-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 13px;
            flex-shrink: 0;
        }
        .suggest-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex-grow: 1;
        }
        .suggest-name {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .suggest-email {
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Custom Emoji Picker Styles */
        .emoji-picker {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            width: 280px;
            height: 200px;
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 2200;
            margin-bottom: 8px;
            padding: 10px;
        }
        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .emoji-item-span {
            font-size: 20px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.1s ease;
            user-select: none;
            padding: 4px;
            border-radius: 6px;
        }
        .emoji-item-span:hover {
            background: #f3e8ff;
            transform: scale(1.2);
        }

        .email-main-stage {
            height: calc(100vh - 70px);
            height: calc(100dvh - 70px);
        }

        @media (max-width: 768px) {
            .top-nav {
                height: 56px;
                padding: 0 12px;
            }
            .logo-box .me-6 { margin-right: 8px !important; }
            .logo-img {
                max-width: 110px;
                height: 34px;
            }
            .header-right-section {
                gap: 8px;
            }
            .header-right-section .gap-6 {
                gap: 10px !important;
            }
            .header-right-section .bi-question-circle {
                display: none;
            }
            .notif-badge {
                width: 14px !important;
                height: 14px !important;
                font-size: 8px !important;
            }
            #composeModal {
                width: calc(100vw - 16px) !important;
                max-width: calc(100vw - 16px) !important;
                max-height: calc(100dvh - 24px);
                border-radius: 16px;
            }
            .compose-header { padding: 16px 18px; }
            .compose-input-group { padding: 10px 18px; }
            .subject-input { padding: 14px 18px; font-size: 16px; }
            .compose-textarea { min-height: 220px; padding: 18px; font-size: 15px; }
            .compose-footer { padding: 14px 18px; flex-wrap: wrap; gap: 10px; }
            .btn-send { padding: 10px 28px; font-size: 14px; }
            .compose-footer-status { max-width: 100%; white-space: normal; }
            .email-main-stage {
                height: calc(100vh - 56px);
                height: calc(100dvh - 56px);
            }
        }
    </style>
    <?php
    $emailDarkThemeCss = function_exists('app_url')
        ? app_url('modules/email/assets/email-dark-theme.css')
        : '../assets/email-dark-theme.css';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($emailDarkThemeCss) ?>?v=5">
</head>
<body>
    <div id="composeOverlay" onclick="toggleCompose()"></div>
    <?php
    // Fetch system logo using global helper
    $logo_url = '';
    if (function_exists('getCompanyLogoUrl')) {
        $logo_url = getCompanyLogoUrl();
    }
    if (empty($logo_url)) {
        $logo_url = function_exists('app_url') ? app_url('/assets/images/logo.png') : '/assets/images/logo.png';
    }

    $currentSlug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
    if ($currentSlug !== '') {
        $selectModuleUrl = function_exists('company_url') ? company_url('select-module.php', $currentSlug) : '../../select-module.php';
    } else {
        $selectModuleUrl = function_exists('app_url') ? app_url('/select-module.php') : '../../select-module.php';
    }

    $customer_emails = [];
    if (isset($pdo)) {
        try {
            // Fetch customer emails for autofill
            $email_stmt = $pdo->query("SELECT DISTINCT email, company_name FROM customers WHERE email IS NOT NULL AND email != ''");
            $customer_emails = $email_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
    ?>
    
     <header class="top-nav">
         <div class="logo-box">
             <button onclick="toggleSidebar()" class="me-6 p-2.5 bg-transparent hover:bg-gray-50 rounded-2xl text-black transition-all border-0 outline-none">
                 <i class="bi bi-list text-2xl"></i>
             </button>
             <a href="<?= htmlspecialchars($selectModuleUrl) ?>" class="block">
                 <img src="<?= htmlspecialchars($logo_url) ?>" alt="System Logo" class="logo-img">
             </a>
         </div>

  
         <div class="header-right-section">
             <div class="flex items-center gap-6">
                 <button type="button" id="emailThemeToggle" onclick="toggleEmailTheme()" class="p-2.5 bg-transparent hover:bg-gray-50 rounded-2xl border-0 outline-none transition-all" title="Toggle dark mode">
                     <i class="bi bi-moon-stars text-2xl text-black" id="emailThemeToggleIcon"></i>
                 </button>
                 <div class="relative cursor-pointer hover:text-blue-600 transition-all text-black">
                     <i class="bi bi-bell text-2xl"></i>
                     <span class="notif-badge" style="width: 18px; height: 18px; font-size: 10px; top: -5px; right: -5px;">3</span>
                 </div>
                 <div class="relative cursor-pointer hover:text-blue-600 transition-all text-black">
                     <i class="bi bi-question-circle text-2xl"></i>
                 </div>
             </div>
         </div>
     </header>
 
     <!-- Floating Compose Modal -->
     <div id="composeModal">
         <div class="compose-header">
             <span>New Message</span>
             <div class="flex gap-4 text-gray-300">
                 <i class="bi bi-dash-lg hover:text-gray-500 cursor-pointer"></i>
                 <i class="bi bi-arrows-angle-expand hover:text-gray-500 text-xs cursor-pointer"></i>
                 <i class="bi bi-x-lg hover:text-red-500 cursor-pointer" onclick="toggleCompose()"></i>
             </div>
         </div>
         <div class="compose-body">
             <div class="compose-input-group">
                 <span class="compose-label">To</span>
                 <div class="chip-container" id="toChips">
                     <div class="relative flex-grow min-w-[150px]">
                         <input type="text" class="chip-input w-full" placeholder="Recipients" onkeydown="handleSuggestKeydown(event, 'to')" oninput="handleSuggestInput(this, 'to')" onfocus="handleSuggestFocus(this, 'to')" autocomplete="off">
                         <div class="suggest-dropdown hidden" id="toSuggests"></div>
                     </div>
                 </div>
                 <div class="flex gap-3 text-xs text-gray-400 font-bold flex-shrink-0">
                     <span class="cursor-pointer hover:text-blue-600" onclick="toggleCcBcc('cc')">Cc</span>
                     <span class="cursor-pointer hover:text-blue-600" onclick="toggleCcBcc('bcc')">Bcc</span>
                 </div>
             </div>
             <div id="ccGroup" class="compose-input-group" style="display: none;">
                 <span class="compose-label">Cc</span>
                 <div class="chip-container" id="ccChips">
                     <div class="relative flex-grow min-w-[150px]">
                         <input type="text" class="chip-input w-full" placeholder="Cc recipients" onkeydown="handleSuggestKeydown(event, 'cc')" oninput="handleSuggestInput(this, 'cc')" onfocus="handleSuggestFocus(this, 'cc')" autocomplete="off">
                         <div class="suggest-dropdown hidden" id="ccSuggests"></div>
                     </div>
                 </div>
             </div>
             <div id="bccGroup" class="compose-input-group" style="display: none;">
                 <span class="compose-label">Bcc</span>
                 <div class="chip-container" id="bccChips">
                     <div class="relative flex-grow min-w-[150px]">
                         <input type="text" class="chip-input w-full" placeholder="Bcc recipients" onkeydown="handleSuggestKeydown(event, 'bcc')" oninput="handleSuggestInput(this, 'bcc')" onfocus="handleSuggestFocus(this, 'bcc')" autocomplete="off">
                         <div class="suggest-dropdown hidden" id="bccSuggests"></div>
                     </div>
                 </div>
             </div>
            <input type="text" class="subject-input" placeholder="Subject">
            <textarea class="compose-textarea" placeholder="Write your message..."></textarea>
            <!-- Attachments Preview List -->
            <div id="attachmentPreviewList" class="flex flex-wrap gap-2 px-8 py-3 bg-gray-50 border-t border-b border-gray-100 hidden"></div>
        </div>
        <div class="compose-footer">
            <button type="button" class="btn-send" onclick="sendEmail()">
                Send
            </button>
            <div class="compose-footer-tools">
                <div class="relative inline-flex items-center">
                    <button type="button" class="footer-icon-btn" data-compose-format-btn title="Formatting" onclick="toggleComposeFormatMenu(event)">
                        <i class="bi bi-type"></i>
                    </button>
                    <div id="composeFormatMenu" class="compose-tool-menu hidden" onclick="event.stopPropagation()">
                        <button type="button" class="compose-tool-menu-item" onclick="applyComposeFormat('bold')"><i class="bi bi-type-bold"></i> Bold</button>
                        <button type="button" class="compose-tool-menu-item" onclick="applyComposeFormat('italic')"><i class="bi bi-type-italic"></i> Italic</button>
                        <button type="button" class="compose-tool-menu-item" onclick="applyComposeFormat('underline')"><i class="bi bi-type-underline"></i> Underline</button>
                    </div>
                </div>
                <button type="button" class="footer-icon-btn" data-compose-attach-btn title="Attach files" onclick="triggerAttachmentSelect(event)">
                    <i class="bi bi-paperclip"></i>
                </button>
                <button type="button" class="footer-icon-btn" title="Insert link" onclick="insertComposeLink(event)">
                    <i class="bi bi-link-45deg"></i>
                </button>
                <div class="relative inline-flex items-center">
                    <button type="button" class="footer-icon-btn" data-compose-emoji-btn title="Emoji" onclick="toggleComposeEmojiPicker(event)">
                        <i class="bi bi-emoji-smile"></i>
                    </button>
                    <div id="composeEmojiPicker" class="emoji-picker hidden" onclick="event.stopPropagation()"></div>
                </div>
                <button type="button" class="footer-icon-btn" title="Insert image" onclick="triggerComposeImageSelect(event)">
                    <i class="bi bi-image"></i>
                </button>
                <div class="h-6 w-px bg-gray-100 mx-1"></div>
                <div class="relative inline-flex items-center">
                    <button type="button" class="footer-icon-btn" data-compose-more-btn title="More options" onclick="toggleComposeMoreMenu(event)">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <div id="composeMoreMenu" class="compose-tool-menu hidden" onclick="event.stopPropagation()">
                        <button type="button" class="compose-tool-menu-item" onclick="insertComposeSignature(event)"><i class="bi bi-pen"></i> Insert signature</button>
                        <button type="button" class="compose-tool-menu-item" onclick="focusComposeBody(event)"><i class="bi bi-cursor-text"></i> Focus message</button>
                    </div>
                </div>
                <div id="composeFooterStatus" class="compose-footer-status hidden" aria-live="polite" aria-atomic="true"></div>
                <button type="button" class="footer-icon-btn" title="Discard draft" onclick="clearComposeForm(event)">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
        </div>
        <input type="file" id="composeAttachments" multiple style="display: none;" onchange="handleAttachmentFiles(this)">
        <input type="file" id="composeImages" accept="image/*" multiple style="display: none;" onchange="handleAttachmentFiles(this)">
    </div>

    <script>
    const popularEmojis = [
        '😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾','👋','🤚','🖐','✋','🖖','👌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','👀','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟'
    ];

    function initEmojiPicker(pickerId, onSelect) {
        const picker = document.getElementById(pickerId);
        if (!picker) return;
        
        const grid = document.createElement('div');
        grid.className = 'emoji-grid';
        
        popularEmojis.forEach(emoji => {
            const span = document.createElement('span');
            span.className = 'emoji-item-span';
            span.innerText = emoji;
            span.addEventListener('mousedown', (e) => {
                e.preventDefault(); // prevent losing focus
                onSelect(emoji);
            });
            grid.appendChild(span);
        });
        
        picker.appendChild(grid);
    }

    function insertComposeEmoji(emoji) {
        const textarea = document.querySelector('.compose-textarea');
        if (!textarea) return;
        
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        
        textarea.value = text.substring(0, start) + emoji + text.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
        textarea.focus();

        const picker = document.getElementById('composeEmojiPicker');
        if (picker) picker.classList.add('hidden');
    }

    function hideComposeToolMenus() {
        ['composeEmojiPicker', 'composeFormatMenu', 'composeMoreMenu'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.add('hidden');
        });
    }

    function positionComposeMenu(menuEl, btn) {
        if (!menuEl || !btn) return;
        const rect = btn.getBoundingClientRect();
        menuEl.style.position = 'fixed';
        menuEl.style.top = 'auto';
        menuEl.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
        if (menuEl.id === 'composeEmojiPicker') {
            menuEl.style.left = (rect.left + rect.width / 2) + 'px';
            menuEl.style.transform = 'translateX(-50%)';
        } else {
            menuEl.style.left = Math.max(8, rect.left) + 'px';
            menuEl.style.transform = 'none';
        }
    }

    function toggleComposeEmojiPicker(e) {
        if (e) e.stopPropagation();
        const picker = document.getElementById('composeEmojiPicker');
        const btn = e?.currentTarget || document.querySelector('[data-compose-emoji-btn]');
        if (!picker || !btn) return;

        hideComposeToolMenus();
        
        if (picker.children.length === 0) {
            initEmojiPicker('composeEmojiPicker', insertComposeEmoji);
        }

        const replyPicker = document.getElementById('replyEmojiPicker');
        if (replyPicker) replyPicker.classList.add('hidden');

        const isHidden = picker.classList.contains('hidden');
        if (isHidden) {
            positionComposeMenu(picker, btn);
            picker.classList.remove('hidden');
        }
    }

    function toggleComposeFormatMenu(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('composeFormatMenu');
        const btn = e?.currentTarget || document.querySelector('[data-compose-format-btn]');
        if (!menu || !btn) return;

        const willOpen = menu.classList.contains('hidden');
        hideComposeToolMenus();
        if (willOpen) {
            positionComposeMenu(menu, btn);
            menu.classList.remove('hidden');
        }
    }

    function toggleComposeMoreMenu(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('composeMoreMenu');
        const btn = e?.currentTarget || document.querySelector('[data-compose-more-btn]');
        if (!menu || !btn) return;

        const willOpen = menu.classList.contains('hidden');
        hideComposeToolMenus();
        if (willOpen) {
            positionComposeMenu(menu, btn);
            menu.classList.remove('hidden');
        }
    }

    function getComposeTextarea() {
        return document.querySelector('.compose-textarea');
    }

    function wrapComposeSelection(before, after, placeholder) {
        const textarea = getComposeTextarea();
        if (!textarea) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        const selected = text.substring(start, end) || placeholder || 'text';
        textarea.value = text.substring(0, start) + before + selected + after + text.substring(end);
        const cursorStart = start + before.length;
        const cursorEnd = cursorStart + selected.length;
        textarea.focus();
        textarea.setSelectionRange(cursorStart, cursorEnd);
    }

    function applyComposeFormat(type) {
        hideComposeToolMenus();
        if (type === 'bold') wrapComposeSelection('**', '**', 'bold text');
        else if (type === 'italic') wrapComposeSelection('_', '_', 'italic text');
        else if (type === 'underline') wrapComposeSelection('__', '__', 'underlined text');
    }

    function insertComposeLink(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const textarea = getComposeTextarea();
        if (!textarea) return;
        const url = prompt('Enter link URL:', 'https://');
        if (!url) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.substring(start, end) || url;
        const linkText = selected.trim() || url;
        const insertion = linkText + ' (' + url + ')';
        textarea.value = textarea.value.substring(0, start) + insertion + textarea.value.substring(end);
        textarea.focus();
        textarea.setSelectionRange(start + insertion.length, start + insertion.length);
    }

    function insertComposeSignature(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const textarea = getComposeTextarea();
        if (!textarea) return;
        const signature = '\n\nBest regards,\n<?= htmlspecialchars($_SESSION['full_name'] ?? 'Team', ENT_QUOTES) ?>';
        const pos = textarea.value.length;
        textarea.value += signature;
        textarea.focus();
        textarea.setSelectionRange(pos, pos);
    }

    function focusComposeBody(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const textarea = getComposeTextarea();
        if (textarea) textarea.focus();
    }

    function clearComposeForm(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const hasContent = document.querySelector('.compose-textarea')?.value.trim()
            || document.querySelector('.subject-input')?.value.trim()
            || document.querySelectorAll('#toChips .email-chip').length > 0
            || composeFiles.length > 0;
        if (hasContent && !confirm('Discard this message?')) return;

        document.querySelectorAll('.email-chip').forEach(c => c.remove());
        const subject = document.querySelector('.subject-input');
        const body = document.querySelector('.compose-textarea');
        if (subject) subject.value = '';
        if (body) body.value = '';
        document.querySelectorAll('.chip-input').forEach(input => { input.value = ''; });
        composeFiles = [];
        renderAttachmentPreviews();
        document.getElementById('ccGroup').style.display = 'none';
        document.getElementById('bccGroup').style.display = 'none';
        hideComposeStatus();
        clearComposeFieldHighlights();
    }

    // Dismiss compose tool menus on click outside
    document.addEventListener('click', function(e) {
        const composePicker = document.getElementById('composeEmojiPicker');
        if (composePicker && !composePicker.classList.contains('hidden')) {
            if (!composePicker.contains(e.target) && !e.target.closest('[data-compose-emoji-btn]')) {
                composePicker.classList.add('hidden');
            }
        }
        const formatMenu = document.getElementById('composeFormatMenu');
        if (formatMenu && !formatMenu.classList.contains('hidden')) {
            if (!formatMenu.contains(e.target) && !e.target.closest('[data-compose-format-btn]')) {
                formatMenu.classList.add('hidden');
            }
        }
        const moreMenu = document.getElementById('composeMoreMenu');
        if (moreMenu && !moreMenu.classList.contains('hidden')) {
            if (!moreMenu.contains(e.target) && !e.target.closest('[data-compose-more-btn]')) {
                moreMenu.classList.add('hidden');
            }
        }
    });

    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        if (!sidebar) return;
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('sidebar-collapsed');
            sidebar.classList.toggle('active');
            if (typeof updateSidebarOverlay === 'function') updateSidebarOverlay();
            return;
        }
        sidebar.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem('emailSidebarCollapsed', sidebar.classList.contains('sidebar-collapsed') ? '1' : '0');
        } catch (e) {}
    }

    function applyEmailTheme(theme) {
        const isDark = theme === 'dark';
        if (isDark) {
            document.documentElement.setAttribute('data-email-theme', 'dark');
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-email-theme');
            document.documentElement.removeAttribute('data-theme');
        }
        const icon = document.getElementById('emailThemeToggleIcon');
        if (icon) {
            icon.className = isDark ? 'bi bi-sun text-2xl' : 'bi bi-moon-stars text-2xl text-black';
        }
        const toggleBtn = document.getElementById('emailThemeToggle');
        if (toggleBtn) {
            toggleBtn.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        }
        try {
            localStorage.setItem('emailTheme', isDark ? 'dark' : 'light');
        } catch (e) {}
    }

    function toggleEmailTheme() {
        const isDark = document.documentElement.getAttribute('data-email-theme') === 'dark';
        applyEmailTheme(isDark ? 'light' : 'dark');
    }

    function initEmailTheme() {
        let theme = 'light';
        try {
            theme = localStorage.getItem('emailTheme') || 'light';
        } catch (e) {}
        applyEmailTheme(theme);
    }

    document.addEventListener('DOMContentLoaded', initEmailTheme);

    function toggleCompose() {
        const modal = document.getElementById('composeModal');
        const overlay = document.getElementById('composeOverlay');
        if (modal.style.display === 'flex') {
            modal.style.display = 'none';
            overlay.style.display = 'none';
            hideComposeToolMenus();
            hideComposeStatus();
            modal.classList.remove('is-sending');
        } else {
            modal.style.display = 'flex';
            overlay.style.display = 'block';
            hideComposeStatus();
            clearComposeFieldHighlights();
        }
    }

    function toggleCcBcc(type) {
        const group = document.getElementById(type + 'Group');
        group.style.display = group.style.display === 'none' ? 'flex' : 'none';
    }

    let composeFiles = [];
    let composeStatusTimer = null;
    const COMPOSE_SEND_TIMEOUT_MS = 45000;

    function hideComposeStatus() {
        if (composeStatusTimer) {
            clearTimeout(composeStatusTimer);
            composeStatusTimer = null;
        }
        const el = document.getElementById('composeFooterStatus');
        if (el) {
            el.className = 'compose-footer-status hidden';
            el.innerHTML = '';
        }
    }

    function showComposeStatus(type, message, autoHideMs = 0) {
        const el = document.getElementById('composeFooterStatus');
        if (!el) return;

        hideComposeStatus();

        let icon = '';
        if (type === 'sending') {
            icon = '<i class="bi bi-arrow-repeat animate-spin"></i>';
        } else if (type === 'warning') {
            icon = '<i class="bi bi-exclamation-circle"></i>';
        } else if (type === 'error') {
            icon = '<i class="bi bi-x-circle"></i>';
        } else if (type === 'success') {
            icon = '<i class="bi bi-check-circle"></i>';
        }

        const dots = type === 'sending'
            ? '<span class="compose-sending-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
            : '';

        el.className = 'compose-footer-status status-' + type;
        el.innerHTML = icon + '<span>' + escapeHtml(message) + '</span>' + dots;
        el.title = message;

        if (autoHideMs > 0) {
            composeStatusTimer = setTimeout(hideComposeStatus, autoHideMs);
        }
    }

    function clearComposeFieldHighlights() {
        document.querySelectorAll('.compose-field-missing').forEach(el => el.classList.remove('compose-field-missing'));
    }

    function highlightComposeField(selector) {
        const el = document.querySelector(selector);
        if (!el) return;
        el.classList.add('compose-field-missing');
        el.focus();
        setTimeout(() => el.classList.remove('compose-field-missing'), 3500);
    }

    function validateComposeFields(toEmails, subject, body) {
        if (toEmails.length === 0) {
            return { ok: false, message: 'Add a recipient in To', field: '#toChips .chip-input' };
        }
        if (!subject.trim()) {
            return { ok: false, message: 'Subject is required', field: '.subject-input' };
        }
        if (!body.trim()) {
            return { ok: false, message: 'Message body is required', field: '.compose-textarea' };
        }
        return { ok: true };
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        return fetch(url, { ...options, signal: controller.signal })
            .finally(() => clearTimeout(timer));
    }

    function triggerAttachmentSelect(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const input = document.getElementById('composeAttachments');
        if (input) input.click();
    }

    function triggerComposeImageSelect(e) {
        if (e) e.stopPropagation();
        hideComposeToolMenus();
        const input = document.getElementById('composeImages');
        if (input) input.click();
    }

    function handleAttachmentFiles(input) {
        for (let i = 0; i < input.files.length; i++) {
            composeFiles.push(input.files[i]);
        }
        input.value = '';
        renderAttachmentPreviews();
    }

    function removeAttachment(index) {
        composeFiles.splice(index, 1);
        renderAttachmentPreviews();
    }

    function renderAttachmentPreviews() {
        const list = document.getElementById('attachmentPreviewList');
        if (composeFiles.length === 0) {
            list.classList.add('hidden');
            list.innerHTML = '';
            return;
        }

        list.classList.remove('hidden');
        list.innerHTML = '';
        composeFiles.forEach((file, index) => {
            const chip = document.createElement('div');
            chip.className = 'attachment-chip flex items-center gap-2 bg-white border border-gray-200 rounded-lg px-3 py-1.5 shadow-sm text-sm';
            chip.innerHTML = `
                <i class="bi bi-paperclip text-gray-400"></i>
                <span class="font-medium text-gray-700 truncate max-w-[180px]">${escapeHtml(file.name)}</span>
                <span class="text-xs text-gray-400">(${formatBytes(file.size)})</span>
                <button type="button" class="text-gray-400 hover:text-red-500 border-0 bg-transparent p-0 outline-none cursor-pointer flex items-center" onclick="removeAttachment(${index})">
                    <i class="bi bi-x-lg text-[10px]"></i>
                </button>
            `;
            list.appendChild(chip);
        });
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    const customerEmails = <?php echo json_encode($customer_emails); ?>;

    function handleSuggestKeydown(e, targetId) {
        const dropdown = document.getElementById(targetId + 'Suggests');
        const items = dropdown.querySelectorAll('.suggest-item');
        const activeItem = dropdown.querySelector('.suggest-item.active');
        let index = -1;
        if (activeItem) {
            index = Array.from(items).indexOf(activeItem);
        }

        if (!dropdown.classList.contains('hidden') && items.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                index = (index + 1) % items.length;
                setActiveItem(items, index);
                return;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                index = (index - 1 + items.length) % items.length;
                setActiveItem(items, index);
                return;
            } else if (e.key === 'Enter') {
                if (activeItem) {
                    e.preventDefault();
                    const email = activeItem.getAttribute('data-email');
                    const name = activeItem.getAttribute('data-name');
                    selectSuggestion({ email, company_name: name }, targetId, e.target, dropdown);
                    return;
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                hideDropdown(dropdown);
                return;
            }
        }

        // Fallback to normal chip behavior
        const input = e.target;
        const val = input.value.trim();
        
        if ((e.key === 'Enter' || e.key === ',') && val) {
            e.preventDefault();
            if (val.includes('@')) {
                addChip(val, targetId);
                input.value = '';
                hideDropdown(dropdown);
            }
        } else if (e.key === 'Backspace' && !val) {
            const container = document.getElementById(targetId + 'Chips');
            const chips = container.querySelectorAll('.email-chip');
            if (chips.length > 0) {
                chips[chips.length - 1].remove();
            }
        }
    }

    function handleSuggestInput(input, targetId) {
        const query = input.value.trim().toLowerCase();
        const dropdown = document.getElementById(targetId + 'Suggests');
        
        if (!query) {
            renderSuggestions(customerEmails.slice(0, 15), dropdown, input, targetId);
            return;
        }

        const filtered = customerEmails.filter(item => {
            const email = (item.email || '').toLowerCase();
            const name = (item.company_name || '').toLowerCase();
            return email.includes(query) || name.includes(query);
        });

        renderSuggestions(filtered.slice(0, 15), dropdown, input, targetId);
    }

    function handleSuggestFocus(input, targetId) {
        handleSuggestInput(input, targetId);
    }

    function selectSuggestion(item, targetId, input, dropdown) {
        addChip(item.email, targetId, item.company_name);
        input.value = '';
        hideDropdown(dropdown);
        input.focus();
    }

    function renderSuggestions(list, dropdown, input, targetId) {
        if (list.length === 0) {
            dropdown.classList.add('hidden');
            dropdown.innerHTML = '';
            return;
        }

        dropdown.innerHTML = '';
        dropdown.classList.remove('hidden');

        list.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'suggest-item' + (index === 0 ? ' active' : '');
            
            const name = item.company_name || '';
            const email = item.email;
            
            const initialText = (item.company_name && item.company_name.trim()) ? item.company_name : email;
            const initial = (initialText || '?').trim().charAt(0).toUpperCase();

            // Hash code generated colorful avatar
            const hue = getHashCode(email) % 360;
            const avatarBg = `hsl(${hue}, 70%, 85%)`;
            const avatarColor = `hsl(${hue}, 80%, 30%)`;

            div.setAttribute('data-email', email);
            div.setAttribute('data-name', name);

            div.innerHTML = `
                <div class="suggest-avatar" style="background: ${avatarBg}; color: ${avatarColor};">${initial}</div>
                <div class="suggest-info">
                    <span class="suggest-name">${escapeHtml(name || email.split('@')[0])}</span>
                    <span class="suggest-email">${escapeHtml(email)}</span>
                </div>
            `;

            div.addEventListener('mousedown', function(e) {
                e.preventDefault(); // prevents blur event on input
                selectSuggestion(item, targetId, input, dropdown);
            });

            dropdown.appendChild(div);
        });
    }

    function hideDropdown(dropdown) {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
    }

    function setActiveItem(items, index) {
        items.forEach(item => item.classList.remove('active'));
        if (index >= 0 && index < items.length) {
            items[index].classList.add('active');
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function getHashCode(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            hash = str.charCodeAt(i) + ((hash << 5) - hash);
        }
        return Math.abs(hash);
    }

    // Dismiss dropdowns on click outside
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.suggest-dropdown').forEach(dropdown => {
            const container = dropdown.parentElement;
            if (container && !container.contains(e.target)) {
                hideDropdown(dropdown);
            }
        });
    });

    function addChip(email, targetId, name = null) {
        if (!email.includes('@')) return; 
        const container = document.getElementById(targetId + 'Chips');
        const input = container.querySelector('.chip-input');
        const wrapper = input.parentElement;
        
        const displayText = name || email;
        const initial = displayText.charAt(0).toUpperCase();
        const chip = document.createElement('div');
        chip.className = 'email-chip';
        chip.setAttribute('data-email', email);
        chip.innerHTML = `
            <div class="chip-avatar">${initial}</div>
            <span class="chip-text" style="color: #1d4ed8;">${displayText}</span>
            <div class="chip-close" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></div>
        `;
        
        container.insertBefore(chip, wrapper);
        input.placeholder = ''; 
    }

    function setComposeSendingState(sending) {
        const modal = document.getElementById('composeModal');
        const sendBtn = document.querySelector('.btn-send');
        if (!modal) return;

        modal.querySelectorAll('.footer-icon-btn').forEach(btn => { btn.disabled = sending; });
        if (sendBtn) sendBtn.disabled = sending;

        if (sending) {
            modal.classList.add('is-sending');
            showComposeStatus('sending', 'Sending email...');
            hideComposeToolMenus();
        } else {
            modal.classList.remove('is-sending');
        }
    }

    function sendEmail() {
        const modal = document.getElementById('composeModal');
        if (modal && modal.classList.contains('is-sending')) return;

        hideComposeStatus();
        clearComposeFieldHighlights();

        // Auto-convert any pending text in input fields to chips before sending
        document.querySelectorAll('.chip-input').forEach(input => {
            const val = input.value.trim();
            const targetId = input.closest('.chip-container').id.replace('Chips', '');
            if (val && val.includes('@')) {
                addChip(val, targetId);
                input.value = '';
            }
        });

        const toEmails = Array.from(document.querySelectorAll('#toChips .email-chip')).map(c => c.getAttribute('data-email'));
        const ccEmails = Array.from(document.querySelectorAll('#ccChips .email-chip')).map(c => c.getAttribute('data-email'));
        const bccEmails = Array.from(document.querySelectorAll('#bccChips .email-chip')).map(c => c.getAttribute('data-email'));
        const subject = document.querySelector('.subject-input').value;
        const body = document.querySelector('.compose-textarea').value;

        const validation = validateComposeFields(toEmails, subject, body);
        if (!validation.ok) {
            showComposeStatus('warning', validation.message, 5000);
            if (validation.field) highlightComposeField(validation.field);
            return;
        }

        const formData = new FormData();
        formData.append('recipient_email', toEmails.join(','));
        formData.append('cc', ccEmails.join(','));
        formData.append('bcc', bccEmails.join(','));
        formData.append('subject', subject);
        formData.append('body', body);
        
        composeFiles.forEach(file => {
            formData.append('attachments[]', file);
        });

        const sendBtn = document.querySelector('.btn-send');
        const originalText = sendBtn.innerText;
        setComposeSendingState(true);
        sendBtn.innerText = 'Sending...';

        fetchWithTimeout('api/send.php', {
            method: 'POST',
            body: formData
        }, COMPOSE_SEND_TIMEOUT_MS)
        .then(async res => {
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if (!res.ok && !data.message) {
                    data.message = 'Server error (' + res.status + ')';
                    data.status = 'error';
                }
                return data;
            } catch (e) {
                console.error("Non-JSON response:", text);
                throw new Error('Server returned an invalid response.');
            }
        })
        .then(data => {
            if (data.status === 'success') {
                setComposeSendingState(false);
                showComposeStatus('success', 'Email sent!', 1800);
                sendBtn.innerText = 'Sent!';
                setTimeout(() => {
                    toggleCompose();
                    document.querySelectorAll('.email-chip').forEach(c => c.remove());
                    document.querySelector('.subject-input').value = '';
                    document.querySelector('.compose-textarea').value = '';
                    composeFiles = [];
                    renderAttachmentPreviews();
                    hideComposeStatus();
                    sendBtn.disabled = false;
                    sendBtn.innerText = originalText;
                }, 1500);
            } else {
                setComposeSendingState(false);
                showComposeStatus('error', data.message || 'Could not send email.', 7000);
                sendBtn.disabled = false;
                sendBtn.innerText = originalText;
            }
        })
        .catch(err => {
            console.error("Send Error:", err);
            setComposeSendingState(false);
            const msg = (err.name === 'AbortError')
                ? 'Send timed out. Check your connection and try again.'
                : (err.message || 'Could not reach the server.');
            showComposeStatus('error', msg, 7000);
            sendBtn.disabled = false;
            sendBtn.innerText = originalText;
        });
    }
    </script>

    <div class="flex flex-col email-main-stage w-full min-w-0">
<?php
// We don't include system headers here because we built a custom one to match the mockup exactly.
?>
