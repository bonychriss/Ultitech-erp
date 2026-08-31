<?php
require_once '../includes/functions.php';
requireAdmin();

$user_id = $_SESSION['user_id'];

// Handle meeting creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_meeting'])) {
    $title = trim($_POST['title']);
    $scheduled_time = !empty($_POST['scheduled_time']) ? $_POST['scheduled_time'] : null;
    
    if (!empty($title)) {
        $meeting = createMeeting($title, $user_id, $scheduled_time);
        if (!$scheduled_time) {
            header("Location: ../meeting-room.php?code=" . $meeting['code']);
            exit;
        }
        header("Location: meetings.php?success=1");
        exit;
    }
}

// Handle meeting actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $meeting_id = (int)$_POST['meeting_id'];
    
    if ($_POST['action'] === 'end') {
        endMeeting($meeting_id);
    } elseif ($_POST['action'] === 'lock') {
        toggleMeetingLock($meeting_id, 1);
    } elseif ($_POST['action'] === 'unlock') {
        toggleMeetingLock($meeting_id, 0);
    }
    
    header("Location: meetings.php");
    exit;
}

// Get all meetings
$activeMeetings = getAllMeetings('active');
$scheduledMeetings = getAllMeetings('scheduled');
$endedMeetings = array_slice(getAllMeetings('ended'), 0, 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meetings - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .meetings-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { font-size: 2rem; margin-bottom: 10px; }
        .create-section { background: white; border: 1px solid #ddd; padding: 20px; border-radius: 4px; margin-bottom: 30px; }
        .create-section h3 { margin-bottom: 15px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 500; }
        .btn-primary { background: #000; color: #fff; }
        .btn-primary:hover { background: #333; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .section { margin-bottom: 30px; }
        .section h2 { font-size: 1.5rem; margin-bottom: 15px; }
        .meeting-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #ddd; }
        .meeting-table th { background: #f8f9fa; padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-size: 0.9rem; }
        .meeting-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        .meeting-table tr:hover { background: #f9fafb; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-scheduled { background: #fef3c7; color: #92400e; }
        .status-ended { background: #e5e7eb; color: #374151; }
        .meeting-code { font-family: monospace; background: #f0f0f0; padding: 4px 8px; border-radius: 4px; }
        .btn-join { background: #059669; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px; display: inline-block; font-size: 0.85rem; }
        .btn-join:hover { background: #047857; }
        .actions { display: flex; gap: 8px; }
        .empty-state { text-align: center; padding: 40px; color: #999; }
        @media(max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="meetings-container">
            <div class="page-header">
                <h1>
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: bottom;"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                    Manage Meetings
                </h1>
                <p>Create and manage audio meetings for your team</p>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div style="padding: 10px; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 20px;">
                Meeting created successfully!
            </div>
            <?php endif; ?>

            <div class="create-section">
                <h3>Create New Meeting</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="title">Meeting Title *</label>
                            <input type="text" id="title" name="title" placeholder="e.g., Team Standup" required>
                        </div>
                        <div class="form-group">
                            <label for="scheduled_time">Schedule For (Optional)</label>
                            <input type="datetime-local" id="scheduled_time" name="scheduled_time">
                        </div>
                    </div>
                    <button type="submit" name="create_meeting" class="btn btn-primary">Create Meeting</button>
                </form>
            </div>

            <?php if (!empty($activeMeetings)): ?>
            <div class="section">
                <h2>Active Meetings (<?= count($activeMeetings) ?>)</h2>
                <table class="meeting-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Code</th>
                            <th>Created By</th>
                            <th>Started</th>
                            <th>Participants</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeMeetings as $meeting): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($meeting['title']) ?></strong></td>
                            <td><span class="meeting-code"><?= $meeting['meeting_code'] ?></span></td>
                            <td><?= htmlspecialchars($meeting['creator_name']) ?><br><small style="color:#777;"><?= htmlspecialchars($meeting['creator_department']) ?></small></td>
                            <td><?= date('M j, g:i a', strtotime($meeting['created_at'])) ?></td>
                            <td>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                <?= $meeting['active_participants'] ?>
                            </td>
                            <td>
                                <span class="status-badge status-active">Active</span>
                                <?php if ($meeting['is_locked']): ?>
                                <span class="status-badge" style="background:#fecaca;color:#991b1b;">Locked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="../meeting-room.php?code=<?= $meeting['meeting_code'] ?>" class="btn-join">Join</a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                        <input type="hidden" name="action" value="<?= $meeting['is_locked'] ? 'unlock' : 'lock' ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:white;"><?= $meeting['is_locked'] ? 'Unlock' : 'Lock' ?></button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                        <input type="hidden" name="action" value="end">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('End this meeting?')">End</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($scheduledMeetings)): ?>
            <div class="section">
                <h2>Scheduled Meetings (<?= count($scheduledMeetings) ?>)</h2>
                <table class="meeting-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Code</th>
                            <th>Created By</th>
                            <th>Scheduled For</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduledMeetings as $meeting): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($meeting['title']) ?></strong></td>
                            <td><span class="meeting-code"><?= $meeting['meeting_code'] ?></span></td>
                            <td><?= htmlspecialchars($meeting['creator_name']) ?></td>
                            <td><?= date('M j, Y g:i a', strtotime($meeting['scheduled_time'])) ?></td>
                            <td><span class="status-badge status-scheduled">Scheduled</span></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                                    <input type="hidden" name="action" value="end">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this meeting?')">Cancel</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($endedMeetings)): ?>
            <div class="section">
                <h2>Recent Meetings</h2>
                <table class="meeting-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Code</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endedMeetings as $meeting): ?>
                        <tr>
                            <td><?= htmlspecialchars($meeting['title']) ?></td>
                            <td><span class="meeting-code"><?= $meeting['meeting_code'] ?></span></td>
                            <td><?= htmlspecialchars($meeting['creator_name']) ?></td>
                            <td><?= date('M j, Y g:i a', strtotime($meeting['created_at'])) ?></td>
                            <td><span class="status-badge status-ended">Ended</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (empty($activeMeetings) && empty($scheduledMeetings) && empty($endedMeetings)): ?>
            <div class="empty-state">
                <p>No meetings yet. Create your first meeting above!</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

