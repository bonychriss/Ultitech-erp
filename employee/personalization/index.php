<?php

require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$effectiveFontDef = getSystemFontDefinition(getEffectiveFontKey($userId));
$userFontKey = getUserFontKey($userId);

$module = isset($_GET['module']) ? (string) $_GET['module'] : 'personalization';
$fontUrl = function_exists('company_url')
    ? company_url('employee/personalization/system-font.php', null) . '?module=' . rawurlencode($module)
    : app_url('/employee/personalization/system-font.php?module=' . rawurlencode($module));

$page_title = 'Personalization';
$employeeHeaderTitle = 'Personalization';
$active_module = 'personalization';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <?= erp_get_theme_init_html() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/style.css')) ?>?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/css/style.css') ?>">
    <?= erp_get_dark_theme_head_html() ?>
    <style>
        body.page-personalization {
            background: #f8fafc;
        }
        .pers-shell {
            max-width: 920px;
            margin: 0 auto;
            padding: 0 1.25rem 2rem;
        }
        .pers-intro {
            margin-bottom: 1.25rem;
            color: #64748b;
            font-size: 0.875rem;
        }
        .pers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }
        .pers-tile {
            display: block;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        }
        .pers-tile:hover {
            color: inherit;
            border-color: #c4b5fd;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.12);
            transform: translateY(-1px);
        }
        .pers-tile-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.875rem;
            background: #f5f3ff;
            color: #7c3aed;
            font-size: 1.1rem;
        }
        .pers-tile h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.35rem;
        }
        .pers-tile p {
            margin: 0;
            font-size: 0.8125rem;
            line-height: 1.5;
            color: #64748b;
        }
        .pers-tile-meta {
            margin-top: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #7c3aed;
        }
    </style>
</head>
<body class="dashboard page-personalization">

<?php require_once __DIR__ . '/../../includes/header_employee.php'; ?>

<div class="pers-shell">
    <p class="pers-intro mb-0 pt-2">
        Customize how the system looks and feels for your account.
    </p>

    <div class="pers-grid mt-3">
        <a href="<?= htmlspecialchars($fontUrl) ?>" class="pers-tile">
            <div class="pers-tile-icon"><i class="fas fa-font" aria-hidden="true"></i></div>
            <h2>System Font</h2>
            <p>Choose your preferred font style for menus, forms, and dashboards.</p>
            <div class="pers-tile-meta">
                Active:
                <?= htmlspecialchars($effectiveFontDef['label'] ?? 'Poppins') ?>
                <?= $userFontKey === null ? '(company default)' : '(your choice)' ?>
            </div>
        </a>
    </div>
</div>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
<?= erp_get_system_font_body_override_html() ?>
<?= erp_get_dark_theme_body_override_html() ?>
</body>
</html>
