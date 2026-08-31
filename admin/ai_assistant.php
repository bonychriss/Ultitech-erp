<?php
require_once '../includes/functions.php';
require_once '../includes/ai_assistant_helper.php';
requireLogin();

// Non-admins should never be bounced to the dashboard from here.
// Gracefully route them to their own (login-only) AI Assistant instead.
if (!isAdmin()) {
    $mod = isset($_GET['module']) ? '?module=' . urlencode((string) $_GET['module']) : '';
    $employeeAi = company_url('employee/ai_assistant.php') . $mod;
    if (!headers_sent()) {
        header('Location: ' . $employeeAi);
    } else {
        echo "<script>window.location.href='" . $employeeAi . "';</script>";
    }
    exit;
}

$userId = (int) $_SESSION['user_id'];
$companyId = (int) currentCompanyId();
$role = $_SESSION['role'] ?? 'admin';

// Initialize defaults or load initial contexts
$activeTab = $_GET['tab'] ?? 'chat';
if (!in_array($activeTab, ['chat', 'reports', 'anomalies', 'growth'], true)) {
    $activeTab = 'chat';
}
$activeModule = $_GET['module'] ?? 'general';

$moduleChatTitles = [
    'sales' => 'Ask anything about orders, quotes, customers, and revenue',
    'voucher' => 'Ask anything about vouchers, payees, and approvals',
    'attendance' => 'Ask anything about shifts, lateness, and attendance records',
    'stocks' => 'Ask anything about inventory, products, and stock levels',
    'finance' => 'Ask anything about balances, payments, and cash flow',
    'accounting' => 'Ask anything about ledgers, journals, and financial reports',
    'payroll' => 'Ask anything about salaries, deductions, and payroll runs',
    'tasks' => 'Ask anything about weekly missions and team performance',
];
$chatTitle = $moduleChatTitles[$activeModule] ?? 'Ask anything about your ERP data and operations';

$modulePresets = [
    'sales' => [
        ['prompt' => 'Summarize this week sales performance and highlight top products', 'icon' => 'bar-chart-line', 'color' => 'primary', 'label' => 'Sales Summary'],
        ['prompt' => 'Which customers have overdue invoices or unpaid orders?', 'icon' => 'person-exclamation', 'color' => 'warning', 'label' => 'Overdue Accounts'],
        ['prompt' => 'Forecast next month revenue based on recent order trends', 'icon' => 'graph-up', 'color' => 'success', 'label' => 'Revenue Forecast'],
        ['prompt' => 'What quotes are still pending conversion to orders?', 'icon' => 'file-earmark-text', 'color' => 'info', 'label' => 'Pending Quotes'],
    ],
    'voucher' => [
        ['prompt' => 'Scan the system for voucher anomalies', 'icon' => 'shield-alert', 'color' => 'danger', 'label' => 'Scan Anomalies'],
        ['prompt' => 'Summarize pending and rejected vouchers this month', 'icon' => 'ticket-detailed', 'color' => 'primary', 'label' => 'Voucher Status'],
        ['prompt' => 'Which payees have the highest voucher totals?', 'icon' => 'people', 'color' => 'warning', 'label' => 'Top Payees'],
        ['prompt' => 'Explain common voucher approval bottlenecks', 'icon' => 'clock-history', 'color' => 'info', 'label' => 'Approval Tips'],
    ],
];
$defaultPresets = [
    ['prompt' => 'Scan the system for voucher anomalies', 'icon' => 'shield-alert', 'color' => 'danger', 'label' => 'Scan Anomalies'],
    ['prompt' => 'Provide a sales and revenue growth prediction', 'icon' => 'graph-up', 'color' => 'success', 'label' => 'Forecast Sales'],
    ['prompt' => 'Explain department performance leaderboard', 'icon' => 'trophy', 'color' => 'warning', 'label' => 'Performance Review'],
    ['prompt' => 'Summarize my team attendance patterns', 'icon' => 'calendar-check', 'color' => 'info', 'label' => 'Attendance Patterns'],
];
$presetPills = $modulePresets[$activeModule] ?? $defaultPresets;

$apiConfig = ai_settings_for_api();
$aiEnabled = $apiConfig['is_enabled'];

