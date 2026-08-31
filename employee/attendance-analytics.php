<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$period = isset($_GET['period']) ? $_GET['period'] : '30'; // days
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$period days"));

// Get attendance data for the period
// Get attendance data for the period
$stmt = $pdo->prepare("
    SELECT 
        date,
        time_in,
        time_out,
        status,
        overtime_hours
    FROM attendance
    WHERE user_id = ? 
    AND date BETWEEN ? AND ?
    ORDER BY date ASC
");
$stmt->execute([$userId, $startDate, $endDate]);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Detailed records are the same set now, just maybe ordered differently or same.
// For history table, let's just use the same data but maybe reverse order if needed for display.
$detailedRecords = array_reverse($attendanceRecords);

// Calculate metrics
$shiftStartTime = '09:00:00'; // Default, ideally from settings but good enough
$totalDays = 0;
$presentDays = 0;
$lateDays = 0;
$totalHours = 0;
$longestStreak = 0;
$currentStreak = 0;
$lastDate = null;

$dailyHours = [];
$weeklyData = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];

foreach ($attendanceRecords as $record) {
    $date = $record['date'];
    $presentDays++;

    // Streak calculation
    if ($lastDate === null || (strtotime($date) - strtotime($lastDate)) == 86400) {
        $currentStreak++;
        $longestStreak = max($longestStreak, $currentStreak);
    } else {
        $currentStreak = 1;
    }
    $lastDate = $date;

    // Late check
    if ($record['status'] === 'Late') {
        $lateDays++;
    }

    // Hours calculation (using totalhours if available, else calc)
    if (!empty($record['time_in']) && !empty($record['time_out'])) {
        $signInTime = new DateTime($record['time_in']);
        $signOutTime = new DateTime($record['time_out']);
        // If time_out is before time_in (over midnight), add day. But simple system usually same day.
        $interval = $signInTime->diff($signOutTime);
        $hours = $interval->h + ($interval->i / 60);
        
        // Use pre-calculated total_hours if in DB for accuracy? 
        // Let's calculate for consistency with visual graph
        $totalHours += $hours;
        $dailyHours[$date] = $hours;

        // Weekly distribution
        $dayOfWeek = date('D', strtotime($date));
        $weeklyData[$dayOfWeek] += $hours;
    } elseif (!empty($record['time_in'])) {
        // user clock in but not out
        $dailyHours[$date] = 0; 
    }
}

// Calculate working days in period (excluding weekends)
$workingDays = 0;
$currentDate = new DateTime($startDate);
$end = new DateTime($endDate);
while ($currentDate <= $end) {
    $dayOfWeek = $currentDate->format('N');
    if ($dayOfWeek < 6) { // Monday = 1, Sunday = 7
        $workingDays++;
    }
    $currentDate->modify('+1 day');
}

$attendanceRate = $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 1) : 0;
$punctualityScore = $presentDays > 0 ? round((($presentDays - $lateDays) / $presentDays) * 100, 1) : 0;
$avgHoursPerDay = $presentDays > 0 ? round($totalHours / $presentDays, 1) : 0;

