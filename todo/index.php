<?php
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'todo';
}

try {
    requireLogin();
} catch (Throwable $e) {
    error_log('todo/index.php requireLogin: ' . $e->getMessage());
    http_response_code(500);
    $msg = 'Unable to load To Do List. Diagnostic Info: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    die($msg . str_repeat(' ', 512));
}

$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'employee';
$isAdmin = ($userRole === 'admin' || (function_exists('isAdmin') && isAdmin()));

$companyName = $_SESSION['company_name'] ?? 'Ultimate General Trading';
try {
    if (function_exists('getCompanyInfo')) {
        $companyInfo = getCompanyInfo();
        if (!empty($companyInfo['company_name'])) {
            $companyName = $companyInfo['company_name'];
        }
    }
} catch (Throwable $e) {
    error_log('todo/index.php company info: ' . $e->getMessage());
}

$weekStart = date('Y-m-d');
$weekEnd = date('Y-m-d');
try {
    require_once __DIR__ . '/includes/weekly_mission_helpers.php';
    $weekBounds = wm_get_week_bounds();
    $weekStart = $weekBounds['week_start'];
    $weekEnd = $weekBounds['week_end'];
} catch (Throwable $e) {
    error_log('todo/index.php week bounds: ' . $e->getMessage());
}
$weekLabel = sprintf(
    'This Week (%d - %s)',
    (int) date('j', strtotime($weekStart)),
    date('j M Y', strtotime($weekEnd))
);

$employeeHeaderTitle = null;
$employeeHeaderSubtitle = null;
$page_title = 'To Do List';

$weeklyMissionQs = ['module' => 'todo'];
if (!empty($_GET['company_slug'])) {
    $weeklyMissionQs['company_slug'] = (string) $_GET['company_slug'];
}
$weeklyMissionUrl = 'weekly_mission.php?' . http_build_query($weeklyMissionQs);

