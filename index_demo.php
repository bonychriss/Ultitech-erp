<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Sidebar Demo</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar Column -->
            <div class="col-auto col-md-3 col-xl-2 px-sm-0 bg-white">
                <?php 
                // Simulate an active module for demo purposes
                $active_module = 'dashboard'; 
                include 'sidebar.php'; 
                ?>
            </div>

            <!-- Main Content Column -->
            <div class="col py-3">
                <!-- Mobile Toggle Button -->
                <button class="btn btn-primary d-md-none mb-3" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i> Menu
                </button>

                <h1>Welcome to the Dashboard</h1>
                <p class="lead">This is a simple, server-side rendered admin layout using PHP and Bootstrap 5.</p>
                
                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Content Area</h5>
                        <p class="card-text">
                            The sidebar is included via <code>include 'sidebar.php';</code>.
                            On mobile, use the toggle button above to open the menu.
                        </p>
                    </div>
                </div>

                 <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Feature Test</h5>
                        <button class="btn btn-outline-secondary" onclick="alert('Feature clicked')">Action</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