// Prepare chart data
$chartLabels = array_keys($dailyHours);
$chartData = array_values($dailyHours);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Analytics - Ultimate General Trading</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .analytics-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 15px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .period-selector {
            display: flex;
            gap: 4px;
            background: white;
            padding: 0;
            border: 1px solid #e5e7eb;
        }

        .period-btn {
            padding: 6px 12px;
            border: none;
            background: white;
            border-radius: 0;
            cursor: pointer;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s;
            text-decoration: none;
            border-right: 1px solid #e5e7eb;
            font-size: 13px;
        }

        .period-btn:last-child {
            border-right: none;
        }

        .period-btn:hover {
            background: #e5e7eb;
        }

        .period-btn.active {
            background: #111827;
            color: white;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .metric-card {
            background: white;
            padding: 16px;
            border-radius: 0;
            border: 1px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 3px;
        }

        .metric-card.primary::before {
            background: #8b5cf6;
        }

        .metric-card.success::before {
            background: #10b981;
        }

        .metric-card.warning::before {
            background: #f59e0b;
        }

        .metric-card.info::before {
            background: #3b82f6;
        }

        .metric-icon {
            width: 36px;
            height: 36px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 18px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }

        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .metric-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }

        .metric-trend {
            font-size: 11px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .metric-trend.up {
            color: #10b981;
        }

        .metric-trend.down {
            color: #ef4444;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .chart-card {
            background: white;
            padding: 16px;
            border-radius: 0;
            border: 1px solid #e5e7eb;
        }

        .chart-title {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 15px;
        }

        .chart-container {
            position: relative;
            height: 250px;
        }

        .insights-section {
            background: white;
            padding: 16px;
            border-radius: 0;
            border: 1px solid #e5e7eb;
        }

        .insights-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 15px;
        }

        .insight-item {
            display: flex;
            align-items: start;
            gap: 10px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 0;
            border-left: 3px solid #e5e7eb;
            margin-bottom: 10px;
        }

        .insight-icon {
            width: 24px;
            height: 24px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
        }

        .insight-icon.success {
            background: #d1fae5;
            color: #065f46;
        }

        .insight-icon.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .insight-icon.info {
            background: #dbeafe;
            color: #1e40af;
        }

        .insight-content h4 {
            margin: 0 0 2px 0;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }

        .insight-content p {
            margin: 0;
            font-size: 12px;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }

        /* History Table Styles */
        .attendance-table {
            width: 100%;
            margin-top: 20px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0;
            overflow: hidden;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: #f9fafb;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .data-table td {
            padding: 10px 12px;
            font-size: 12px;
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f9fafb;
        }

        /* Badges */
        .sign-type-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 500;
            text-transform: capitalize;
        }

        .sign-type-badge.sign_in {
            background: #d1fae5;
            color: #065f46;
        }

        .sign-type-badge.sign_out {
            background: #ffedd5;
            color: #9a3412;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.late {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .status-badge.early {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-badge.overtime {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .status-badge.ontime {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .distance-badge {
            display: inline-block;
            padding: 2px 4px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
        }

        .signature-preview {
            max-width: 60px;
            max-height: 25px;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            background: white;
            padding: 2px;
        }

        /* Modal */
        .signature-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 50;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .signature-modal.show {
            display: flex;
        }

        .signature-modal-content {
            background: white;
            padding: 24px;
            border-radius: 8px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .signature-modal-content img {
            max-width: 100%;
            height: auto;
            border: 1px solid #e5e7eb;
        }

        .btn-close-modal {
            margin-top: 16px;
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
    </style>
</head>

<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="analytics-container">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-chart-bar"></i> Attendance Analytics</h1>
                <div class="period-selector">
                    <a href="?period=7" class="period-btn <?= $period == 7 ? 'active' : '' ?>">7 Days</a>
                    <a href="?period=30" class="period-btn <?= $period == 30 ? 'active' : '' ?>">30 Days</a>
                    <a href="?period=90" class="period-btn <?= $period == 90 ? 'active' : '' ?>">90 Days</a>
                </div>
            </div>

            <div class="metrics-grid">
                <div class="metric-card primary">
                    <div class="metric-icon primary"><i class="fas fa-chart-line"></i></div>
                    <div class="metric-value"><?= $attendanceRate ?>%</div>
                    <div class="metric-label">Attendance Rate</div>
                    <div class="metric-trend up">
                        <i class="fas fa-arrow-up"></i> <?= $presentDays ?> of <?= $workingDays ?> days
                    </div>
                </div>

                <div class="metric-card success">
                    <div class="metric-icon success"><i class="fas fa-clock"></i></div>
                    <div class="metric-value"><?= $punctualityScore ?>%</div>
                    <div class="metric-label">Punctuality Score</div>
                    <div class="metric-trend <?= $lateDays == 0 ? 'up' : 'down' ?>">
                        <?= $lateDays ?> late arrivals
                    </div>
                </div>

                <div class="metric-card warning">
                    <div class="metric-icon warning"><i class="fas fa-fire"></i></div>
                    <div class="metric-value"><?= $longestStreak ?></div>
                    <div class="metric-label">Longest Streak</div>
                    <div class="metric-trend up">
                        Current: <?= $currentStreak ?> days
                    </div>
                </div>

                <div class="metric-card info">
                    <div class="metric-icon info"><i class="fas fa-hourglass-half"></i></div>
                    <div class="metric-value"><?= $avgHoursPerDay ?>h</div>
                    <div class="metric-label">Avg Hours/Day</div>
                    <div class="metric-trend up">
                        Total: <?= round($totalHours, 1) ?>h
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h3 class="chart-title">Daily Hours Worked</h3>
                    <div class="chart-container">
                        <canvas id="hoursChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h3 class="chart-title">Weekly Distribution</h3>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="insights-section">
                <h3 class="insights-title"><i class="fas fa-lightbulb"></i> Insights & Recommendations</h3>

                <?php if ($presentDays > 0): ?>
                    <?php if ($punctualityScore >= 90): ?>
                        <div class="insight-item">
                            <div class="insight-icon success"><i class="fas fa-check-circle"></i></div>
                            <div class="insight-content">
                                <h4>Excellent Punctuality!</h4>
                                <p>You're consistently on time. Keep up the great work!</p>
                            </div>
                        </div>
                    <?php elseif ($punctualityScore >= 70): ?>
                        <div class="insight-item">
                            <div class="insight-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="insight-content">
                                <h4>Room for Improvement</h4>
                                <p>Try to arrive a few minutes earlier to improve your punctuality score.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="insight-item">
                            <div class="insight-icon warning">⚠️</div>
                            <div class="insight-content">
                                <h4>Punctuality Needs Attention</h4>
                                <p>You've been late <?= $lateDays ?> times. Consider adjusting your morning routine.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($longestStreak >= 10): ?>
                        <div class="insight-item">
                            <div class="insight-icon success">🔥</div>
                            <div class="insight-content">
                                <h4>Amazing Consistency!</h4>
                                <p>Your <?= $longestStreak ?>-day streak shows excellent commitment.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($avgHoursPerDay >= 8): ?>
                        <div class="insight-item">
                            <div class="insight-icon info">💪</div>
                            <div class="insight-content">
                                <h4>Strong Work Ethic</h4>
                                <p>You're averaging <?= $avgHoursPerDay ?> hours per day. Great dedication!</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($attendanceRate >= 95): ?>
                        <div class="insight-item">
                            <div class="insight-icon success">🌟</div>
                            <div class="insight-content">
                                <h4>Perfect Attendance!</h4>
                                <p>You've attended <?= $attendanceRate ?>% of working days. Outstanding!</p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="insight-item">
                        <div class="insight-icon info">ℹ️</div>
                        <div class="insight-content">
                            <h4>No Data Yet</h4>
                            <p>Sign in to start tracking your attendance and see insights here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance History Table -->
        <div class="insights-section" style="margin-top: 30px;">
            <h3 class="insights-title">📜 Attendance History</h3>
            <div class="attendance-table">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Distance</th>
                                    <th>Signature</th>
                                    <th>Device/IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detailedRecords)): ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 30px; color: #6b7280;">
                                            No attendance records found for this period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($detailedRecords as $record): ?>
                                        <tr>
                                            <td>
                                                <?= date('d/m/Y', strtotime($record['date'])) ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Me') ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($_SESSION['department'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($record['time_in'])): ?>
                                                    <?= date('H:i', strtotime($record['time_in'])) ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($record['time_out'])): ?>
                                                    <?= date('H:i', strtotime($record['time_out'])) ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <!-- Status Badge -->
                                                <?php 
                                                    $statusClass = 'ontime';
                                                    if ($record['status'] === 'Late') $statusClass = 'late';
                                                    elseif ($record['status'] === 'Early') $statusClass = 'early';
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>">
                                                    <?= htmlspecialchars($record['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    Lat: <?= number_format($record['latitude'] ?? 0, 6) ?><br>
                                                    Lon: <?= number_format($record['longitude'] ?? 0, 6) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="distance-badge"><?= number_format($record['distance_from_office'] ?? 0, 2) ?> m</span>
                                            </td>
                                            <td>
                                                <?php if (!empty($record['signature_image'])): ?>
                                                    <img src="<?= htmlspecialchars($record['signature_image']) ?>"
                                                        class="signature-preview"
                                                        onclick="showSignatureModal('<?= htmlspecialchars($record['signature_image'], ENT_QUOTES) ?>')">
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small
                                                    style="display:block; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"
                                                    title="<?= htmlspecialchars($record['device_info'] ?? '-') ?>">
                                                    <?= htmlspecialchars($record['device_info'] ?? 'Unknown Device') ?>
                                                </small>
                                                <small style="color: #6b7280; display:block;">IP:
                                                    <?= htmlspecialchars($record['ip_address'] ?? 'Unknown') ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>
    </main>

    <!-- Signature Modal -->
    <div id="signatureModal" class="signature-modal" onclick="closeSignatureModal()">
        <div class="signature-modal-content" onclick="event.stopPropagation()">
            <h3 style="margin: 0 0 16px;">Signature</h3>
            <img id="signatureModalImage" src="" alt="Signature">
            <div style="text-align: center;">
                <button class="btn-close-modal" onclick="closeSignatureModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function showSignatureModal(src) {
            const modal = document.getElementById('signatureModal');
            const img = document.getElementById('signatureModalImage');
            img.src = src;
            modal.classList.add('show');
        }

        function closeSignatureModal() {
            document.getElementById('signatureModal').classList.remove('show');
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSignatureModal();
        });
    </script>

    <script>
        // Daily Hours Chart
        const hoursCtx = document.getElementById('hoursChart').getContext('2d');
        new Chart(hoursCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function ($d) {
                    return date('M j', strtotime($d)); }, $chartLabels)) ?>,
                datasets: [{
                    label: 'Hours Worked',
                    data: <?= json_encode($chartData) ?>,
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return value + 'h';
                            }
                        }
                    }
                }
            }
        });

        // Weekly Distribution Chart
        const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
        new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Total Hours',
                    data: <?= json_encode(array_values($weeklyData)) ?>,
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(236, 72, 153, 0.8)',
                        'rgba(107, 114, 128, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return value + 'h';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>