// Standard app shell (sidebar + top bar + main column) — same pattern as stock/dashboard.php
$rootPath = '../';
$logoBase = '../';
$headerPath = $isAdmin
    ? __DIR__ . '/../includes/header_admin.php'
    : __DIR__ . '/../includes/header_employee.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Do List - Ultimate General Trading</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/todo-my-tasks.css') : '../assets/css/todo-my-tasks.css') ?>?v=<?= time() ?>">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Modern Pickers & Alerts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary-accent: #94a3ff;
            --primary-gradient: linear-gradient(135deg, #94a3ff 0%, #7f9eff 100%);
            --primary-hover: #7f9eff;
            --bg-main: #fcfdfe; /* Soft page background */
            --form-bg: #ffffff;
            --item-border: #f1f5f9;
            --input-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --shadow-sm: 0 4px 6px -1px rgb(0 0 0 / 0.05);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.08);
        }

        /* --- Legacy task card (modal only contexts) --- */
        .task-card {
            background: var(--form-bg);
            padding: 16px 18px;
            border-radius: 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            border: 1px solid var(--item-border);
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            position: relative;
        }

        .task-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            transform: translateY(-1px);
            border-color: #e2e8f0;
        }

        .task-check {
            width: 26px;
            height: 26px;
            border-radius: 10px;
            border: 2px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            transition: all 0.2s;
            flex-shrink: 0;
            cursor: pointer;
            margin-top: 2px;
            background: #fff;
        }

        .task-card.completed .task-check {
            background: #22c55e;
            border-color: #22c55e;
        }

        .task-card.completed .task-text {
            color: #94a3b8;
            text-decoration: line-through;
        }

        .task-content {
            flex: 1;
            min-width: 0;
        }

        .task-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }

        .task-text {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.35;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .task-desc {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
            line-height: 1.45;
        }

        .task-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
            font-size: 11px;
            color: #94a3b8;
            font-weight: 500;
        }

        .task-footer span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .task-footer i { font-size: 11px; opacity: 0.85; }

        .task-remind-icon { color: #8b5cf6; font-size: 12px; }

        .task-menu-wrap { position: relative; flex-shrink: 0; }
        .task-menu-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        .task-menu-btn:hover { background: #f1f5f9; color: #475569; }
        .task-menu-panel {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 4px;
            min-width: 140px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            z-index: 100;
            overflow: hidden;
            text-align: left;
        }
        .task-menu-panel.open { display: block; }
        .task-menu-panel button {
            width: 100%;
            padding: 10px 14px;
            border: none;
            background: none;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        .task-menu-panel button:hover { background: #f8fafc; }
        .task-menu-panel button.danger { color: #ef4444; }
        .task-menu-panel button.danger:hover { background: #fef2f2; }

        /* --- Quick Reminder Presets --- */
        .preset-btn {
            background: #f8fafc;
            color: var(--text-muted);
            border: 1px solid var(--item-border);
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
            text-align: center;
        }

        .preset-btn:hover {
            background: rgba(148, 163, 255, 0.1);
            color: var(--primary-accent);
            border-color: var(--primary-accent);
            transform: translateY(-1px);
        }

        /* --- Floating Button --- */
        .fab-container {
            position: fixed;
            bottom: 32px;
            right: 32px;
            display: flex;
            flex-direction: column-reverse;
            align-items: center;
            gap: 12px;
            z-index: 2000;
        }

        .fab {
            background: linear-gradient(135deg, #4f6ef7 0%, #9b4ef7 100%);
            color: #ffffff;
            width: 68px;
            height: 68px;
            border-radius: 50% !important;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: 0 10px 30px rgba(79, 110, 247, 0.4);
            cursor: pointer;
            border: none;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .fab:hover {
            transform: scale(1.1);
            box-shadow: 0 15px 40px rgba(79, 110, 247, 0.5);
        }

        .help-btn {
            background: #ffffff;
            color: #0f172a;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            cursor: pointer;
            border: 1px solid #f1f5f9;
            transition: all 0.2s;
            margin-right: -10px; /* Offset for premium feel */
        }

        .help-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }

        /* --- Add Task modal (scoped; avoids Bootstrap .modal-content) --- */
        #taskModal.todo-task-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            z-index: 10200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        #taskModal .todo-task-modal-sheet {
            background: #ffffff !important;
            border-radius: 32px;
            width: 100%;
            max-width: 440px;
            padding: 35px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.2);
            border: 1px solid #f1f5f9;
            animation: modalPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            flex-shrink: 0;
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            font-size: 24px;
            font-weight: 800;
            margin: 0;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .modal-body label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #475569;
        }

        .modal-body input, 
        .modal-body textarea,
        .modal-body select {
            width: 100%;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: all 0.2s;
            color: var(--text-main);
            font-family: inherit;
        }

        .modal-body input:focus, 
        .modal-body textarea:focus {
            border-color: var(--primary-accent);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(148, 163, 255, 0.1);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }

        .input-with-icon input {
            padding-left: 44px !important;
        }

        /* --- Toggle Switch --- */
        .reminder-toggle-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fcfdfe;
            padding: 14px 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .4s; border-radius: 34px;
        }
        .slider:before {
            position: absolute; content: "";
            height: 18px; width: 18px; left: 3px; bottom: 3px;
            background-color: white; transition: .4s; border-radius: 50%;
        }
        input:checked + .slider { background-color: #4f46e5; }
        input:checked + .slider:before { transform: translateX(20px); }

        .modal-footer {
            display: flex;
            gap: 12px;
            margin-top: 35px;
            justify-content: flex-end;
        }

        .modal-btn {
            padding: 14px 28px;
            border-radius: 999px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            font-size: 15px;
            transition: all 0.2s;
            min-width: 120px;
        }

        .modal-btn.cancel {
            background: #ffffff;
            border-color: #e2e8f0;
            color: #64748b;
        }

        .modal-btn.cancel:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .modal-btn.save {
            background: var(--primary-gradient);
            color: #ffffff;
            box-shadow: 0 6px 15px rgba(148, 163, 255, 0.3);
        }

        .modal-btn.save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(148, 163, 255, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 100px 20px;
            color: var(--text-muted);
            animation: fadeIn 0.8s ease-out;
        }

        .empty-state i {
            display: inline-flex;
            width: 64px;
            height: 64px;
            background: #f1f5f9;
            color: #94a3b8;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 24px;
            border: 2px solid #e2e8f0;
        }

        .preset-btn.active {
            background: rgba(148, 163, 255, 0.2);
            color: var(--primary-accent);
            border-color: var(--primary-accent);
            transform: scale(1.05);
        }

        #modalTime.highlighted {
            background: #fdfbff !important;
            border-color: var(--primary-accent) !important;
            font-weight: 700 !important;
            color: var(--primary-accent) !important;
            box-shadow: 0 0 10px rgba(148, 163, 255, 0.1) !important;
        }

        @keyframes modalSheetUp {
            from { opacity: 0; transform: translateY(100%); }
            to { opacity: 1; transform: translateY(0); }
        }

        body.todo-modal-open {
            overflow: hidden;
        }

        /* Mobile / tablet bottom sheet — fixed to viewport bottom (reliable slide-up) */
        @media (max-width: 991.98px) {
            #taskModal.todo-task-modal-overlay,
            #taskModal.todo-task-modal-overlay.todo-task-modal--sheet {
                align-items: stretch !important;
                justify-content: flex-start !important;
                padding: 0 !important;
            }

            #taskModal .todo-task-modal-sheet {
                position: fixed !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                top: auto !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 12px 20px calc(20px + env(safe-area-inset-bottom, 0)) !important;
                border-radius: 20px 20px 0 0 !important;
                border-bottom: none !important;
                max-height: min(92vh, 92dvh) !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch;
                animation: modalSheetUp 0.38s cubic-bezier(0.32, 0.72, 0, 1) forwards !important;
                box-shadow: 0 -12px 40px rgba(15, 23, 42, 0.18) !important;
            }
        }

        @media (max-width: 768px) {
            .fab-container { bottom: calc(76px + env(safe-area-inset-bottom, 0)); right: 20px; }

            #taskModal .modal-header {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #fff;
                margin-bottom: 16px;
                padding-top: 14px;
            }

            #taskModal .modal-header::before {
                content: '';
                position: absolute;
                top: 4px;
                left: 50%;
                transform: translateX(-50%);
                width: 40px;
                height: 4px;
                background: #e2e8f0;
                border-radius: 999px;
            }

            #taskModal .modal-header h2 {
                font-size: 1.25rem;
            }

            #taskModal .form-row {
                flex-direction: column;
                gap: 0;
            }

            #taskModal .modal-footer {
                flex-direction: column-reverse;
                gap: 10px;
                margin-top: 20px;
                padding-top: 8px;
                position: sticky;
                bottom: 0;
                background: #fff;
            }

            #taskModal .modal-btn {
                width: 100%;
                min-width: 0;
            }
        }

        /* JS-driven sheet mode (fallback if media queries are overridden) */
        #taskModal.todo-task-modal-overlay.todo-task-modal--sheet {
            align-items: stretch !important;
            justify-content: flex-start !important;
            padding: 0 !important;
        }
        #taskModal.todo-task-modal-overlay.todo-task-modal--sheet .todo-task-modal-sheet {
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            top: auto !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 12px 20px calc(20px + env(safe-area-inset-bottom, 0)) !important;
            border-radius: 20px 20px 0 0 !important;
            border-bottom: none !important;
            max-height: min(92vh, 92dvh) !important;
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch;
            animation: modalSheetUp 0.38s cubic-bezier(0.32, 0.72, 0, 1) forwards !important;
            box-shadow: 0 -12px 40px rgba(15, 23, 42, 0.18) !important;
        }
    </style>
