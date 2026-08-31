<?php
require_once '../includes/functions.php';
requireLogin();

if (!isCompanyAdmin() && !isSuperAdmin()) {
    header('Location: ../select-module.php?error=access_denied');
    exit;
}

$currentCompany = getCurrentCompany();
if (!$currentCompany) {
    die("Company context not found.");
}

$companyId = (int)$currentCompany['id'];
$companyName = $currentCompany['company_name'];
$industryType = $currentCompany['industry_type'] ?? 'trading';

// Fetch Stats
// 1. Total Employees
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'employee'");
$stmt->execute([$companyId]);
$totalEmployees = (int)$stmt->fetchColumn();

// 2. Active Modules
$modules = getCompanyModules(true);
$totalModules = count($modules);

// 3. Recent Activity (Placeholder for now, can be expanded)
$recentActivity = []; // Could fetch from an activity log table if it exists

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Dashboard - <?= h($companyName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.3);
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar simple style for dashboard */
        .sidebar {
            width: 260px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--glass-border);
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sidebar-logo {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-main);
            border-radius: 12px;
            transition: all 0.2s;
            font-weight: 500;
        }

        .nav-item:hover, .nav-item.active {
            background: var(--primary);
            color: white;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
        }

        .header-title p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: rgba(99, 102, 241, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--primary);
        }

        .stat-info h3 {
            font-size: 14px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .stat-info .value {
            font-size: 24px;
            font-weight: 700;
        }

        /* Quick Actions & Sections */
        .dashboard-sections {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .section-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        .section-card h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .action-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            text-decoration: none;
            color: var(--text-main);
            font-weight: 600;
            transition: all 0.2s;
        }

        .action-btn i {
            font-size: 20px;
            color: var(--primary);
        }

        .action-btn:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .module-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        @media (max-width: 1024px) {
            .dashboard-sections {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--glass-border); }
            .main-content { padding: 20px; }
            .action-list { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-logo">
            <i class="fas fa-rocket"></i>
            <span>OmmyERP</span>
        </div>
        
        <a href="dashboard.php" class="nav-item active">
            <i class="fas fa-th-large"></i>
            Dashboard
        </a>
        <a href="manage-employees.php" class="nav-item">
            <i class="fas fa-users"></i>
            Employees
        </a>
        <a href="../select-module.php" class="nav-item">
            <i class="fas fa-th"></i>
            App Launcher
        </a>
        <a href="../logout.php" class="nav-item" style="margin-top: auto; color: #ef4444;">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="header-title">
                <h1>Welcome, <?= h($_SESSION['full_name']) ?></h1>
                <p>Managing <strong><?= h($companyName) ?></strong> (<?= ucfirst($industryType) ?>)</p>
            </div>
            <div class="header-actions">
                <a href="../select-module.php" class="nav-item" style="background: white; border: 1px solid #e5e7eb;">
                    <i class="fas fa-external-link-alt"></i>
                    Go to ERP
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info">
                    <h3>Total Employees</h3>
                    <div class="value"><?= $totalEmployees ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-cubes"></i></div>
                <div class="stat-info">
                    <h3>Enabled Modules</h3>
                    <div class="value"><?= $totalModules ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-industry"></i></div>
                <div class="stat-info">
                    <h3>Industry</h3>
                    <div class="value"><?= ucfirst($industryType) ?></div>
                </div>
            </div>
        </div>

        <div class="dashboard-sections">
            <div class="section-card">
                <h2>Quick Management Actions</h2>
                <div class="action-list">
                    <a href="manage-employees.php" class="action-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>Register New Employee</span>
                    </a>
                    <a href="settings.php" class="action-btn">
                        <i class="fas fa-cog"></i>
                        <span>Company Settings</span>
                    </a>
                    <a href="modules.php" class="action-btn">
                        <i class="fas fa-puzzle-piece"></i>
                        <span>Configure Modules</span>
                    </a>
                    <a href="reports.php" class="action-btn">
                        <i class="fas fa-chart-bar"></i>
                        <span>Business Reports</span>
                    </a>
                </div>
            </div>

            <div class="section-card">
                <h2>Active Modules</h2>
                <div style="margin-top: 10px;">
                    <?php if (empty($modules)): ?>
                        <p style="color: var(--text-muted); font-size: 14px;">No modules enabled yet.</p>
                    <?php else: ?>
                        <?php foreach ($modules as $m): ?>
                            <span class="module-badge"><?= h($m['custom_label'] ?: $m['module_name']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 30px;">
                    <h2 style="font-size: 16px;">Quick Info</h2>
                    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.6;">
                        Your ERP instance is optimized for <strong><?= $industryType ?></strong>. 
                        You can manage your employees and track their activity across all enabled modules.
                    </p>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
