<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
requireAdmin();

$type = $_GET['type'] ?? 'monthly';
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Calculate date range
$endDate = date('Y-m-d');
switch ($type) {
    case 'weekly':
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $filename = 'attendance_weekly_' . date('Y-m-d');
        break;
    case 'yearly':
        $startDate = date('Y-m-d', strtotime('-1 year'));
        $filename = 'attendance_yearly_' . date('Y-m-d');
        break;
    case 'monthly':
    default:
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $filename = 'attendance_monthly_' . date('Y-m-d');
        break;
}

// Build query
$params = [$startDate, $endDate];
$sql = "
    SELECT 
        a.date,
        u.full_name,
        u.department,
        a.time_in,
        a.time_out,
        a.status,
        a.total_hours,
        a.overtime_hours
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.date BETWEEN ? AND ?
";

if ($userId > 0) {
    $sql .= " AND a.user_id = ?";
    $params[] = $userId;
}

$sql .= " ORDER BY a.date DESC, u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header row
fputcsv($output, ['Date', 'Day', 'Employee', 'Department', 'Sign In', 'Sign Out', 'Status', 'Overtime Status', 'Hours Worked']);

foreach ($records as $row) {
    $dateObj = new DateTime($row['date']);
    $formattedDate = $dateObj->format('d/m/Y');
    $dayName = $dateObj->format('l'); // Monday, Tuesday...
    
    $signIn = $row['time_in'] ? date('H:i:s', strtotime($row['time_in'])) : '-';
    $signOut = $row['time_out'] ? date('H:i:s', strtotime($row['time_out'])) : '-';
    
    $status = $row['status'] ?? '-';
    
    // Calculate Overtime Status details
    $overtimeStatus = '-';
    if (!empty($row['overtime_hours']) && $row['overtime_hours'] > 0) {
        $overtimeStatus = $row['overtime_hours'] . ' hrs OT';
    } 
    /* 
       Old departure logic was calculating Early/Overtime manually from sign_out.
       Now we rely on `status` (Late/Early/On Time) and `overtime_hours`.
       We can infer "Early Leave" if status is Early.
    */
    
    // Format hours
    $hours = '-';
    if (!empty($row['total_hours'])) {
         $hoursVal = (float)$row['total_hours'];
         $h = floor($hoursVal);
         $m = round(($hoursVal - $h) * 60);
         $hours = $h . 'h ' . sprintf('%02d', $m) . 'm';
    } elseif ($row['time_in'] && $row['time_out']) {
        // Fallback calc if total_hours is null
        $start = new DateTime($row['time_in']);
        $end = new DateTime($row['time_out']);
        $diff = $start->diff($end);
        $hours = $diff->h . 'h ' . sprintf('%02d', $diff->i) . 'm';
    }

    fputcsv($output, [
        $formattedDate,
        $dayName,
        $row['full_name'],
        $row['department'],
        $signIn,
        $signOut,
        $status,
        $overtimeStatus,
        $hours
    ]);
}

fclose($output);
exit;
