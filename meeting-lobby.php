<?php
require_once 'includes/functions.php';
requireLogin();

$userName = $_SESSION['full_name'] ?? 'User';
$initials = strtoupper(substr($userName, 0, 1) . substr(strstr($userName, " ") ?: " ", 1, 1));

// Fetch active meetings (last 2 hours activity)
try {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM active_meetings 
                         WHERE status='active' 
                         AND last_activity > DATE_SUB(NOW(), INTERVAL 2 HOUR) 
                         ORDER BY last_activity DESC");
    $active_meetings = $stmt->fetchAll();
} catch (PDOException $e) {
    $active_meetings = [];
    error_log("Lobby fetch error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Lobby - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #111827;
            --card-bg: #1f2937;
            --brand-blue: #2563eb;
            --text-light: #f9fafb;
            --text-muted: #9ca3af;
            --input-bg: #374151;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .lobby-container {
            width: 100%;
            max-width: 900px;
            padding: 60px 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 50px;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .header p {
            color: var(--text-muted);
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        /* Card Styles */
        .lobby-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.05);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-title i {
            color: var(--brand-blue);
        }

        /* Create Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 14px 16px;
            color: white;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--brand-blue);
        }

        .btn-primary {
            width: 100%;
            background: var(--brand-blue);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-primary:active {
            transform: scale(0.98);
        }

        /* Active Meetings List */
        .meetings-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .meeting-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background 0.2s;
        }

        .meeting-item:hover {
            background: rgba(255,255,255,0.05);
        }

        .meeting-info h4 {
            margin: 0 0 4px 0;
            font-size: 15px;
            color: var(--text-light);
        }

        .meeting-meta {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            gap: 15px;
        }

        .btn-join {
            background: transparent;
            color: var(--brand-blue);
            border: 1px solid var(--brand-blue);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-join:hover {
            background: var(--brand-blue);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 32px;
            margin-bottom: 15px;
            display: block;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="lobby-container">
        <header class="header">
            <h1>Meetings</h1>
            <p>Connect with your team in high-quality audio</p>
        </header>

        <div class="grid">
            <!-- Create Section -->
            <div class="lobby-card">
                <div class="card-title">
                    <i class="fas fa-plus-circle"></i>
                    Start a New Meeting
                </div>
                <form action="meeting.php" method="GET">
                    <div class="form-group">
                        <label class="form-label">Meeting Name</label>
                        <input type="text" name="room" class="form-input" placeholder="e.g. Sales Sync, Project X" required maxlength="50">
                    </div>
                    <button type="submit" class="btn-primary">Create Room & Join</button>
                    <p style="font-size: 11px; color: var(--text-muted); margin-top: 15px; text-align: center;">
                        <i class="fas fa-shield-alt"></i> Everyone will enter muted by default.
                    </p>
                </form>
            </div>

            <!-- List Section -->
            <div class="lobby-card">
                <div class="card-title">
                    <i class="fas fa-broadcast-tower"></i>
                    Ongoing Meetings
                </div>
                <div class="meetings-list">
                    <?php if (empty($active_meetings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-video-slash"></i>
                            No meetings currently active.
                        </div>
                    <?php else: ?>
                        <?php foreach ($active_meetings as $meeting): ?>
                            <div class="meeting-item">
                                <div class="meeting-info">
                                    <h4><?= htmlspecialchars($meeting['room_name']) ?></h4>
                                    <div class="meeting-meta">
                                        <span><i class="far fa-user"></i> By <?= htmlspecialchars($meeting['created_by']) ?></span>
                                    </div>
                                </div>
                                <a href="meeting.php?room=<?= urlencode($meeting['room_name']) ?>" class="btn-join">Join</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