</head>
<body class="dashboard todo-my-tasks-page">
    <?php include_once $headerPath; ?>

    <main class="main-content min-h-screen">
    <div class="todo-dash">
        <header class="todo-page-header">
            <div class="todo-page-header__left">
                <h1 class="todo-page-title">To Do List</h1>
                <p class="todo-page-sub">
                    <?= htmlspecialchars($weekLabel) ?>
                    · <a href="<?= htmlspecialchars($weeklyMissionUrl) ?>">Weekly Mission</a>
                </p>
            </div>
            <div class="todo-page-header__center">
                <div class="todo-header-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="taskSearch" placeholder="Search tasks..." autocomplete="off" aria-label="Search tasks">
                </div>
            </div>
            <button type="button" class="todo-btn-add" onclick="openModal()">
                <i class="fas fa-plus"></i> Add New Task
            </button>
        </header>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8" id="statsGrid">
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--today" aria-hidden="true"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-value" id="statToday">0</div>
                <div class="kpi-label">Tasks scheduled for today</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--upcoming" aria-hidden="true"><i class="fas fa-calendar-alt"></i></div>
                <div class="kpi-value" id="statUpcoming">0</div>
                <div class="kpi-label">Tasks coming up next 7 days</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--done" aria-hidden="true"><i class="fas fa-check-circle"></i></div>
                <div class="kpi-value" id="statDone">0</div>
                <div class="kpi-label">Tasks completed this week</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--week" aria-hidden="true"><i class="fas fa-chart-line"></i></div>
                <div class="kpi-value" id="statWeekPct">0%</div>
                <div class="kpi-label">Weekly completion</div>
                <div class="kpi-hint" id="statWeekHint">0 of 0 tasks</div>
            </div>
        </div>

        <article class="dash-card todo-tasks-card">
            <div class="todo-toolbar todo-toolbar--inset">
                <nav class="todo-tabs" id="tabs">
                    <div class="tab-item active" data-tab="today">Today <span class="tab-count" id="tabCountToday">(0)</span></div>
                    <div class="tab-item" data-tab="upcoming">Upcoming <span class="tab-count" id="tabCountUpcoming">(0)</span></div>
                    <div class="tab-item" data-tab="completed">Done <span class="tab-count" id="tabCountDone">(0)</span></div>
                </nav>
            </div>
            <div id="taskList" class="todo-task-list"></div>
            <div id="emptyState" class="todo-empty" style="display: none;">
                <i class="far fa-clipboard"></i>
                <p>No tasks in this view.</p>
                <button type="button" class="todo-btn-add todo-btn-add--sm" onclick="openModal()">Add New Task</button>
            </div>
        </article>
    </div>
    </main>

    <!-- New Task Modal (bottom sheet on mobile; portaled to body via JS) -->
    <div class="todo-task-modal-overlay" id="taskModal" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
        <div class="todo-task-modal-sheet">
            <div class="modal-header">
                <h2 id="taskModalTitle">Add New Task</h2>
                <i class="fas fa-times" style="cursor:pointer; color: #94a3b8" onclick="closeModal()"></i>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Title <span style="color:#ef4444">*</span></label>
                    <input type="text" id="modalTitle" placeholder="Enter task title">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea id="modalDesc" placeholder="Add details about your task (optional)" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1.5;">
                        <label>Due Date & Time</label>
                        <div class="input-with-icon">
                            <i class="far fa-calendar"></i>
                            <input type="date" id="modalDate" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>&nbsp;</label>
                        <div class="input-with-icon">
                            <i class="far fa-clock"></i>
                            <input type="text" id="modalTime" placeholder="09:00 AM">
                        </div>
                    </div>
                </div>

                <div class="reminder-section">
                    <div class="reminder-toggle-bar">
                        <div class="reminder-info">
                            <label style="margin:0">Reminders</label>
                            <p style="font-size:11px; color:var(--text-muted); margin:0">Get notified before the due date</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="reminderToggle" onchange="toggleReminderOptions()">
                            <span class="slider round"></span>
                        </label>
                    </div>

                    <div id="reminderOptions" style="display: none; margin-top: 15px; animation: slideDown 0.3s ease-out;">
                        <div class="preset-bar" style="margin-bottom: 12px; display: flex; gap: 6px;">
                            <button type="button" class="preset-btn" onclick="setPresetTime(1, this)">+1m</button>
                            <button type="button" class="preset-btn" onclick="setPresetTime(5, this)">+5m</button>
                            <button type="button" class="preset-btn" onclick="setPresetTime(15, this)">+15m</button>
                            <button type="button" class="preset-btn" onclick="setPresetTime(60, this)">+1h</button>
                        </div>
                        <label>Notification Sound <i class="fas fa-volume-up" style="margin-left: 5px; color: var(--primary-accent); cursor: pointer;" onclick="previewSound()"></i></label>
                        <select id="modalSound" onchange="previewSound()" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--input-border); outline: none; font-size: 13px;">
                            <option value="bell notification.wav">Bell Notification</option>
                            <option value="alarm reminder.wav">Alarm Reminder</option>
                            <option value="baby alarm reminder.wav">Baby Alarm Reminder</option>
                            <option value="chicken alarm.wav">Chicken Alarm</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeModal()">Cancel</button>
                <button class="modal-btn save" onclick="saveTask()">Add Task</button>
            </div>
        </div>
    </div>

    <!-- Hidden Audio for Notifications -->
    <audio id="notificationPlayer" style="display: none;"></audio>

    <?php require __DIR__ . '/includes/todo-task-success-lottie.php'; ?>

    <script>
        /**
         * Returns a Date object locked to Africa/Dar_es_Salaam.
         * Used for all internal logic to ensure consistency.
         */
        function getTzDate() {
            return new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Dar_es_Salaam' }));
        }

        const WEEK_START = <?= json_encode($weekStart) ?>;
        const WEEK_END = <?= json_encode($weekEnd) ?>;
        let tasks = JSON.parse(localStorage.getItem('tasks') || '[]');
        let activeTab = 'today';
        let editingTaskId = null;
        let searchQuery = '';

        tasks = tasks.map(t => ({
            ...t,
            priority: t.priority || inferPriority(t)
        }));

        function inferPriority(task) {
            const title = (task.title || '').toLowerCase();
            if (/urgent|asap|critical|voucher|approval|high/i.test(title)) return 'high';
            if (/low|optional|later/i.test(title)) return 'low';
            return 'medium';
        }

        function taskPriority(task) {
            return task.priority || inferPriority(task);
        }

        function isTaskModalSheetViewport() {
            return window.matchMedia('(max-width: 991.98px)').matches;
        }

        function replayTaskModalSheetAnimation() {
            const sheet = document.querySelector('#taskModal .todo-task-modal-sheet');
            if (!sheet || !isTaskModalSheetViewport()) return;
            sheet.style.animation = 'none';
            void sheet.offsetWidth;
            sheet.style.animation = '';
        }

        function setupTaskModal() {
            const overlay = document.getElementById('taskModal');
            const sheet = overlay?.querySelector('.todo-task-modal-sheet');
            if (!overlay) return;
            if (overlay.parentElement !== document.body) {
                document.body.appendChild(overlay);
            }
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal();
            });
            sheet?.addEventListener('click', (e) => e.stopPropagation());
            window.addEventListener('resize', () => {
                if (overlay.style.display === 'flex') {
                    overlay.classList.toggle('todo-task-modal--sheet', isTaskModalSheetViewport());
                }
            });
        }

        function showTaskSuccessMessage(wasEdit) {
            const msg = wasEdit ? 'Task updated successfully!' : 'Task added successfully!';
            if (window.TodoTaskSuccessLottie && typeof window.TodoTaskSuccessLottie.show === 'function') {
                window.TodoTaskSuccessLottie.show(msg);
                return;
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: msg,
                    toast: true,
                    position: 'top-end',
                    timer: 2800,
                    showConfirmButton: false
                });
            }
        }

        function init() {
            render();
            setupTabs();
            setupTaskMenus();
            setupSearch();
            setupTaskModal();
            if (window.TodoTaskSuccessLottie && typeof window.TodoTaskSuccessLottie.preload === 'function') {
                window.TodoTaskSuccessLottie.preload();
            }
            // Initialize modern time picker with highlight logic
            flatpickr("#modalTime", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "h:i K",
                time_24hr: false,
                onChange: function(selectedDates, dateStr, instance) {
                    if (dateStr) {
                        document.querySelector("#modalTime").classList.add('highlighted');
                    } else {
                        document.querySelector("#modalTime").classList.remove('highlighted');
                    }
                    // If user manually selects time, clear active presets
                    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                }
            });
            // Start precision intervals
            setInterval(checkReminders, 1000);
        }

        function toggleReminderOptions() {
            const options = document.getElementById('reminderOptions');
            options.style.display = document.getElementById('reminderToggle').checked ? 'block' : 'none';
        }

        function setupSearch() {
            const input = document.getElementById('taskSearch');
            if (!input) return;
            input.addEventListener('input', () => {
                searchQuery = input.value.trim().toLowerCase();
                render();
            });
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        }

        function matchesSearch(task) {
            if (!searchQuery) return true;
            const hay = `${task.title || ''} ${task.desc || ''}`.toLowerCase();
            return hay.includes(searchQuery);
        }

        function setupTabs() {
            document.querySelectorAll('.tab-item').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    activeTab = tab.dataset.tab;
                    render();
                });
            });
        }

        function quickAdd() {
            const input = document.getElementById('quickInput');
            if (!input) return;
            const title = input.value.trim();
            if(!title) return;

            const now = getTzDate();
            const newTask = {
                id: Date.now(),
                title: title,
                desc: '',
                completed: false,
                date: now.toISOString().split('T')[0],
                createdAt: now.toISOString(),
                reminderTime: null,
                reminderSound: 'bell notification.wav',
                reminded: false,
                priority: inferPriority({ title })
            };

            tasks.unshift(newTask);
            save();
            input.value = '';
            render();
        }

        function openModal(taskId) {
            editingTaskId = taskId || null;
            const modal = document.getElementById('taskModal');
            const titleEl = document.querySelector('#taskModal .modal-header h2');
            const saveBtn = document.querySelector('#taskModal .modal-btn.save');
            if (editingTaskId) {
                const t = tasks.find(x => x.id === editingTaskId);
                if (!t) { editingTaskId = null; return; }
                titleEl.textContent = 'Edit Task';
                saveBtn.textContent = 'Save Changes';
                document.getElementById('modalTitle').value = t.title || '';
                document.getElementById('modalDesc').value = t.desc || '';
                document.getElementById('modalDate').value = t.date || '';
                document.getElementById('modalTime').value = t.reminderTime || '';
                document.getElementById('reminderToggle').checked = !!t.reminderTime;
                document.getElementById('modalSound').value = t.reminderSound || 'bell notification.wav';
                toggleReminderOptions();
            } else {
                titleEl.textContent = 'Add New Task';
                saveBtn.textContent = 'Add Task';
                document.getElementById('modalDate').value = getTzDate().toISOString().split('T')[0];
            }
            modal.classList.toggle('todo-task-modal--sheet', isTaskModalSheetViewport());
            modal.style.display = 'flex';
            document.body.classList.add('todo-modal-open');
            replayTaskModalSheetAnimation();
            document.getElementById('modalTitle').focus();
        }

        function closeModal() {
            editingTaskId = null;
            const modal = document.getElementById('taskModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('todo-task-modal--sheet');
            }
            document.body.classList.remove('todo-modal-open');
            document.querySelector('#taskModal .modal-header h2').textContent = 'Add New Task';
            document.querySelector('#taskModal .modal-btn.save').textContent = 'Add Task';
            document.getElementById('modalTitle').value = '';
            document.getElementById('modalDesc').value = '';
            document.getElementById('modalTime').value = '';
            document.getElementById('reminderToggle').checked = false;
            toggleReminderOptions();
        }

        function saveTask() {
            const titleInput = document.getElementById('modalTitle');
            const descInput = document.getElementById('modalDesc');
            const dateInput = document.getElementById('modalDate');
            const timeInput = document.getElementById('modalTime');
            const soundInput = document.getElementById('modalSound');
            const hasReminder = document.getElementById('reminderToggle').checked;
            
            const title = titleInput.value.trim();
            if(!title) return;

            let finalTime = null;
            if(hasReminder && timeInput.value) {
                finalTime = timeInput.value;
            }

            const wasEdit = !!editingTaskId;

            if (editingTaskId) {
                tasks = tasks.map(t => {
                    if (t.id !== editingTaskId) return t;
                    return {
                        ...t,
                        title,
                        desc: descInput.value.trim(),
                        date: dateInput.value,
                        reminderTime: finalTime,
                        reminderSound: soundInput.value,
                        reminded: false
                    };
                });
            } else {
                const newTask = {
                    id: Date.now(),
                    title: title,
                    desc: descInput.value.trim(),
                    completed: false,
                    date: dateInput.value,
                    reminderTime: finalTime,
                    reminderSound: soundInput.value,
                    reminded: false,
                    createdAt: getTzDate().toISOString(),
                    priority: inferPriority({ title })
                };
                tasks.unshift(newTask);
            }

            save();
            closeModal();
            render();
            showTaskSuccessMessage(wasEdit);
        }

        function previewSound() {
            const soundFile = document.getElementById('modalSound').value;
            const player = document.getElementById('notificationPlayer');
            player.src = 'notification sound/' + soundFile;
            player.play().catch(e => console.log('Audio preview failed:', e));
        }

        function setPresetTime(minutes, btn) {
            const now = getTzDate();
            now.setMinutes(now.getMinutes() + minutes);
            // Must match Flatpickr format "h:i K" exactly (e.g. "9:30 AM")
            const hours = now.getHours();
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const h12 = hours % 12 || 12;
            const mins = now.getMinutes().toString().padStart(2, '0');
            const timeStr = `${h12}:${mins} ${suffix}`;
            
            const fpInput = document.querySelector("#modalTime");
            if (fpInput && fpInput._flatpickr) {
                fpInput._flatpickr.setDate(timeStr);
                // Highlight the time input
                fpInput.classList.add('highlighted');
            }

            // Highlight the active button
            if (btn) {
                document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }
        }

        function toggleComplete(id, e) {
            if(e) e.stopPropagation();
            tasks = tasks.map(t => {
                if(t.id === id) {
                    const newStatus = !t.completed;
                    if(newStatus) {
                        confetti({
                            particleCount: 40,
                            spread: 70,
                            origin: { y: 0.9 },
                            colors: ['#2563eb', '#22c55e', '#ffffff']
                        });
                    }
                    return {...t, completed: newStatus};
                }
                return t;
            });
            save();
            render();
        }

        function deleteTask(id, e) {
            if(e) e.stopPropagation();
            if(!confirm('Delete this task?')) return;
            tasks = tasks.filter(t => t.id !== id);
            save();
            render();
        }

        function save() {
            localStorage.setItem('tasks', JSON.stringify(tasks));
        }

        function checkReminders() {
            const now = getTzDate();
            const currentDate = now.toISOString().split('T')[0];
            
            // Format time to match Flatpickr "h:i K" exactly
            const hours = now.getHours();
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const h12 = hours % 12 || 12;
            const mins = now.getMinutes().toString().padStart(2, '0');
            const currentTimeStr = `${h12}:${mins} ${suffix}`;
            
            let updateNeeded = false;

            tasks = tasks.map(task => {
                if(!task.completed && task.reminderTime && !task.reminded) {
                    if(task.date === currentDate && task.reminderTime === currentTimeStr) {
                        triggerReminder(task);
                        updateNeeded = true;
                        return {...task, reminded: true};
                    }
                }
                return task;
            });

            if(updateNeeded) {
                save();
                render();
            }
        }

        function triggerReminder(task) {
            const player = document.getElementById('notificationPlayer');
            const soundPath = 'notification sound/' + task.reminderSound;
            
            player.src = soundPath;
            player.loop = true; 
            
            const playPromise = player.play();
            
            if (playPromise !== undefined) {
                playPromise.catch(error => {
                    console.log('Audio playback blocked:', error);
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: true,
                        confirmButtonText: 'Enable Sound',
                        confirmButtonColor: '#94a3ff',
                        timer: null
                    });
                    Toast.fire({
                        icon: 'info',
                        title: 'Sound Blocked',
                        text: 'Click here to enable audio reminders'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            player.play();
                        }
                    });
                });
            }

            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 15000, 
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                },
                didClose: () => {
                    player.pause();
                    player.currentTime = 0;
                    player.loop = false;
                }
            });

            Toast.fire({
                icon: 'info',
                title: 'Reminder Task',
                text: task.title,
                background: '#ffffff',
                color: '#0f172a',
                iconColor: '#94a3ff',
                width: '350px'
            });

            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('Ultimate General Trading Reminder', { body: task.title });
            } else if ('Notification' in window && Notification.permission !== 'denied') {
                Notification.requestPermission();
            }
        }

        function countTasks() {
            const today = getTzDate().toISOString().split('T')[0];
            const in7 = new Date(getTzDate());
            in7.setDate(in7.getDate() + 7);
            const end7 = in7.toISOString().split('T')[0];

            let todayCount = 0, upcomingCount = 0;
            let weekTotal = 0, weekDone = 0;

            tasks.forEach(t => {
                const inWeek = t.date >= WEEK_START && t.date <= WEEK_END;
                if (inWeek) {
                    weekTotal++;
                    if (t.completed) weekDone++;
                }
                if (t.completed) return;
                if (t.date === today || t.date < today) todayCount++;
                else if (t.date > today && t.date <= end7) upcomingCount++;
            });

            const weekPct = weekTotal > 0 ? Math.round((weekDone / weekTotal) * 100) : 0;
            const tabDone = tasks.filter(t => t.completed).length;

            return { todayCount, upcomingCount, doneCount: weekDone, weekTotal, weekDone, weekPct, tabDone };
        }

        function updateStats() {
            const c = countTasks();
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            set('statToday', c.todayCount);
            set('statUpcoming', c.upcomingCount);
            set('statDone', c.doneCount);
            set('statWeekPct', c.weekPct + '%');
            set('statWeekHint', `${c.weekDone} of ${c.weekTotal} tasks`);
        }

        function formatTaskTime(task) {
            if (task.reminderTime) return task.reminderTime;
            const raw = task.createdAt ? new Date(task.createdAt) : getTzDate();
            const d = new Date(raw.toLocaleString('en-US', { timeZone: 'Africa/Dar_es_Salaam' }));
            const hours = d.getHours();
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const h12 = hours % 12 || 12;
            const mins = d.getMinutes().toString().padStart(2, '0');
            return `${h12}:${mins} ${suffix}`;
        }

        function priorityLabel(p) {
            return p.charAt(0).toUpperCase() + p.slice(1);
        }

        function categorizeTasks() {
            const today = getTzDate().toISOString().split('T')[0];
            const in7 = new Date(getTzDate());
            in7.setDate(in7.getDate() + 7);
            const end7 = in7.toISOString().split('T')[0];
            const buckets = { today: [], upcoming: [], done: [] };

            tasks.forEach(t => {
                if (!matchesSearch(t)) return;
                if (t.completed) {
                    buckets.done.push(t);
                    return;
                }
                if (t.date > today && t.date <= end7) buckets.upcoming.push(t);
                else if (t.date === today || t.date < today) buckets.today.push(t);
            });

            buckets.today.sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || ''));
            buckets.upcoming.sort((a, b) => a.date.localeCompare(b.date));
            buckets.done.sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || ''));
            return buckets;
        }

        function setupTaskMenus() {
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.task-menu-wrap')) {
                    document.querySelectorAll('.task-menu-panel').forEach(p => p.classList.remove('open'));
                }
            });
        }

        function toggleTaskMenu(btn, e) {
            e.stopPropagation();
            const panel = btn.nextElementSibling;
            const wasOpen = panel.classList.contains('open');
            document.querySelectorAll('.task-menu-panel').forEach(p => p.classList.remove('open'));
            if (!wasOpen) panel.classList.add('open');
        }

        function bindTaskCard(card, task) {
            const check = card.querySelector('.todo-task-check') || card.querySelector('.task-check');
            if (check) {
                check.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleComplete(task.id, e);
                });
            }
            const menuBtn = card.querySelector('.task-menu-btn');
            if (menuBtn) {
                menuBtn.addEventListener('click', (e) => toggleTaskMenu(menuBtn, e));
            }
            const editBtn = card.querySelector('[data-action="edit"]');
            if (editBtn) {
                editBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    document.querySelectorAll('.task-menu-panel').forEach(p => p.classList.remove('open'));
                    openModal(task.id);
                });
            }
            const delBtn = card.querySelector('[data-action="delete"]');
            if (delBtn) {
                delBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    document.querySelectorAll('.task-menu-panel').forEach(p => p.classList.remove('open'));
                    deleteTask(task.id, e);
                });
            }
        }

        function buildTaskRow(task) {
            const prio = taskPriority(task);
            const remindIcon = (task.reminderTime || task.reminded)
                ? '<i class="fas fa-volume-up todo-task-remind" title="Reminder set"></i>' : '';
            const checkClass = task.completed ? 'todo-task-check is-done' : 'todo-task-check';
            const checkInner = task.completed ? '<i class="fas fa-check"></i>' : '';

            const row = document.createElement('div');
            row.className = `todo-task-row ${task.completed ? 'completed' : ''}`;
            row.innerHTML = `
                <div class="${checkClass}" role="button" tabindex="0" aria-label="Mark complete">${checkInner}</div>
                <div class="todo-task-main">
                    <div class="todo-task-title">${escapeHtml(task.title)} ${remindIcon}</div>
                    ${task.desc ? `<div class="todo-task-sub">${escapeHtml(task.desc)}</div>` : ''}
                </div>
                <div class="todo-task-meta">
                    <span class="todo-task-time"><i class="far fa-clock"></i> ${escapeHtml(formatTaskTime(task))}</span>
                    <span class="todo-prio todo-prio--${prio}">${priorityLabel(prio)}</span>
                    <div class="task-menu-wrap">
                        <button type="button" class="task-menu-btn" aria-label="Task options"><i class="fas fa-ellipsis-v"></i></button>
                        <div class="task-menu-panel">
                            <button type="button" data-action="edit"><i class="fas fa-pen"></i> Edit</button>
                            <button type="button" class="danger" data-action="delete"><i class="fas fa-trash-alt"></i> Delete</button>
                        </div>
                    </div>
                </div>
            `;
            bindTaskCard(row, task);
            return row;
        }

        function render() {
            const listEl = document.getElementById('taskList');
            const empty = document.getElementById('emptyState');
            if (!listEl) return;

            updateStats();
            const buckets = categorizeTasks();
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            set('tabCountToday', `(${buckets.today.length})`);
            set('tabCountUpcoming', `(${buckets.upcoming.length})`);
            set('tabCountDone', `(${buckets.done.length})`);

            let items = buckets.today;
            if (activeTab === 'upcoming') items = buckets.upcoming;
            else if (activeTab === 'completed') items = buckets.done;

            listEl.innerHTML = '';
            items.forEach(t => listEl.appendChild(buildTaskRow(t)));

            if (empty) {
                empty.style.display = items.length === 0 ? 'block' : 'none';
                listEl.style.display = items.length === 0 ? 'none' : 'block';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        init();
    </script>
    </div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

</html>






