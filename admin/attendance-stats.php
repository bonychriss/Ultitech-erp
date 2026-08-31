<?php
require_once '../includes/functions.php';
requireAdmin();

// --- Configuration ---
$workStartTime = defined('WORK_START_TIME') ? WORK_START_TIME : '08:30:00';
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Ensure valid date
if ($month < 1 || $month > 12) $month = (int)date('m');
if ($year < 2020 || $year > 2030) $year = (int)date('Y');

// Month start/end
$startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$endDate = date('Y-m-t', strtotime($startDate));

// Get all active users
$companySql = "";
$companyParams = [];
if (columnExists('users', 'company_id', $pdo)) {
    $companySql = " AND company_id = ?";
    $companyParams[] = (int) currentCompanyId();
}
$stmt = $pdo->prepare("SELECT id, full_name, department FROM users WHERE is_active = 1{$companySql} ORDER BY full_name ASC");
$stmt->execute($companyParams);
$users = $stmt->fetchAll();

// Fetch all attendance for this month
$stmt = $pdo->prepare("
    SELECT user_id, sign_type, signed_at, distance_from_office 
    FROM attendance 
    WHERE DATE(signed_at) BETWEEN ? AND ?
    ORDER BY signed_at ASC
");
$stmt->execute([$startDate, $endDate]);
$attendanceRaw = $stmt->fetchAll(PDO::FETCH_GROUP); // Group by user_id implied? No, fetchAll default is list.

// Re-organize attendance by User -> Date -> Type
$attendanceData = [];
foreach ($attendanceRaw as $row) {
    $uid = $row['user_id'];
    $date = date('Y-m-d', strtotime($row['signed_at']));
    $type = $row['sign_type'];
    
    if (!isset($attendanceData[$uid])) $attendanceData[$uid] = [];
    if (!isset($attendanceData[$uid][$date])) $attendanceData[$uid][$date] = ['in' => null, 'out' => null];
    
    if ($type === 'sign_in' && $attendanceData[$uid][$date]['in'] === null) {
        $attendanceData[$uid][$date]['in'] = $row;
    } elseif ($type === 'sign_out') {
        $attendanceData[$uid][$date]['out'] = $row; // Keeps the last sign out
    }
}

// --- Calculate Statistics ---
$stats = [];
$today = date('Y-m-d');
$now = time();

// Determine working days so far in the month (up to Today or End of Month)
$calcEndDate = ($endDate > $today) ? $today : $endDate;
$workingDays = [];
$period = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($calcEndDate))->modify('+1 day')
);
foreach ($period as $dt) {
    if ($dt->format('N') <= 5) { // 1 (Mon) to 5 (Fri)
        $workingDays[] = $dt->format('Y-m-d');
    }
}

// Initialize stats for each user
foreach ($users as $u) {
    $uid = $u['id'];
    $userStats = [
        'id' => $uid,
        'name' => $u['full_name'],
        'dept' => $u['department'],
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'on_time' => 0,
        'hours' => 0,
        'avg_hours' => 0
    ];
    
    // Process known attendance
    if (isset($attendanceData[$uid])) {
        foreach ($attendanceData[$uid] as $date => $dayData) {
            if ($dayData['in']) {
                $userStats['present']++;
                
                // Late Check
                $inTime = date('H:i:s', strtotime($dayData['in']['signed_at']));
                if ($inTime > $workStartTime) {
                    $userStats['late']++;
                } else {
                    $userStats['on_time']++;
                }
                
                // Hours Calculation
                if ($dayData['out']) {
                    $t1 = strtotime($dayData['in']['signed_at']);
                    $t2 = strtotime($dayData['out']['signed_at']);
                    $diff = $t2 - $t1;
                    if ($diff > 0) {
                        $userStats['hours'] += ($diff / 3600);
                    }
                }
            }
        }
    }
    
    // Calculate Absences (only for working days that have supposedly passed)
    foreach ($workingDays as $wd) {
        // If user didn't sign in on this working day, count as absent
        if (!isset($attendanceData[$uid][$wd]['in'])) {
             // Optional: Check if future? No, workingDays is capped at Today.
             // Special case: If today, only count absent if work day is over or substantial time passed? 
             // For simplicity: If today has started and they aren't in, they are currently absent.
             $userStats['absent']++;
        }
    }
    
    if ($userStats['present'] > 0) {
        $userStats['avg_hours'] = $userStats['hours'] / $userStats['present'];
    }
    
    $stats[] = $userStats;
}

