<?php
/**
 * Reusable Layout Component
 * 
 * Provides a standardized page structure with Sidebar and Bootstrap 5.
 * 
 * Usage:
 * require_once 'layout.php';
 * startLayout('Page Title', 'active-module-id');
 * // HTML Content
 * endLayout();
 */

require_once __DIR__ . '/includes/functions.php';

// Ensure session is started for auth checks in sidebar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Begin the HTML layout
 *
 * @param string $title Page Title
 * @param string|null $active_module_override Optional override for the active sidebar link
 */
function startLayout($title = 'Admin Panel', $active_module_override = null) {
    // If $active_module_override is provided, we assign it to $active_module
    // which sidebar.php uses to highlight the current item.
    if ($active_module_override) {
        $active_module = $active_module_override;
    }
    // Note: sidebar.php logic also checks global state/URL, 
    // but local variable in this scope will take precedence if passed to include.
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        
        <!-- Bootstrap 5 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        
        <!-- Custom Global Styles -->
        <style>
             body {
                 background-color: #f8f9fa;
                 min-height: 100vh;
             }
             /* Ensure content doesn't sit under the header if one exists, 
                though sidebar.php handles margin-left for .main-content */
        </style>
    </head>
    <body>
    
    <?php
    // Include the Sidebar
    // We use include logic. sidebar.php expects $active_module to be defined in current scope if we want to force it.
    include __DIR__ . '/sidebar.php'; 
    ?>

    <!-- Main Content Wrapper -->
    <!-- .main-content class triggers the margin-left from sidebar.php styles -->
    <div class="main-content">
        <!-- Optional Top Header/Navbar could go here -->
        <header class="d-flex justify-content-between align-items-center p-3 mb-4 border-bottom bg-white shadow-sm d-none d-lg-flex">
            <h1 class="h4 m-0 text-muted"><?= htmlspecialchars($title) ?></h1>
            <div class="user-menu">
                <!-- Additional header items can go here -->
            </div>
        </header>

        <div class="container-fluid px-4 pb-4">
    <?php
}

/**
 * Close the HTML layout
 */
function endLayout() {
    ?>
        </div> <!-- End container-fluid -->
    </div> <!-- End main-content -->

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
// Self-execution check for direct access
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    startLayout('Layout Preview');
    ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Layout Template</h5>
                    <p class="card-text">
                        This file is intended to be used as a layout template for other pages.
                    </p>
                    <div class="alert alert-info">
                        <strong>Usage:</strong><br>
                        <code>require_once 'layout.php';</code><br>
                        <code>startLayout('Page Title');</code><br>
                        <code>// Your Content</code><br>
                        <code>endLayout();</code>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Bootstrap 5 Ready</h5>
                    <p>The layout automatically includes:</p>
                    <ul>
                        <li>Bootstrap 5 CSS & JS</li>
                        <li>Responsive Sidebar</li>
                        <li>Main Content Wrapper</li>
                        <li>Mobile Toggle Support</li>
                    </ul>
                    <button class="btn btn-primary" onclick="alert('Bootstrap JS is working!')">Test Interaction</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    endLayout();
}
?>