// Retrieve recent chats
$recentChats = [];
try {
    if (tableExists('ai_logs', $pdo)) {
        $stmt = $pdo->prepare("SELECT id, question, response, created_at FROM ai_logs WHERE user_id = ? AND company_id = ? ORDER BY created_at DESC LIMIT 6");
        $stmt->execute([$userId, $companyId]);
        $recentChats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    // Fail silently
}

// Fast API dispatcher for AJAX queries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$aiEnabled) {
        echo json_encode(['success' => false, 'error' => 'AI Assistant is currently disabled. Please enable it in the Control Center settings.']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    $params = $_POST['params'] ?? [];
    
    try {
        $response = ai_assistant_handle_action($pdo, $userId, $companyId, $role, $action, $params);
        echo json_encode($response);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Intelligence Hub - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= app_url('/assets/css/style.css') ?>?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --ai-primary: #6366f1;
            --ai-primary-glow: rgba(99, 102, 241, 0.15);
            --ai-secondary: #a855f7;
            --ai-bg-glass: rgba(255, 255, 255, 0.85);
            --ai-border: rgba(229, 231, 235, 0.8);
            --ai-dark-text: #0f172a;
            --ai-muted-text: #64748b;
        }

        body.dashboard {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--ai-dark-text);
        }

        .ai-title-section {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--ai-border);
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ai-heading {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            background: linear-gradient(135deg, var(--ai-primary) 0%, var(--ai-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 26px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ai-card {
            background: var(--ai-bg-glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--ai-border);
            border-radius: 16px;
            box-shadow: 0 10px 35px -10px rgba(0, 0, 0, 0.05);
            padding: 24px;
            height: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ai-card:hover {
            box-shadow: 0 15px 40px -5px rgba(99, 102, 241, 0.08);
            border-color: rgba(99, 102, 241, 0.3);
        }

        .ai-nav-pills {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            background: rgba(241, 245, 249, 0.8);
            padding: 6px;
            border-radius: 12px;
            border: 1px solid var(--ai-border);
        }

        .ai-nav-pills .nav-link {
            flex: 1;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 16px;
            color: var(--ai-muted-text);
            border: none;
            background: transparent;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .ai-nav-pills .nav-link.active,
        .ai-nav-pills .nav-link:hover {
            background: #fff;
            color: var(--ai-primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .ai-nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--ai-primary) 0%, var(--ai-secondary) 100%);
            color: #fff !important;
        }

        /* Chat Layout */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 520px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            border: 1px solid var(--ai-border);
            margin-bottom: 16px;
        }

        .message-bubble {
            max-width: 80%;
            padding: 14px 18px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
            position: relative;
            animation: bubbleIn 0.3s ease-out;
        }

        @keyframes bubbleIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-bubble.assistant {
            align-self: flex-start;
            background: #ffffff;
            color: var(--ai-dark-text);
            border: 1px solid var(--ai-border);
            border-top-left-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
        }

        .message-bubble.user {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--ai-primary) 0%, var(--ai-secondary) 100%);
            color: #ffffff;
            border-top-right-radius: 4px;
            box-shadow: 0 4px 12px var(--ai-primary-glow);
        }

        .chat-input-wrap {
            display: flex;
            gap: 10px;
        }

        .chat-input {
            flex: 1;
            border-radius: 10px;
            border: 1px solid var(--ai-border);
            padding: 12px 16px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .chat-input:focus {
            border-color: var(--ai-primary);
            box-shadow: 0 0 0 3px var(--ai-primary-glow);
        }

        .btn-ai-send {
            background: linear-gradient(135deg, var(--ai-primary) 0%, var(--ai-secondary) 100%);
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 0 20px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-ai-send:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        /* Preset actions */
        .preset-pill {
            background: #fff;
            border: 1px solid var(--ai-border);
            color: var(--ai-muted-text);
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .preset-pill:hover {
            border-color: var(--ai-primary);
            color: var(--ai-primary);
            background: var(--ai-primary-glow);
        }

        .anomalies-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .anomaly-item {
            padding: 16px;
            border-radius: 12px;
            background: rgba(254, 242, 242, 0.6);
            border: 1px solid rgba(252, 165, 165, 0.4);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .anomaly-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .anomaly-icon {
            color: #ef4444;
            font-size: 20px;
        }

        .anomaly-meta {
            font-size: 13px;
            color: var(--ai-muted-text);
        }

        .analysis-output-box {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--ai-border);
            padding: 20px;
            min-height: 200px;
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
            color: var(--ai-dark-text);
        }

        .growth-trend-box {
            display: flex;
            align-items: center;
            justify-content: space-around;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(168, 85, 247, 0.05) 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--ai-border);
        }

        .trend-stat {
            text-align: center;
        }

        .trend-stat-val {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--ai-primary);
        }

        .trend-stat-lbl {
            font-size: 12px;
            color: var(--ai-muted-text);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .status-badge-ai {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge-ai.enabled { background: #d1fae5; color: #065f46; }
        .status-badge-ai.disabled { background: #fee2e2; color: #991b1b; }

        /* WhatsApp PC Split Layout */
        .whatsapp-layout {
            display: flex;
            background: #ffffff;
            border: 1px solid var(--ai-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.05);
            height: 650px;
            margin-bottom: 30px;
        }

        .whatsapp-sidebar {
            width: 330px;
            border-right: 1px solid var(--ai-border);
            display: flex;
            flex-direction: column;
            background: #f8fafc;
            flex-shrink: 0;
        }

        .sidebar-search {
            padding: 16px;
            border-bottom: 1px solid var(--ai-border);
            background: #ffffff;
        }
        
        .sidebar-search .input-group {
            border: 1px solid var(--ai-border);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .sidebar-search .input-group-text {
            background: #f8fafc;
            border: none;
            color: var(--ai-muted-text);
        }
        
        .sidebar-search .form-control {
            border: none;
            background: #f8fafc;
            font-size: 13px;
        }
        
        .sidebar-search .form-control:focus {
            box-shadow: none;
            background: #ffffff;
        }

        .thread-list {
            flex: 1;
            overflow-y: auto;
        }

        .thread-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            cursor: pointer;
            border-bottom: 1px solid rgba(229, 231, 235, 0.4);
            transition: all 0.2s ease;
        }

        .thread-item:hover {
            background: rgba(99, 102, 241, 0.04);
        }

        .thread-item.active {
            background: rgba(99, 102, 241, 0.08);
            border-left: 4px solid var(--ai-primary);
        }

        .thread-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .thread-details {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .thread-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .thread-name {
            font-weight: 700;
            font-size: 13px;
            color: var(--ai-dark-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .thread-time {
            font-size: 10px;
            color: var(--ai-muted-text);
            font-weight: 500;
        }

        .thread-preview {
            font-size: 11px;
            color: var(--ai-muted-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .whatsapp-chat-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-width: 0;
            height: 100%;
            overflow: hidden;
        }
        
        .whatsapp-chat-panel .ai-card {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            height: 100% !important;
            padding: 24px !important;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .whatsapp-chat-panel .chat-container {
            height: 100% !important;
            flex: 1;
        }

        /* Mobile toolbar (threads ↔ chat toggle) */
        .ai-mobile-topbar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--ai-border);
            background: #f8fafc;
            flex-shrink: 0;
        }

        .ai-mobile-topbar-title {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--ai-dark-text);
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ai-mobile-back-btn {
            color: var(--ai-primary);
            text-decoration: none;
            padding: 4px 8px;
            border: none;
            background: transparent;
            font-size: 20px;
            line-height: 1;
            flex-shrink: 0;
        }

        .preset-pills-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        @media (max-width: 991.98px) {
            body.page-ai-assistant {
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            body.page-ai-assistant .layout-main-wrapper {
                min-height: auto !important;
            }

            body.page-ai-assistant .main-content {
                padding: 12px 12px 96px !important;
                overflow: visible;
            }

            body.page-ai-assistant .ai-title-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                padding: 14px 16px;
                margin-bottom: 12px;
                border-radius: 12px;
            }

            body.page-ai-assistant .ai-heading {
                font-size: 18px;
                flex-wrap: wrap;
                line-height: 1.3;
            }

            body.page-ai-assistant .ai-heading .badge {
                display: none !important; /* Hide module context badge on mobile */
            }

            body.page-ai-assistant .ai-title-section p {
                font-size: 12px !important;
            }

            body.page-ai-assistant .whatsapp-layout {
                position: relative;
                flex-direction: column;
                height: auto;
                min-height: calc(100dvh - 200px);
                max-height: none;
                margin-bottom: 12px;
                border-radius: 12px;
                overflow: visible;
            }

            body.page-ai-assistant .whatsapp-sidebar,
            body.page-ai-assistant .whatsapp-chat-panel {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                min-height: calc(100dvh - 200px);
                transition: transform 0.25s ease, opacity 0.2s ease;
            }

            body.page-ai-assistant .whatsapp-sidebar {
                border-right: none;
                z-index: 2;
                transform: translateX(-100%);
                opacity: 0;
                pointer-events: none;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            body.page-ai-assistant .whatsapp-sidebar.is-mobile-active {
                transform: translateX(0);
                opacity: 1;
                pointer-events: auto;
            }

            body.page-ai-assistant .whatsapp-chat-panel {
                z-index: 1;
                transform: translateX(0);
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            body.page-ai-assistant .whatsapp-chat-panel.is-mobile-hidden {
                transform: translateX(100%);
                opacity: 0;
                pointer-events: none;
            }

            body.page-ai-assistant .ai-mobile-topbar {
                display: flex;
            }

            body.page-ai-assistant #tab-chat {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
            }

            body.page-ai-assistant .whatsapp-chat-panel .ai-card {
                padding: 0 !important;
                height: 100% !important;
                min-height: 0;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            body.page-ai-assistant .whatsapp-chat-panel .ai-card > h3 {
                display: none;
            }

            body.page-ai-assistant .chat-container {
                flex: 1;
                min-height: 0;
                height: auto !important;
                display: flex;
                flex-direction: column;
            }

            body.page-ai-assistant .chat-messages {
                flex: 1;
                min-height: 120px;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                padding: 12px;
                margin-bottom: 0;
            }

            body.page-ai-assistant .message-bubble {
                max-width: 90%;
                padding: 12px 14px;
                font-size: 13px;
            }

            body.page-ai-assistant .preset-pills-wrap {
                flex-shrink: 0;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 8px 12px;
                margin-bottom: 0 !important;
            }

            body.page-ai-assistant .preset-pills-wrap::-webkit-scrollbar {
                display: none;
            }

            body.page-ai-assistant .preset-pill {
                flex-shrink: 0;
                font-size: 11px;
                padding: 7px 12px;
            }

            body.page-ai-assistant .chat-input-wrap {
                flex-shrink: 0;
                /* Keep input visible like the mobile chat UI. */
                padding: 10px 12px;
                gap: 8px;
                background: #fff;
                border-top: 1px solid var(--ai-border);
            }

            body.page-ai-assistant .chat-input {
                font-size: 16px;
                padding: 11px 14px;
            }

            body.page-ai-assistant .btn-ai-send {
                padding: 0 16px;
                min-width: 72px;
                flex-shrink: 0;
            }

            body.page-ai-assistant .growth-trend-box {
                flex-direction: column;
                gap: 14px;
                padding: 16px;
            }

            body.page-ai-assistant .trend-stat-val {
                font-size: 24px;
            }

            body.page-ai-assistant .anomaly-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            body.page-ai-assistant .main-content {
                padding-bottom: 104px !important;
            }

            body.page-ai-assistant .whatsapp-layout,
            body.page-ai-assistant .whatsapp-sidebar,
            body.page-ai-assistant .whatsapp-chat-panel {
                min-height: calc(100dvh - 170px);
            }

            body.page-ai-assistant .ai-title-section {
                padding: 12px 14px;
            }

            body.page-ai-assistant .ai-heading {
                font-size: 16px;
            }

            body.page-ai-assistant .status-badge-ai {
                font-size: 11px;
            }

            body.page-ai-assistant .chat-input-wrap {
                padding-bottom: 10px; /* avoid pushing the Send button off-screen */
            }
        }
    </style>
</head>
<body class="dashboard page-ai-assistant">
    <?php require_once '../includes/header_admin.php'; ?>
        
        <main class="main-content">
            <div class="ai-title-section">
                <div>
                    <h1 class="ai-heading">
                        <i class="bi bi-stars"></i> Ultimate AI Intelligence Hub
                        <?php if ($activeModule !== 'general'): ?>
                            <span class="badge bg-primary ms-2" style="font-size: 12px; vertical-align: middle;"><?= htmlspecialchars(ucfirst($activeModule)) ?> Module Context</span>
                        <?php endif; ?>
                    </h1>
                    <p class="mb-0 mt-1 text-muted" style="font-size: 14px;">
                        Interactive role-based metrics analyzer, anomaly detection suite, and growth forecaster.
                    </p>
                </div>
                <div>
                    <?php if ($aiEnabled): ?>
                        <span class="status-badge-ai enabled"><i class="bi bi-shield-check"></i> AI Enabled</span>
                    <?php else: ?>
                        <span class="status-badge-ai disabled"><i class="bi bi-shield-slash"></i> AI Disabled</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="whatsapp-layout" id="whatsappLayout">
                <!-- Left Thread List Sidebar -->
                <div class="whatsapp-sidebar" id="whatsappSidebar">
                    <div class="p-3 border-bottom bg-white d-flex align-items-center justify-content-between gap-2">
                        <button type="button" class="ai-mobile-back-btn d-lg-none" onclick="showMobileChat()" aria-label="Back to chat">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <span class="fw-bold text-muted flex-grow-1" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Recent Chats</span>
                        <button class="btn btn-sm btn-outline-primary py-1 px-2" onclick="resetToNewChat()" style="font-size: 11px; font-weight: 600; border-radius: 6px; flex-shrink: 0;">
                            <i class="bi bi-plus-lg"></i> New Chat
                        </button>
                    </div>
                    <div class="thread-list">
                        <?php if (!empty($recentChats)): ?>
                            <div class="px-3 py-2 text-muted fw-bold border-bottom" style="font-size: 11px; text-transform: uppercase; background: #f8fafc; letter-spacing: 0.5px;">Recent Chats</div>
                            <?php foreach ($recentChats as $chat): 
                                $previewQ = htmlspecialchars(substr($chat['question'], 0, 30) . (strlen($chat['question']) > 30 ? '...' : ''));
                                $fullQ = htmlspecialchars($chat['question'], ENT_QUOTES);
                                $fullR = htmlspecialchars($chat['response'], ENT_QUOTES);
                                $chatTime = date('H:i', strtotime($chat['created_at']));
                            ?>
                                <div class="thread-item recent-chat-item" onclick="loadRecentChat('<?= $fullQ ?>', '<?= $fullR ?>', this)" data-chat-id="<?= $chat['id'] ?>">
                                    <div class="thread-icon bg-secondary-subtle text-secondary">
                                        <i class="bi bi-chat-dots-fill"></i>
                                    </div>
                                    <div class="thread-details">
                                        <div class="thread-meta">
                                            <span class="thread-name"><?= $previewQ ?></span>
                                            <span class="thread-time"><?= $chatTime ?></span>
                                        </div>
                                        <span class="thread-preview">View conversation</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Chat Panel -->
                <div class="whatsapp-chat-panel is-mobile-active" id="whatsappChatPanel">
                    
                    <!-- TAB 1: INTERACTIVE CHAT -->
                    <div id="tab-chat" class="ai-tab-content <?= $activeTab === 'chat' ? '' : 'd-none' ?> h-100">
                        <div class="ai-card">
                            <div class="ai-mobile-topbar">
                                <button type="button" class="ai-mobile-back-btn" id="btnMobileBack" onclick="showMobileChat()" aria-label="Back to chat" style="visibility:hidden;">
                                    <i class="bi bi-arrow-left"></i>
                                </button>
                                <span class="ai-mobile-topbar-title">
                                    <i class="bi bi-stars text-primary"></i>
                                    <?= $activeModule !== 'general' ? htmlspecialchars(ucfirst($activeModule)) . ' AI' : 'AI Chat' ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2" onclick="showMobileThreads()" style="font-size:11px;font-weight:600;border-radius:6px;flex-shrink:0;">
                                    <i class="bi bi-clock-history"></i> History
                                </button>
                            </div>
                            <h3 style="font-family:'Outfit'; font-size:18px; margin-bottom:16px; padding: 0 24px;">
                                <i class="bi bi-chat-quote-fill text-primary"></i> <?= htmlspecialchars($chatTitle) ?>
                            </h3>
                            
                            <div class="chat-container">
                                <div class="chat-messages" id="chatMessages">
                                    <div class="message-bubble assistant">
                                        Hello <?= htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]) ?>! What can I do for you today? Describe your ideas...
                                    </div>
                                    <?php if (isset($_GET['kpi'])): ?>
                                        <div class="message-bubble user">
                                            Explain KPI: <?= htmlspecialchars($_GET['kpi']) ?> (Value: <?= htmlspecialchars($_GET['val'] ?? '-') ?>)
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3 preset-pills-wrap">
                                    <?php foreach ($presetPills as $pill): ?>
                                        <span class="preset-pill" onclick="sendPreset(<?= json_encode($pill['prompt'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                                            <i class="bi bi-<?= htmlspecialchars($pill['icon']) ?> text-<?= htmlspecialchars($pill['color']) ?>"></i>
                                            <?= htmlspecialchars($pill['label']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <div class="chat-input-wrap">
                                    <input type="text" class="chat-input" id="chatInput" placeholder="Type a message..." aria-label="AI Question">
                                    <button class="btn btn-ai-send" id="btnSendChat" onclick="sendChatMessage()">
                                        Send <i class="bi bi-send-fill ms-1"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional tabs moved to Performance Module AI Assistant -->
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    function isMobileAiLayout() {
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function showMobileThreads() {
        if (!isMobileAiLayout()) return;
        const sidebar = document.getElementById('whatsappSidebar');
        const panel = document.getElementById('whatsappChatPanel');
        if (sidebar) sidebar.classList.add('is-mobile-active');
        if (panel) panel.classList.add('is-mobile-hidden');
    }

    function showMobileChat() {
        if (!isMobileAiLayout()) return;
        const sidebar = document.getElementById('whatsappSidebar');
        const panel = document.getElementById('whatsappChatPanel');
        if (sidebar) sidebar.classList.remove('is-mobile-active');
        if (panel) {
            panel.classList.remove('is-mobile-hidden');
            panel.classList.add('is-mobile-active');
        }
    }

    function initMobileAiLayout() {
        const sidebar = document.getElementById('whatsappSidebar');
        const panel = document.getElementById('whatsappChatPanel');
        if (!sidebar || !panel) return;

        if (isMobileAiLayout()) {
            sidebar.classList.remove('is-mobile-active');
            panel.classList.remove('is-mobile-hidden');
            panel.classList.add('is-mobile-active');
        } else {
            sidebar.classList.remove('is-mobile-active');
            panel.classList.remove('is-mobile-hidden', 'is-mobile-active');
        }
    }

    function switchTab(tabId) {
        document.querySelectorAll('.ai-tab-content').forEach(el => el.classList.add('d-none'));
        document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
        
        document.getElementById('tab-' + tabId).classList.remove('d-none');
        const activeThread = document.getElementById('thread-' + tabId);
        if (activeThread) {
            activeThread.classList.add('active');
        }
        
        if (tabId === 'chat') {
            resetChatMessages();
        }
        
        // Push state
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, document.title, url.toString());
    }

    function switchTabDirect(tabId) {
        document.querySelectorAll('.ai-tab-content').forEach(el => el.classList.add('d-none'));
        document.querySelectorAll('.thread-item').forEach(btn => {
            btn.classList.remove('active');
            if (btn.id === 'thread-' + tabId) {
                btn.classList.add('active');
            }
        });
        const tabEl = document.getElementById('tab-' + tabId);
        if (tabEl) {
            tabEl.classList.remove('d-none');
        }
    }

    function filterSidebarThreads() {
        const query = document.getElementById('sidebarSearchInput').value.toLowerCase();
        document.querySelectorAll('.thread-item').forEach(item => {
            const name = item.querySelector('.thread-name').textContent.toLowerCase();
            const preview = item.querySelector('.thread-preview').textContent.toLowerCase();
            if (name.includes(query) || preview.includes(query)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function loadRecentChat(question, response, el) {
        // Switch tab to 'chat' without resetting
        document.querySelectorAll('.ai-tab-content').forEach(el => el.classList.add('d-none'));
        document.getElementById('tab-chat').classList.remove('d-none');
        
        // Set active thread list selection
        document.querySelectorAll('.thread-item').forEach(item => item.classList.remove('active'));
        el.classList.add('active');
        
        // Populate chat window
        const wrap = document.getElementById('chatMessages');
        wrap.innerHTML = `
            <div class="message-bubble assistant">
                Hello <?= htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]) ?>! What can I do for you today? Describe your ideas...
            </div>
            <div class="message-bubble user">
                ${question}
            </div>
            <div class="message-bubble assistant">
                ${response}
            </div>
        `;
        wrap.scrollTop = wrap.scrollHeight;
        showMobileChat();
    }

    function resetChatMessages() {
        const wrap = document.getElementById('chatMessages');
        wrap.innerHTML = `
            <div class="message-bubble assistant">
                Hello <?= htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]) ?>! What can I do for you today? Describe your ideas...
            </div>
        `;
        wrap.scrollTop = wrap.scrollHeight;
    }
    function resetToNewChat() {
        document.querySelectorAll('.thread-item').forEach(item => item.classList.remove('active'));
        resetChatMessages();
        showMobileChat();
    }

    // Interactive Chat Logic
    function sendChatMessage() {
        const inp = document.getElementById('chatInput');
        const text = inp.value.trim();
        if (!text) return;
        
        inp.value = '';
        appendMessage('user', text);
        
        const loader = appendMessage('assistant', '<span class="spinner-border spinner-border-sm"></span> Thinking...');
        
        // Call backend API
        const formData = new FormData();
        formData.append('ajax_action', 'explain_kpi');
        formData.append('params[kpi]', 'User Question');
        formData.append('params[value]', text);
        
        // Add active module parameter from URL search parameters
        const urlParams = new URLSearchParams(window.location.search);
        const activeModule = urlParams.get('module') || 'general';
        formData.append('params[active_module]', activeModule);
        
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            loader.remove();
            if (data.success) {
                appendMessage('assistant', data.analysis);
            } else {
                appendMessage('assistant', 'Error: ' + data.error);
            }
        })
        .catch(err => {
            loader.remove();
            appendMessage('assistant', 'Offline / Connection error occurred.');
        });
    }

    function sendPreset(promptText) {
        document.getElementById('chatInput').value = promptText;
        sendChatMessage();
    }

    function appendMessage(role, content) {
        const wrap = document.getElementById('chatMessages');
        const el = document.createElement('div');
        el.className = 'message-bubble ' + role;
        el.innerHTML = content;
        wrap.appendChild(el);
        wrap.scrollTop = wrap.scrollHeight;
        return el;
    }

    // Reports Logic
    function generateReport(moduleName) {
        const out = document.getElementById('reportsOutput');
        out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Compiling records and executing AI report builder...';
        
        const formData = new FormData();
        formData.append('ajax_action', 'module_report');
        formData.append('params[module]', moduleName);
        
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                out.textContent = data.analysis;
            } else {
                out.textContent = 'Error: ' + data.error;
            }
        })
        .catch(err => {
            out.textContent = 'Connection error.';
        });
    }

    // Integrity Scan Logic
    function runIntegrityScan() {
        const list = document.getElementById('anomaliesList');
        const out = document.getElementById('anomaliesOutput');
        
        list.innerHTML = '<li><span class="spinner-border spinner-border-sm"></span> Auditing voucher and lateness records...</li>';
        out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Evaluation queued...';
        
        const formData = new FormData();
        formData.append('ajax_action', 'scan_errors');
        
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                out.textContent = data.analysis;
                
                // Render anomaly list items
                let itemsHtml = '';
                const anom = data.anomalies;
                
                let foundAny = false;
                
                if (anom.paid_unapproved.length > 0) {
                    foundAny = true;
                    anom.paid_unapproved.forEach(v => {
                        itemsHtml += `
                            <li class="anomaly-item">
                                <div class="anomaly-info">
                                    <i class="bi bi-exclamation-triangle-fill anomaly-icon"></i>
                                    <div>
                                        <strong>Paid Before Approval</strong>
                                        <span class="anomaly-meta d-block">Voucher ${v.voucher_no} is marked paid, but has status '${v.status}' (Value: ${v.currency} ${v.total_amount})</span>
                                    </div>
                                </div>
                            </li>
                        `;
                    });
                }
                
                if (anom.duplicates.length > 0) {
                    foundAny = true;
                    anom.duplicates.forEach(d => {
                        itemsHtml += `
                            <li class="anomaly-item">
                                <div class="anomaly-info">
                                    <i class="bi bi-layers-half anomaly-icon"></i>
                                    <div>
                                        <strong>Duplicate Detected</strong>
                                        <span class="anomaly-meta d-block">Vouchers ${d.voucher_a_no} and ${d.voucher_b_no} created to payee '${d.payee_name}' within 24h</span>
                                    </div>
                                </div>
                            </li>
                        `;
                    });
                }

                if (anom.attendance_geofence.length > 0) {
                    foundAny = true;
                    anom.attendance_geofence.forEach(a => {
                        itemsHtml += `
                            <li class="anomaly-item" style="background:#fffbeb; border-color:#fde047;">
                                <div class="anomaly-info">
                                    <i class="bi bi-geo-alt-fill text-warning fs-5"></i>
                                    <div>
                                        <strong>Geofence Exception</strong>
                                        <span class="anomaly-meta d-block">${a.full_name} signed in at ${a.time_in} (Status: ${a.status}, IP: ${a.ip_address})</span>
                                    </div>
                                </div>
                            </li>
                        `;
                    });
                }
                
                if (!foundAny) {
                    itemsHtml = `
                        <li class="anomaly-item" style="background:#ecfdf5; border-color:#a7f3d0; color:#065f46;">
                            <div class="anomaly-info">
                                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <div>
                                    <strong>All Clear</strong>
                                    <span class="anomaly-meta d-block" style="color:#047857;">No duplicate vouchers, unapproved paid items, or geofence exceptions found.</span>
                                </div>
                            </div>
                        </li>
                    `;
                }
                
                list.innerHTML = itemsHtml;
            } else {
                list.innerHTML = '<li>Error loading results.</li>';
                out.textContent = data.error;
            }
        })
        .catch(err => {
            list.innerHTML = '<li>Connection failure.</li>';
            out.textContent = 'Connection error.';
        });
    }

    // Growth Forecaster Logic
    function generateGrowthForecast() {
        const out = document.getElementById('growthOutput');
        out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Compiling sales matrix and forecasting regression values...';
        
        const formData = new FormData();
        formData.append('ajax_action', 'predict_growth');
        
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                out.textContent = data.analysis;
                
                // Update KPI totals
                const f = data.forecast;
                document.getElementById('forecastVal').textContent = 'TZS ' + Math.round(f.forecast_value).toLocaleString();
                document.getElementById('growthRate').textContent = (f.growth_rate >= 0 ? '+' : '') + f.growth_rate.toFixed(1) + '%';
                document.getElementById('slopeVal').textContent = f.slope.toFixed(0);
            } else {
                out.textContent = 'Error: ' + data.error;
            }
        })
        .catch(err => {
            out.textContent = 'Connection error.';
        });
    }

    window.addEventListener('resize', initMobileAiLayout);

    // Trigger pre-loaded queries if requested by KPI click
    document.addEventListener("DOMContentLoaded", function() {
        initMobileAiLayout();

        const urlParams = new URLSearchParams(window.location.search);
        const kpi = urlParams.get('kpi');
        const val = urlParams.get('val');
        if (kpi) {
            switchTabDirect('chat');
            // Execute explanation query automatically
            const text = "Explain the KPI: " + kpi + " which currently holds the value " + val;
            document.getElementById('chatInput').value = text;
            sendChatMessage();
        }
    });

    document.getElementById('chatInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            sendChatMessage();
        }
    });
</script>
</body>
</html>