// --- Today's Highlights (Late & Absent) ---
$todaysHighlights = ['late' => [], 'absent' => [], 'present_count' => 0];
// Fetch Today's data specifically quickly
$today = date('Y-m-d');
// Re-use logic from above but filter for $today
foreach ($users as $u) {
    $uid = $u['id'];
    $hasIn = isset($attendanceData[$uid][$today]['in']);
    
    if ($hasIn) {
        $todaysHighlights['present_count']++;
        $inTime = date('H:i:s', strtotime($attendanceData[$uid][$today]['in']['signed_at']));
        if ($inTime > $workStartTime) {
            $todaysHighlights['late'][] = [
                'name' => $u['full_name'],
                'time' => date('H:i', strtotime($attendanceData[$uid][$today]['in']['signed_at']))
            ];
        }
    } else {
        // Only mark absent if it's a weekday
        if (date('N') <= 5) {
             $todaysHighlights['absent'][] = $u['full_name'];
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Statistics - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 24px;
            align-items: start;
        }
        .stats-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .stats-card h3 {
            margin-top: 0;
            color: #111827;
            font-size: 16px;
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .highlight-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f9fafb;
            font-size: 14px;
        }
        .highlight-item:last-child { border-bottom: none; }
        .highlight-name { font-weight: 500; color: #374151; }
        .highlight-meta { color: #6b7280; font-size: 13px; }
        
        .badge-late { background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-absent { background: #f3f4f6; color: #4b5563; padding: 2px 6px; border-radius: 4px; font-size: 11px; }

        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        table.stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        table.stats-table th, table.stats-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        table.stats-table th {
            background: #f9fafb;
            color: #374151;
            font-weight: 600;
        }
        table.stats-table tr:hover { background: #f9fafb; }
        
        .filter-form {
            display: flex;
            gap: 12px;
            align-items: center;
            background: white;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }
        .filter-form select, .filter-form button {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .filter-form button {
            background: #111827;
            color: white;
            font-weight: 500;
            border-color: #111827;
            cursor: pointer;
        }
        
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="dashboard">
<?php require_once '../includes/header_admin.php'; ?>

<main class="main-content">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:24px; margin:0;">Attendance Statistics</h1>
        <form class="filter-form" style="margin:0; padding:10px;">
            <select name="month">
                <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year">
                <?php for($y=date('Y'); $y>=2023; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit">View Report</button>
        </form>
    </div>

    <div class="stats-grid">
        <!-- Sidebar: Today's Highlights -->
        <div class="highlights-column">
            <div class="stats-card">
                <h3>âš ï¸ Late Today (<?= count($todaysHighlights['late']) ?>)</h3>
                <?php if (empty($todaysHighlights['late'])): ?>
                    <p style="color:#6b7280; font-size:14px;">No one arrived late today.</p>
                <?php else: ?>
                    <?php foreach ($todaysHighlights['late'] as $late): ?>
                        <div class="highlight-item">
                            <span class="highlight-name"><?= htmlspecialchars($late['name']) ?></span>
                            <span class="badge-late"><?= $late['time'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="stats-card">
                <h3>ðŸš« Absent Today (<?= count($todaysHighlights['absent']) ?>)</h3>
                <p style="font-size:12px; color:#6b7280; margin-bottom:12px;">Employees not signed in yet.</p>
                <?php if (empty($todaysHighlights['absent'])): ?>
                    <p style="color:#059669; font-size:14px; font-weight:500;">Everyone is present!</p>
                <?php else: ?>
                    <?php foreach ($todaysHighlights['absent'] as $name): ?>
                        <div class="highlight-item">
                            <span class="highlight-name" style="color:#6b7280;"><?= htmlspecialchars($name) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="stats-card">
                <h3>â„¹ï¸ Legend</h3>
                <div class="highlight-item"><span class="highlight-name">Work Start</span> <span class="highlight-meta"><?= $workStartTime ?></span></div>
                <div class="highlight-item"><span class="highlight-name">Working Days</span> <span class="highlight-meta">Mon - Fri</span></div>
            </div>
        </div>

        <!-- Main Stats Table -->
        <div class="table-container">
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th title="Days Signed In">Present</th>
                        <th title="Working Days Missed">Absent</th>
                        <th title="Sign-ins after <?= $workStartTime ?>">Late</th>
                        <th>Total Hours</th>
                        <th>Avg Hrs/Day</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $s): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500; color:#111827;"><?= htmlspecialchars($s['name']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($s['dept'] ?: '-') ?></td>
                            <td>
                                <strong style="color:#059669;"><?= $s['present'] ?></strong>
                            </td>
                            <td>
                                <?php if ($s['absent'] > 0): ?>
                                    <strong style="color:#dc2626;"><?= $s['absent'] ?></strong>
                                <?php else: ?>
                                    <span style="color:#d1d5db;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['late'] > 0): ?>
                                    <span style="color:#d97706; font-weight:600;"><?= $s['late'] ?></span>
                                <?php else: ?>
                                    <span style="color:#d1d5db;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($s['hours'], 1) ?>h</td>
                            <td>
                                <?php 
                                    $avg = $s['avg_hours'];
                                    $col = '#374151';
                                    if ($avg < 7) $col = '#dc2626'; // Underworked warning
                                    elseif ($avg > 9) $col = '#059669'; // Overworked info?
                                ?>
                                <span style="color:<?= $col ?>; font-weight:600;"><?= number_format($avg, 1) ?>h</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>

