<?php
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$userName = $_SESSION['full_name'];

// Handle meeting creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_meeting'])) {
    $title = trim($_POST['title']);
    if (!empty($title)) {
        $meeting = createMeeting($title, $user_id);
        header("Location: ../meeting-room.php?code=" . $meeting['code']);
        exit;
    }
}

// Handle meeting deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_meeting'])) {
    $meeting_id = (int)$_POST['meeting_id'];
    // Security check: only allow creator or admin
    $meeting = getMeetingById($meeting_id);
    if ($meeting && ((int)$meeting['created_by'] === (int)$user_id || isAdmin() || ($_SESSION['role'] ?? '') === 'admin')) {
        deleteMeeting($meeting_id);
        header("Location: meetings.php");
        exit;
    }
}

// Get user's meetings
$activeMeetings = getUserMeetings($user_id, 'active');
$scheduledMeetings = getUserMeetings($user_id, 'scheduled');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meetings - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .meetings-container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .page-header { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h1 { 
            font-size: 1.8rem; 
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        .header-desc {
            color: #6b7280;
            margin-top: 5px;
            font-size: 0.95rem;
        }

        /* Create Section */
        .create-card { 
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            color: white;
            padding: 25px; 
            border-radius: 16px; 
            margin-bottom: 40px; 
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .create-info h3 { margin: 0 0 5px 0; font-size: 1.25rem; }
        .create-info p { margin: 0; opacity: 0.8; font-size: 0.9rem; }
        
        .create-form {
            flex: 1;
            max-width: 500px;
            display: flex;
            gap: 10px;
        }
        .create-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            color: white;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }
        .create-input:focus {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.4);
        }
        .create-input::placeholder { color: rgba(255,255,255,0.5); }
        
        .btn-create { 
            padding: 12px 24px; 
            background: #ffffff; 
            color: #111827; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 600; 
            white-space: nowrap;
            transition: transform 0.2s;
        }
        .btn-create:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        /* Section Styling */
        .section-title { 
            font-size: 1.1rem; 
            font-weight: 600; 
            color: #374151; 
            margin-bottom: 20px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Grid Layout */
        .meeting-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); 
            gap: 24px; 
            margin-bottom: 40px;
        }

        /* Card Styling */
        .meeting-card { 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 12px; 
            overflow: hidden;
            transition: all 0.2sease;
            position: relative;
        }
        .meeting-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.08); 
            border-color: #d1d5db;
        }
        
        .card-body { padding: 20px; }
        
        .meeting-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .meeting-title { font-size: 1.1rem; font-weight: 600; color: #111827; line-height: 1.4; }
        
        .status-badge { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            text-transform: uppercase; 
            letter-spacing: 0.025em;
            flex-shrink: 0;
            margin-left: 10px;
        }
        .status-active { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-scheduled { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .status-ended { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }

        .meta-row {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 6px;
        }
        
        .card-footer {
            padding: 15px 20px;
            background: #f9fafb;
            border-top: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .meeting-code-pill {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            color: #4b5563;
            background: #e5e7eb;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .btn-join { 
            background: #059669; 
            color: white; 
            padding: 8px 16px; 
            border-radius: 6px; 
            font-size: 0.9rem; 
            font-weight: 500; 
            text-decoration: none; 
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-join:hover { background: #047857; text-decoration: none; color: white; }
        
        .empty-state { 
            text-align: center; 
            padding: 60px 20px; 
            color: #9ca3af; 
            background: #f9fafb;
            border-radius: 12px;
            border: 2px dashed #e5e7eb;
        }

        @media (max-width: 768px) {
            .create-card { flex-direction: column; align-items: stretch; }
            .create-form { max-width: none; flex-direction: column; }
            .btn-create { width: 100%; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="meetings-container">
            <div class="page-header">
                <div>
                    <h1>Meetings</h1>
                    <div class="header-desc">Collaborate with your team in real-time.</div>
                </div>
            </div>

            <!-- Create Section -->
            <div class="create-card">
                <div class="create-info">
                    <h3>Start a New Meeting</h3>
                    <p>Create an instant meeting room and invite your team.</p>
                </div>
                <form method="POST" class="create-form">
                    <input type="text" id="title" name="title" class="create-input" placeholder="Enter meeting title (e.g., Project Update)" required>
                    <button type="submit" name="create_meeting" class="btn-create">
                        <svg style="vertical-align: middle; margin-right: 4px;" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Create Meeting
                    </button>
                </form>
            </div>

            <?php if (!empty($activeMeetings)): ?>
            <div class="section-title">
                <div style="width: 8px; height: 8px; background: #059669; border-radius: 50%;"></div>
                Active Now
            </div>
            <div class="meeting-grid">
                <?php foreach ($activeMeetings as $meeting): ?>
                <div class="meeting-card">
                    <div class="card-body">
                        <div class="meeting-header-row">
                            <div class="meeting-title"><?= htmlspecialchars($meeting['title']) ?></div>
                            <span class="status-badge status-active">Active</span>
                        </div>
                        <div class="meta-row">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            Created by <?= htmlspecialchars($meeting['creator_name']) ?>
                        </div>
                        <div class="meta-row">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <?= date('M j, g:i a', strtotime($meeting['created_at'])) ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div style="font-size: 0.85rem; color: #4b5563;">
                            <span class="meeting-code-pill"><?= $meeting['meeting_code'] ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?php if ((int)$meeting['created_by'] === (int)$user_id || isAdmin() || ($_SESSION['role'] ?? '') === 'admin'): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meeting?');" style="margin:0;">
                                <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                <button type="submit" name="delete_meeting" style="padding: 8px; border: 1px solid #ef4444; background: white; color: #ef4444; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" title="Delete Meeting">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="../meeting-room.php?code=<?= $meeting['meeting_code'] ?>" class="btn-join">
                                Join Room
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($scheduledMeetings)): ?>
            <div class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                Scheduled
            </div>
            <div class="meeting-grid">
                <?php foreach ($scheduledMeetings as $meeting): ?>
                <div class="meeting-card">
                    <div class="card-body">
                        <div class="meeting-header-row">
                            <div class="meeting-title"><?= htmlspecialchars($meeting['title']) ?></div>
                            <span class="status-badge status-scheduled">Scheduled</span>
                        </div>
                        <div class="meta-row">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <?= date('M j, Y g:i a', strtotime($meeting['scheduled_time'])) ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div style="font-size: 0.85rem; color: #4b5563;">
                            Code: <span class="meeting-code-pill"><?= $meeting['meeting_code'] ?></span>
                        </div>
                        <?php if ((int)$meeting['created_by'] === (int)$user_id || isAdmin() || ($_SESSION['role'] ?? '') === 'admin'): ?>
                        <div style="display:flex;">
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meeting?');" style="margin:0;">
                                <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                <button type="submit" name="delete_meeting" style="padding: 6px; border: none; background: transparent; color: #ef4444; cursor: pointer; opacity: 0.7;" title="Delete Meeting">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>



            <?php if (empty($activeMeetings) && empty($scheduledMeetings)): ?>
            <div class="empty-state">
                <svg style="color: #d1d5db; margin-bottom: 15px;" xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                <h3>No meetings yet</h3>
                <p>Create your first meeting above to get started with your team.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

