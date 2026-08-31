<?php
require_once __DIR__ . '/../config/paths.php';
$logoBase = $rootPath;
$modulesLink = $rootPath . 'select-module.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Stock System' : 'Stock Management System'; ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Stock: consistent alerts + redirect -->
    <script src="<?= htmlspecialchars($stockBasePath) ?>assets/js/stock-alert.js"></script>
    <!-- Bootstrap 5 JS Bundle (Loaded early to prevent race conditions) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Stock CSS -->
    <link href="<?= htmlspecialchars($stockBasePath) ?>assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    
    <!-- Main App CSS -->
    <link href="<?= htmlspecialchars($rootPath) ?>assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>

    <style>
        /* Layout Adjustment for Integration */
        .stock-container { width: 100%; }
        body.dashboard .layout-main-wrapper { align-items: stretch; }
        body.dashboard .layout-main-wrapper > .flex-grow-1 { min-height: 0; display: flex; flex-direction: column; }
        body.dashboard main.main-content { flex: 1 1 auto; min-height: 0; overflow: visible !important; }
        main.main-content .stock-container, main.main-content #root { width: 100%; }
        
        /* Global SweetAlert Button Styles */
        .swal2-clean-confirm { padding: 10px 24px !important; font-weight: 700 !important; font-size: 14px !important; border-radius: 12px !important; transition: all 0.2s !important; margin: 0 5px !important; border: none !important; color: white !important; }
        .swal2-clean-cancel { padding: 10px 24px !important; font-weight: 700 !important; font-size: 14px !important; border-radius: 12px !important; transition: all 0.2s !important; margin: 0 5px !important; border: none !important; background: #f1f5f9 !important; color: #64748b !important; }
        .swal2-clean-confirm.success-btn { background-color: #3b82f6 !important; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2) !important; }
        .swal2-clean-confirm.danger-btn { background-color: #ef4444 !important; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2) !important; }
    </style>

    <script>
    // Inject Clean Executive style into all SweetAlert calls
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof Swal !== 'undefined') {
            const originalFire = Swal.fire;
            Swal.fire = function(...args) {
                let opts = args[0] || {};
                if (typeof opts === 'string') {
                    opts = { title: args[0], text: args[1], icon: args[2] };
                }

                // Default "Clean Executive" Overrides
                const defaults = {
                    buttonsStyling: false,
                    background: '#ffffff',
                    borderRadius: '24px',
                    padding: '2rem',
                    customClass: {
                        confirmButton: 'swal2-clean-confirm ' + (opts.icon === 'error' || opts.icon === 'warning' ? 'danger-btn' : 'success-btn'),
                        cancelButton: 'swal2-clean-cancel',
                        title: 'text-xl font-bold text-slate-800 mb-2',
                        htmlContainer: 'text-sm text-slate-500'
                    }
                };

                // Merge and fire
                return originalFire.call(Swal, { ...defaults, ...opts });
            };
        }
    });
    </script>
</head>
<body class="dashboard<?= !empty($bodyExtraClass) ? ' ' . htmlspecialchars((string) $bodyExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>">
<?php
include_once __DIR__ . '/../../includes/header_employee.php'; 
?>

