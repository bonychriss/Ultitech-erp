<?php
/**
 * Suggest React shell with ERP native sidebar (header_employee).
 * Expects: $page_title, $employeeHeaderTitle, $employeeHeaderSubtitle,
 *          $employeeHeaderExtraClass, $assets, $cfgJson
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars((string) $page_title) ?><?php if (defined('COMPANY_NAME')): ?> - <?= htmlspecialchars((string) COMPANY_NAME); ?><?php endif; ?></title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php if (!empty($assets['cssFile'])): ?>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script>
      window.__SUGGEST_CFG__ = <?= $cfgJson ?>;
    </script>
</head>
<body class="dashboard page-exp-desk page-suggest-react">

<?php include dirname(__DIR__) . '/includes/header_employee.php'; ?>

<style>
body.page-suggest-react.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-suggest-react.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-suggest-react,
body.page-suggest-react.dashboard,
body.page-suggest-react .layout-main-wrapper,
body.page-suggest-react .layout-main-wrapper > .flex-grow-1 {
    background: #eef3f5 !important;
}
body.page-suggest-react .employee-header.employee-header--exp-desk {
    background: #eef3f5 !important;
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
body.page-suggest-react .employee-header--exp-desk::after { display: none !important; }
body.page-suggest-react .employee-header--exp-desk .header-content {
    display: flex !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    background: transparent !important;
}
body.page-suggest-react .employee-header--exp-desk .employee-header-page-title {
    font-family: 'DM Sans', var(--erp-font-family, system-ui, sans-serif) !important;
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f1c24 !important;
    letter-spacing: -0.02em;
}
main.main-content.suggest-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #eef3f5 !important;
}
main.main-content.suggest-react-root #root {
    width: 100%;
    min-height: 320px;
}
main.suggest-react-root .sg-btn,
main.suggest-react-root button.sg-btn {
    border-radius: 999px !important;
}
@media (max-width: 767.98px) {
    body.page-suggest-react .employee-header.employee-header--exp-desk { padding: 0 0.75rem !important; }
    main.main-content.suggest-react-root { padding: 0 0.75rem 1.5rem !important; }
}
</style>

<main class="main-content suggest-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;">
            <strong>JavaScript is required</strong> for the Suggest workspace.
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
