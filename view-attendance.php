<?php
require_once __DIR__ . '/includes/functions.php';
requireAdmin();
ensureAttendanceTable();

// Get filter parameters
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$userFilter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$signTypeFilter = isset($_GET['sign_type']) ? $_GET['sign_type'] : '';

// Build query
$whereConditions = [];
$params = [];

if (!empty($dateFilter)) {
    $whereConditions[] = "DATE(a.signed_at) = ?";
    $params[] = $dateFilter;
}

if ($userFilter > 0) {
    $whereConditions[] = "a.user_id = ?";
    $params[] = $userFilter;
}

if (!empty($signTypeFilter) && in_array($signTypeFilter, ['sign_in', 'sign_out'])) {
    $whereConditions[] = "a.sign_type = ?";
    $params[] = $signTypeFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get attendance records
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        u.full_name,
        u.username,
        u.department
    FROM attendance a
    INNER JOIN users u ON a.user_id = u.id
    $whereClause
    ORDER BY a.signed_at DESC
    LIMIT 500
");
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll();

// Get all users for filter
$stmt = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name");
$allUsers = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.user_id) as total_employees,
        SUM(CASE WHEN a.sign_type = 'sign_in' THEN 1 ELSE 0 END) as total_sign_ins,
        SUM(CASE WHEN a.sign_type = 'sign_out' THEN 1 ELSE 0 END) as total_sign_outs
    FROM attendance a
    $whereClause
");
$stmt->execute($params);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .attendance-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #212529;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filters-section {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filters-section h3 {
            margin: 0 0 16px 0;
            font-size: 16px;
            color: #212529;
        }
        
        .filter-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 4px;
            font-size: 13px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 0;
            font-size: 14px;
        }
        
        .btn-filter {
            padding: 8px 20px;
            background: #212529;
            color: white;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        
        .btn-filter:hover {
            background: #424242;
        }
        
        .btn-reset {
            padding: 8px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        .attendance-table {
            background: #ffffff;
            border: 1px solid #e9ecef;
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
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #212529;
            border-bottom: 2px solid #dee2e6;
        }
        
        .data-table td {
            padding: 12px;
            font-size: 13px;
            border-bottom: 1px solid #f1f3f5;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .sign-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 0;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .sign-type-badge.sign-in {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .sign-type-badge.sign-out {
            background: #fff3e0;
            color: #e65100;
        }
        
        .signature-preview {
            max-width: 200px;
            max-height: 80px;
            border: 1px solid #dee2e6;
            border-radius: 0;
            cursor: pointer;
        }
        
        .signature-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .signature-modal.show {
            display: flex;
        }
        
        .signature-modal-content {
            background: white;
            padding: 24px;
            border-radius: 0;
            max-width: 600px;
            max-height: 80vh;
            overflow: auto;
        }
        
        .signature-modal-content img {
            max-width: 100%;
            height: auto;
            border: 1px solid #dee2e6;
            border-radius: 0;
        }
        
        .btn-close-modal {
            margin-top: 16px;
            padding: 8px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 0;
            cursor: pointer;
        }
        
        .btn-close-modal:hover {
            background: #5a6268;
        }
        
        .distance-badge {
            display: inline-block;
            padding: 2px 6px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 0;
            font-size: 11px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .attendance-container {
                padding: 16px;
            }
            
            .filter-form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>
    
    <main class="main-content">
        <div class="attendance-container">
            <h1 style="margin-bottom: 24px;">Attendance Records</h1>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total_employees'] ?? 0 ?></div>
                    <div class="stat-label">Employees</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total_sign_ins'] ?? 0 ?></div>
                    <div class="stat-label">Sign Ins</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total_sign_outs'] ?? 0 ?></div>
                    <div class="stat-label">Sign Outs</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <h3>Filters</h3>
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="user_id">Employee</label>
                        <select id="user_id" name="user_id">
                            <option value="0">All Employees</option>
                            <?php foreach ($allUsers as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= $userFilter == $user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="sign_type">Type</label>
                        <select id="sign_type" name="sign_type">
                            <option value="">All Types</option>
                            <option value="sign_in" <?= $signTypeFilter === 'sign_in' ? 'selected' : '' ?>>Sign In</option>
                            <option value="sign_out" <?= $signTypeFilter === 'sign_out' ? 'selected' : '' ?>>Sign Out</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn-filter">Apply Filters</button>
                    </div>
                    <div class="filter-group">
                        <a href="view-attendance.php" class="btn-reset" style="text-decoration: none; display: inline-block;">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Attendance Table -->
            <div class="attendance-table">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Distance</th>
                                <th>Signature</th>
                                <th>Device/IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendanceRecords)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #6c757d;">
                                        No attendance records found for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendanceRecords as $record): ?>
                                    <tr>
                                        <td>
                                            <?= date('d/m/Y H:i:s', strtotime($record['signed_at'])) ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($record['full_name']) ?></strong><br>
                                            <small style="color: #6c757d;"><?= htmlspecialchars($record['username']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($record['department'] ?? 'â€”') ?></td>
                                        <td>
                                            <span class="sign-type-badge <?= $record['sign_type'] ?>">
                                                <?= $record['sign_type'] === 'sign_in' ? 'Sign In' : 'Sign Out' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small>
                                                Lat: <?= number_format($record['latitude'], 6) ?><br>
                                                Lon: <?= number_format($record['longitude'], 6) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="distance-badge">
                                                <?= number_format($record['distance_from_office'], 2) ?> m
                                            </span>
                                        </td>
                                        <td>
                                            <img src="<?= htmlspecialchars($record['signature_image']) ?>" 
                                                 alt="Signature" 
                                                 class="signature-preview"
                                                 onclick="showSignatureModal('<?= htmlspecialchars($record['signature_image'], ENT_QUOTES) ?>')">
                                        </td>
                                        <td>
                                            <small>
                                                <?= htmlspecialchars(substr($record['device_info'] ?? 'â€”', 0, 30)) ?>...<br>
                                                IP: <?= htmlspecialchars($record['ip_address'] ?? 'â€”') ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Signature Modal -->
    <div id="signatureModal" class="signature-modal" onclick="closeSignatureModal()">
        <div class="signature-modal-content" onclick="event.stopPropagation()">
            <h3 style="margin-top: 0;">Signature</h3>
            <img id="signatureModalImage" src="" alt="Signature">
            <div style="text-align: center; margin-top: 16px;">
                <button class="btn-close-modal" onclick="closeSignatureModal()">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        function showSignatureModal(signatureData) {
            const modal = document.getElementById('signatureModal');
            const img = document.getElementById('signatureModalImage');
            img.src = signatureData;
            modal.classList.add('show');
        }
        
        function closeSignatureModal() {
            const modal = document.getElementById('signatureModal');
            modal.classList.remove('show');
        }
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSignatureModal();
            }
        });
    </script>
</body>
</html>


