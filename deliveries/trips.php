<?php
require_once '../includes/functions.php';
requireLogin();

// Handle Form Submission for New Trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_trip') {
    try {
        $tripRef = 'TRIP-' . date('Ymd') . '-' . rand(100, 999);
        $driverId = $_POST['driver_id'];
        $vehicleId = $_POST['vehicle_id'];
        
        $stmt = $pdo->prepare("INSERT INTO delivery_trips (trip_ref, driver_id, vehicle_id, status) VALUES (?, ?, ?, 'planned')");
        $stmt->execute([$tripRef, $driverId, $vehicleId]);
        
        $newTripId = $pdo->lastInsertId();
        header("Location: manifest.php?trip_id=" . $newTripId); // Redirect to add orders
        exit;
    } catch (Exception $e) {
        $error = "Error creating trip: " . $e->getMessage();
    }
}

$pageTitle = "Trips";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?> - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Roboto:wght@400;500;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-primary: 'Inter', sans-serif;
            --font-heading: 'Roboto', sans-serif;
            --font-data: 'Source Sans 3', sans-serif;
            --primary-color: #2563eb;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        body { font-family: var(--font-primary); background-color: var(--bg-color); color: var(--text-main); }
        h1, h2, h3 { font-family: var(--font-heading); }
        
        .main-content { padding: 20px; }

        /* Sharp Cards & Buttons */
        .card { background: #fff; border: 1px solid #e5e7eb; box-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 0; }
        .btn { background: var(--primary-color); color: white; border: none; padding: 8px 16px; font-size: 13px; font-weight: 500; cursor: pointer; border-radius: 0; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: #1d4ed8; }
        .btn-outline { background: white; border: 1px solid #d1d5db; color: #374151; padding: 6px 12px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f9fafb; padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
        .data-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #374151; font-family: var(--font-data); vertical-align: middle; }
        .data-table tr:hover td { background-color: #f8fafc; }
        
        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:24px; width:450px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #374151; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; font-family: var(--font-data); font-size: 14px; border-radius: 0; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-color); outline: none; }
    <style>
        /* Mobile Specific Overrides */
        @media (max-width: 768px) {
            .main-content { padding: 15px !important; }
            .header-row { flex-direction: column !important; align-items: flex-start !important; gap: 15px !important; }
            .header-row > button { width: 100%; justify-content: center; }

            /* Flatten All */
            .card {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                padding: 0 !important;
            }

            /* Table Scroll */
            .table-wrap {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -15px;
                padding: 0 15px;
            }
            .data-table {
                min-width: 800px;
                display: table !important;
            }
            .data-table th, .data-table td {
                white-space: nowrap !important;
                font-size: 11px !important;
                padding: 10px 8px !important;
            }

        @media (min-width: 769px) {
            /* Removed mobile-module-nav */
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <div class="header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
            <div>
                <h1 style="font-size: 20px; color: #111827; margin:0;">Trips</h1>
                <p style="color: #6b7280; margin: 2px 0 0 0; font-size: 13px;">Plan and manage delivery routes</p>
            </div>
        </div>

        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Trip ID</th>
                            <th>Status</th>
                            <th>Driver</th>
                            <th>Vehicle</th>
                            <th>Stops</th>
                            <th>Date</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT t.*, 
                            (SELECT COUNT(*) FROM delivery_orders WHERE trip_id = t.id) as stop_count,
                            u.full_name as driver_name
                            FROM delivery_trips t 
                            LEFT JOIN erp_users u ON t.driver_id = u.id 
                            ORDER BY t.created_at DESC");
                        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (empty($trips)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:#9ca3af;">No trips found. Create one to get started.</td></tr>
                        <?php else: ?>
                            <?php foreach($trips as $trip): ?>
                                <tr>
                                    <td style="font-weight:600; color:#111827;"><?= htmlspecialchars($trip['trip_ref']) ?></td>
                                    <td>
                                        <span style="
                                            padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500;
                                            background: <?= $trip['status']=='completed'?'#ecfdf5':($trip['status']=='in_transit'?'#eff6ff':'#f3f4f6') ?>; 
                                            color: <?= $trip['status']=='completed'?'#059669':($trip['status']=='in_transit'?'#2563eb':'#4b5563') ?>;
                                            text-transform: capitalize;
                                        "><?= str_replace('_', ' ', $trip['status']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($trip['driver_name']) ?></td>
                                    <td><?= htmlspecialchars($trip['vehicle_id']) ?></td>
                                    <td><?= $trip['stop_count'] ?> stops</td>
                                    <td><?= date('d M Y', strtotime($trip['created_at'])) ?></td>
                                    <td style="text-align:right;">
                                        <a href="view_trip.php?trip_id=<?= $trip['id'] ?>" class="btn-outline" style="margin-left:4px;">Driver View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Create Trip Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size:18px;">Create New Trip</h3>
                <span onclick="closeModal()" style="cursor:pointer; font-size:20px;">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_trip">
                
                <div class="form-group">
                    <label>Driver</label>
                    <select name="driver_id" required>
                        <option value="">Select Driver...</option>
                        <?php
                        $users = $pdo->query("SELECT id, full_name FROM erp_users")->fetchAll();
                        foreach($users as $user) {
                            echo "<option value='{$user['id']}'>".htmlspecialchars($user['full_name'])."</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Vehicle ID</label>
                    <input type="text" name="vehicle_id" placeholder="e.g. T 123 ABC" required>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
                    <button type="button" onclick="closeModal()" class="btn-outline" style="border:none;">Cancel</button>
                    <button type="submit" class="btn">Create Trip</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() { document.getElementById('createModal').classList.add('open'); }
        function closeModal() { document.getElementById('createModal').classList.remove('open'); }
    </script>
</body>
</html>
