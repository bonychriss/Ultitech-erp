<?php
/**
 * Shared React shell for backup module.
 *
 * Expects: $page_title, $employeeHeaderTitle, $backupHeadMarkup, $assets
 */
$GLOBALS['_erp_header_style_linked'] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <?= backupDeskShellHeadExtras() ?>
    <?= $backupHeadMarkup ?>
</head>
<body class="dashboard page-backup-desk">

<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<style>
body.page-backup-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-backup-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-backup-desk,
body.page-backup-desk.dashboard,
body.page-backup-desk .layout-main-wrapper,
body.page-backup-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-backup-desk .employee-header.employee-header--backup-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-backup-desk .employee-header--backup-desk::after { display: none !important; }
body.page-backup-desk .employee-header--backup-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-backup-desk .employee-header--backup-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-backup-desk .employee-header--backup-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 500 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
}
body.page-backup-desk .employee-header--backup-desk .header-right.header-actions-tray {
    margin-left: auto !important;
}
main.main-content.backup-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.backup-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 767.98px) {
    body.page-backup-desk .employee-header.employee-header--backup-desk { padding: 0 0.75rem !important; }
    main.main-content.backup-react-root { padding: 0 0.75rem 1.5rem !important; }
}
</style>

<main class="main-content backup-react-root" role="main">
    <div id="root">
        <div style="padding:24px 0;color:#64748b;font-family:var(--erp-font-family,system-ui,sans-serif);">
            Loading backup desk�
        </div>
    </div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</main>

</body>
</html>
