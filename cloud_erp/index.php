<?php
require_once __DIR__ . '/core/ModuleLoader.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';

use Core\ModuleLoader;
use Core\Auth;

Auth::check();
$user = Auth::user();

$loader = new ModuleLoader(__DIR__ . '/modules');
$loader->scanAndRegister();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate ERP</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8f9fa; }
        .sidebar { min-width: 260px; background: #1e1e2d; color: #fff; min-height: 100vh; }
        .sidebar a { color: #a2a3b7; text-decoration: none; padding: 12px 24px; display: block; }
        .sidebar a:hover, .sidebar a.active { color: #fff; background: #1b1b28; }
        .card { border: none; box-shadow: 0 0 20px 0 rgba(76, 87, 125, 0.02); }
    </style>
</head>
<body>
    <div class="d-flex" x-data="{ sidebarOpen: true }">
        <!-- Sidebar -->
        <aside class="sidebar" x-show="sidebarOpen" x-transition>
            <div class="p-4">
                <h4 class="fw-bold">Ultimate ERP</h4>
            </div>
            <nav class="mt-2">
                <a href="index.php" class="active">Dashboard</a>
                <?php foreach($loader->getModules() as $name => $config): ?>
                    <?php if($config['enabled']): ?>
                        <a href="modules/<?= $name ?>/index.php"><?= $name ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-grow-1">
            <header class="bg-white p-3 border-bottom d-flex justify-content-between align-items-center">
                <button class="btn btn-light btn-sm" @click="sidebarOpen = !sidebarOpen">☰</button>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <?= htmlspecialchars($user['erp_user_name']) ?> (<?= htmlspecialchars($user['erp_company_name']) ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </div>
            </header>
            
            <div class="p-4">
                <div class="row mb-4">
                    <div class="col">
                        <h2 class="h4">Dashboard</h2>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- KPI Cards Example -->
                    <div class="col-md-3">
                        <div class="card p-4">
                            <span class="text-muted small">Total Revenue</span>
                            <h3 class="fw-bold mt-2">$0.00</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card p-4">
                            <span class="text-muted small">Active Projects</span>
                            <h3 class="fw-bold mt-2">0</h3>
                        </div>
                    </div>
                </div>
                
                <div class="mt-5">
                    <div class="card p-4">
                        <h5>System Status</h5>
                        <p class="text-success">Core Framework Loaded.</p>
                        <p>Loaded Modules: <?= count($loader->getModules()) ?></p>
                        <a href="install.php" class="btn btn-primary">Run Database Installer</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
