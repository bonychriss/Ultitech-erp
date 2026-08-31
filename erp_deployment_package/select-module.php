<?php
require_once 'includes/functions.php';
requireLogin();

$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'employee';
$isAdmin = ($userRole === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Module - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        }
        .container {
            max-width: 1200px;
            width: 100%;
        }
        .header {
            text-align: center;
            color: #000;
            margin-bottom: 48px;
        }
        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        .header p {
            font-size: 0.95rem;
            color: #666;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
        }
        .card {
            background: #fff;
            border: 2px solid #000;
            border-radius: 0;
            padding: 24px 16px;
            text-align: center;
            text-decoration: none;
            color: #000;
            transition: all 0.2s;
            position: relative;
        }
        .card:hover {
            background: #000;
            color: #fff;
        }
        .card:hover .card-desc {
            color: #ccc;
        }
        .icon {
            font-size: 2rem;
            margin-bottom: 12px;
            display: block;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .card-desc {
            font-size: 0.75rem;
            color: #666;
        }
        .logout-link {
            display: block;
            text-align: center;
            margin-top: 32px;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .logout-link:hover {
            color: #000;
        }
        @media(max-width: 1024px) {
            .grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media(max-width: 768px) {
            .header h1 { font-size: 1.5rem; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Select Module</h1>
        <p>Choose a module to continue</p>
    </div>
    <div class="grid">
        <a href="<?= $isAdmin ? 'admin/dashboard.php' : 'employee/dashboard.php' ?>" class="card voucher">

            <div class="card-title">Payment Voucher</div>
            <div class="card-desc">Create, manage, and track vouchers</div>
        </a>
        <a href="<?= $isAdmin ? 'admin/view-attendance.php' : 'employee/sign.php' ?>" class="card attendance">

            <div class="card-title">Attendance</div>
            <div class="card-desc">Sign in/out and view records</div>
        </a>
        <a href="<?= $isAdmin ? 'admin/manage_tasks.php' : 'employee/tasks.php' ?>" class="card tasks">

            <div class="card-title">Tasks</div>
            <div class="card-desc">Manage daily tasks</div>
        </a>
        <a href="<?= $isAdmin ? 'admin/meetings.php' : 'employee/meetings.php' ?>" class="card meetings">
            <span class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
            </span>
            <div class="card-title">Meetings</div>
            <div class="card-desc">Audio meetings with staff</div>
        </a>
        <a href="erp/index.php" class="card erp">

            <div class="card-title">ERP</div>
            <div class="card-desc">Enterprise resource planning</div>
        </a>
        <a href="order-tracking/index.php" class="card tracking">
            <span class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </span>
            <div class="card-title">Order Tracking</div>
            <div class="card-desc">Track shipments and orders</div>
        </a>
    </div>
    <a href="logout.php" class="logout-link">â† Logout</a>
</div>
</body>
</html>

