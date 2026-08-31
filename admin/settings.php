<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
ensureSystemSettingsSchema();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['active_module'] = 'settings';

$message = '';
$error = '';

$settingsQs = static function (array $extra = []) {
    $qs = http_build_query(array_merge($_GET ?: [], $extra));
    return $qs === '' ? '' : ('?' . $qs);
};

$settingsHubUrl = (function_exists('company_url') ? company_url('admin/settings.php') : app_url('/admin/settings.php'))
    . $settingsQs(['module' => 'settings']);

if (!empty($_SESSION['flash_message'])) {
    if (($_SESSION['flash_type'] ?? '') === 'error') {
        $error = (string) $_SESSION['flash_message'];
    } else {
        $message = (string) $_SESSION['flash_message'];
    }
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_system_font'])) {
    $fontKey = (string) ($_POST['system_font'] ?? 'poppins');
    if (saveSystemFontKey($fontKey)) {
        $_SESSION['flash_message'] = 'System font updated successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_message'] = 'Could not save font selection. Please try again.';
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: ' . $settingsHubUrl);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_factory_reset'])) {
    $confirmText = trim((string) ($_POST['confirm_reset'] ?? ''));
    if (strtolower($confirmText) !== 'reset') {
        $error = 'Confirmation failed. Please type exactly "RESET" to confirm.';
    } else {
        $companyId = (int) (currentCompanyId() ?? 0);
        if ($companyId <= 0) {
            $error = 'Unable to resolve company context.';
        } else {
            try {
                // Perform the factory reset inside the tenant database
                $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
                $exclude = [
                    'users', 'companies', 'company_settings', 'system_settings',
                    'attendance_settings', 'payroll_settings', 'payroll_tax_bands',
                    'sales_settings', 'erp_settings', 'document_layouts',
                    'email_templates', 'erp_email_templates', 'language_translations',
                    'migrations', 'failed_jobs', 'jobs', 'sessions',
                    'erp_users', 'erp_roles', 'erp_user_roles', 'erp_departments',
                    'branches'
                ];
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $truncatedCount = 0;
                foreach ($tables as $table) {
                    if (in_array(strtolower($table), $exclude)) {
                        continue;
                    }
                    $pdo->exec("TRUNCATE TABLE `" . $table . "`");
                    $truncatedCount++;
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                
                // Delete tenant uploads folder
                $tenantStorage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
                if (is_dir($tenantStorage)) {
                    $deleteDir = function($dir) use (&$deleteDir) {
                        if (!is_dir($dir)) return false;
                        $items = array_diff(scandir($dir), ['.', '..']);
                        foreach ($items as $item) {
                            $path = $dir . DIRECTORY_SEPARATOR . $item;
                            if (is_dir($path)) {
                                $deleteDir($path);
                            } else {
                                @unlink($path);
                            }
                        }
                        return @rmdir($dir);
                    };
                    $deleteDir($tenantStorage);
                }
                
                $message = 'Factory reset completed successfully. Cleared ' . $truncatedCount . ' operational tables and all uploaded files.';
            } catch (Throwable $e) {
                $error = 'Factory reset failed: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Settings hub';
$rootPath = '/';
$logoBase = '/';
$modulesLink = app_url('/select-module.php');
$systemFontKey = getSystemFontKey();
$systemFontCatalog = getSystemFontCatalog();
$systemFontDef = getSystemFontDefinition($systemFontKey);
$systemFontStack = $systemFontDef['stack'] ?? "'Poppins', sans-serif";
$fontStacksForJs = [];
$fontLabelsForJs = [];
$fontGoogleForJs = [];
foreach ($systemFontCatalog as $fontId => $fontMeta) {
    $fontStacksForJs[$fontId] = $fontMeta['stack'];
    $fontLabelsForJs[$fontId] = $fontMeta['label'];
    $fontGoogleForJs[$fontId] = $fontMeta['google'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?><?php if (defined('COMPANY_NAME')): ?> Â· <?= htmlspecialchars(COMPANY_NAME); ?><?php endif; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <style>
        .settings-shell {
            font-family: var(--erp-font-family, <?= htmlspecialchars($systemFontStack, ENT_NOQUOTES, 'UTF-8') ?>);
            font-size: 16px;
            color: #374151;
        }
        .font-selector-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .font-preview-box {
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            background: #f8fafc;
            padding: 1rem 1.25rem;
            margin-top: 0.75rem;
        }
        .font-preview-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.35rem;
        }
        .font-preview-body {
            font-size: 0.95rem;
            color: #475569;
            margin: 0;
            line-height: 1.5;
        }
        .btn-font-save {
            background: #7c3aed;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.6rem 1.25rem;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-font-save:hover { background: #6d28d9; color: #fff; }
        .dash-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        a.hub-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        a.hub-card-link:hover .dash-card {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px -8px rgba(15, 23, 42, 0.12);
            border-color: #cbd5e1;
        }
        .hub-card-accent { border-left: 4px solid var(--hub-accent, #2563eb); }
        .badge-new {
            background: #128C7E;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 6px;
            vertical-align: middle;
            text-transform: uppercase;
        }
        /* Live Cleaning Animation Styles */
        .scanner-bar {
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent, #dc2626, transparent);
            position: absolute;
            top: 0;
            left: 0;
            animation: scan 2s infinite ease-in-out;
        }
        @keyframes scan {
            0% { top: 0%; }
            50% { top: 100%; }
            100% { top: 0%; }
        }
        .cleaning-icon-container {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 20px auto;
        }
        .cleaning-icon-bg {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 4px dashed rgba(220, 38, 38, 0.2);
            border-radius: 50%;
            animation: rotate-dashed 8s infinite linear;
            top: 0;
            left: 0;
        }
        @keyframes rotate-dashed {
            100% { transform: rotate(360deg); }
        }
        .cleaning-broom {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 28px;
            color: #dc2626;
            animation: sweep 1.5s infinite ease-in-out;
            transform-origin: bottom left;
        }
        @keyframes sweep {
            0%, 100% { transform: rotate(0deg) translate(0, 0); }
            50% { transform: rotate(-30deg) translate(-12px, -6px); }
        }
    </style>
</head>
<body class="dashboard">
<?php include_once __DIR__ . '/../includes/header_employee.php'; ?>

<main class="main-content settings-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-sliders-h text-[#2563EB]"></i><span>Settings hub</span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <span><i class="fas fa-calendar text-gray-400 me-1"></i><?php echo date('l, d M Y'); ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span>System configuration<?php if (defined('COMPANY_NAME')): ?> Â· <?= htmlspecialchars(COMPANY_NAME); ?><?php endif; ?></span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <?php if ($message !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px; font-weight: 500;">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px; font-weight: 500;">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <p class="text-secondary mb-4 mb-md-3">Manage system configurations across departments.</p>

            <div class="font-selector-card">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-2">
                    <div>
                        <h2 class="h5 fw-bold text-gray-900 mb-1">
                            <i class="fas fa-font text-[#7c3aed] me-2"></i>System font
                        </h2>
                        <p class="small text-secondary mb-0">Applies across modules, sidebars, forms, and dashboards for this company.</p>
                    </div>
                </div>
                <form method="post" id="systemFontForm">
                    <input type="hidden" name="update_system_font" value="1">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-5 col-lg-4">
                            <label for="system_font" class="form-label fw-semibold small text-secondary mb-1">Font family</label>
                            <select name="system_font" id="system_font" class="form-select" style="border-radius: 10px; padding: 0.65rem 0.9rem;">
                                <?php foreach ($systemFontCatalog as $fontId => $fontMeta): ?>
                                    <option value="<?= htmlspecialchars($fontId) ?>" <?= $fontId === $systemFontKey ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($fontMeta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 col-lg-3">
                            <button type="submit" class="btn-font-save w-100">
                                <i class="fas fa-check me-1"></i> Apply font
                            </button>
                        </div>
                    </div>
                    <div class="font-preview-box" id="fontPreviewBox" style="font-family: <?= htmlspecialchars($systemFontStack, ENT_NOQUOTES, 'UTF-8') ?>;">
                        <p class="font-preview-title mb-1">Preview — <?= htmlspecialchars($systemFontDef['label'] ?? 'Poppins') ?></p>
                        <p class="font-preview-body mb-0">The quick brown fox jumps over the lazy dog. 0123456789 · Payment voucher #1042 · Jumatano, 28 May 2026</p>
                    </div>
                </form>
            </div>

            <div class="row g-3 g-md-4">
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="company-settings.php<?= htmlspecialchars($settingsQs([])) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #2563eb;">
                            <i class="fas fa-building fa-lg mb-3 d-block" style="color: #2563eb;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Company settings</h2>
                            <p class="small text-secondary mb-0">Profile, branding, modules, tax and numbering per company.</p>
                        </div>
                    </a>
                </div>

                <?php if (function_exists('isSuperAdmin') && isSuperAdmin()): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="management.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #1d4ed8;">
                            <i class="fas fa-plus-square fa-lg mb-3 d-block" style="color: #1d4ed8;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Register new company</h2>
                            <p class="small text-secondary mb-0">Create a company tenant with default module setup.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="management.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #0f766e;">
                            <i class="fas fa-sitemap fa-lg mb-3 d-block" style="color: #0f766e;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Company management</h2>
                            <p class="small text-secondary mb-0">Monitor companies and switch active company context.</p>
                        </div>
                    </a>
                </div>

                <?php
                $hubCompanySlug = strtolower(trim((string) ($_GET['company_slug'] ?? '')));
                $hubCompanyId = (int) ($_GET['company_id'] ?? 0);
                $listUsersHubUrl = 'list-company-users.php' . ($hubCompanySlug !== '' ? '?company=' . rawurlencode($hubCompanySlug) : '');
                $aiHubParams = array_filter([
                    'module' => 'settings',
                    'company_slug' => $hubCompanySlug !== '' ? $hubCompanySlug : null,
                    'company_id' => $hubCompanyId > 0 ? $hubCompanyId : null,
                ]);
                $aiHubUrl = 'management.php?' . http_build_query($aiHubParams);
                ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="<?= htmlspecialchars($listUsersHubUrl) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #6366f1;">
                            <i class="fas fa-users fa-lg mb-3 d-block" style="color: #6366f1;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Company users &amp; emails</h2>
                            <p class="small text-secondary mb-0">All tenant users and login emails per company (login index).</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="sync-user-company-index.php" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #7c3aed;">
                            <i class="fas fa-sync fa-lg mb-3 d-block" style="color: #7c3aed;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Sync login index</h2>
                            <p class="small text-secondary mb-0">Rebuild <code>user_company_index</code> from tenant databases.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="<?= htmlspecialchars($aiHubUrl) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #10b981;">
                            <i class="fas fa-robot fa-lg mb-3 d-block" style="color: #10b981;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">AI Integration</h2>
                            <p class="small text-secondary mb-0">System-wide OpenAI key, usage limits, and connection test.</p>
                        </div>
                    </a>
                </div>
                <?php endif; ?>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="register_employee.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #2b2f42;">
                            <i class="fas fa-user-plus fa-lg mb-3 d-block" style="color: #2b2f42;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Register employee</h2>
                            <p class="small text-secondary mb-0">Add team members with roles and departments.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="whatsapp-settings.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #128C7E;">
                            <i class="fab fa-whatsapp fa-lg mb-3 d-block" style="color: #128C7E;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">WhatsApp group <span class="badge-new">New</span></h2>
                            <p class="small text-secondary mb-0">WhatsApp link for voucher sharing and notifications.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="time-settings.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card h-100 p-4">
                            <i class="fas fa-clock fa-lg text-primary mb-3 d-block"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Time &amp; format</h2>
                            <p class="small text-secondary mb-0">Timezone, 12/24 hour display, and time overrides.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="../attendance/settings.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card h-100 p-4">
                            <i class="fas fa-calendar-check fa-lg text-primary mb-3 d-block"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Attendance</h2>
                            <p class="small text-secondary mb-0">Work hours, grace periods, and attendance options.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="../modules/sales/settings/index.php<?= htmlspecialchars($settingsQs(['module' => 'sales'])) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #3b82f6;">
                            <i class="fas fa-shopping-cart fa-lg mb-3 d-block" style="color: #3b82f6;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Sales settings</h2>
                            <p class="small text-secondary mb-0">Quotations, catalog display, and company logo for sales docs.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="stock-purchase-settings.php<?= htmlspecialchars($settingsQs(['module' => 'settings'])) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #a855f7;">
                            <i class="fas fa-file-invoice-dollar fa-lg mb-3 d-block" style="color: #a855f7;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Stock purchase &amp; vouchers</h2>
                            <p class="small text-secondary mb-0">Set PO type (Internal / Abroad), link Stock Purchase payment vouchers, and route to Finance approval.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="email-settings.php<?= htmlspecialchars($settingsQs()) ?>" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #a855f7;">
                            <i class="fas fa-envelope-open-text fa-lg mb-3 d-block" style="color: #a855f7;"></i>
                            <h2 class="h5 fw-bold text-gray-900 mb-2">Email SMTP settings</h2>
                            <p class="text-sm text-gray-500 mb-0">SMTP/IMAP servers and the system mailbox used by payroll, sales, purchases, and more.</p>
                            <p class="small text-secondary mb-0">Setup outgoing mail server, credentials, and security for the Email module.</p>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#factoryResetModal" class="hub-card-link">
                        <div class="dash-card hub-card-accent h-100 p-4" style="--hub-accent: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2);">
                            <i class="fas fa-trash-alt fa-lg mb-3 d-block text-danger" style="color: #dc2626;"></i>
                            <h2 class="h5 fw-bold text-red-600 mb-2">Factory Reset</h2>
                            <p class="small text-secondary mb-0">Wipe all operational transactions, stock logs, attendance, and uploaded files for this company.</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

    </div>
</div>

<!-- Factory Reset Modal -->
<div class="modal fade" id="factoryResetModal" tabindex="-1" aria-labelledby="factoryResetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15); overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4" id="resetModalHeader">
                <h5 class="modal-title text-danger fw-bold d-flex align-items-center gap-2" id="factoryResetModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> DANGER: Factory Reset
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="resetModalCloseBtn"></button>
            </div>
            <form method="post" class="needs-validation" novalidate id="factoryResetForm">
                <input type="hidden" name="execute_factory_reset" value="1">
                
                <!-- Normal Confirm Body -->
                <div class="modal-body px-4 py-3" id="resetModalNormalBody">
                    <p class="mb-3 text-secondary" style="font-size: 14px; line-height: 1.5;">
                        You are about to perform a **Factory Reset** for <strong><?= htmlspecialchars(defined('COMPANY_NAME') ? COMPANY_NAME : 'this company') ?></strong>. 
                        This is an irreversible operation that will physically wipe all operating data.
                    </p>
                    
                    <div class="card bg-danger/5 border-danger/10 mb-3" style="border-radius: 12px; background: rgba(220, 38, 38, 0.03); border: 1px solid rgba(220, 38, 38, 0.1);">
                        <div class="card-body p-3">
                            <div class="fw-bold text-danger mb-2" style="font-size: 13px;">What will be permanently deleted:</div>
                            <ul class="text-secondary ps-3 mb-2" style="font-size: 12px; list-style-type: disc;">
                                <li>All payment vouchers and line items</li>
                                <li>All sales orders, quotations, and invoices</li>
                                <li>All inventory categories, stock logs, and products</li>
                                <li>All financial account ledgers and expense rows</li>
                                <li>All attendance check-in/out records</li>
                                <li>All physically uploaded images, invoices, and vouchers</li>
                            </ul>
                            <div class="fw-bold text-success" style="font-size: 13px;">What will be kept:</div>
                            <ul class="text-secondary ps-3 mb-0" style="font-size: 12px; list-style-type: disc;">
                                <li>Your administrator login accounts and user settings</li>
                                <li>Your company profile settings, colors, and module options</li>
                            </ul>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="confirm_reset" class="form-label fw-bold text-gray-700" style="font-size: 13px;">
                            To confirm, please type exactly <span class="text-danger fw-bold font-monospace">RESET</span> below:
                        </label>
                        <input type="text" class="form-control" id="confirm_reset" name="confirm_reset" placeholder="RESET" required autocomplete="off" style="border-radius: 10px; padding: 10px 14px;">
                        <div class="invalid-feedback">Please type RESET to proceed.</div>
                    </div>
                </div>
                
                <div class="modal-footer border-0 px-4 pb-4 pt-0" id="resetModalNormalFooter">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 10px; padding: 10px 18px; font-weight: 500;">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="border-radius: 10px; padding: 10px 20px; font-weight: 600; background-color: #dc2626;">
                        Yes, Delete All Data
                    </button>
                </div>

                <!-- Cleaning Progress Screen (hidden by default) -->
                <div id="resetCleaningScreen" class="d-none text-center px-4 py-5" style="position: relative; overflow: hidden;">
                    <div class="scanner-bar"></div>
                    <div class="cleaning-icon-container">
                        <div class="cleaning-icon-bg"></div>
                        <i class="fas fa-database fa-3x text-danger" style="margin-top: 26px;"></i>
                        <i class="fas fa-broom cleaning-broom"></i>
                    </div>
                    
                    <h4 class="fw-bold text-danger mb-1">Performing Factory Reset</h4>
                    <p class="text-secondary small mb-4">Please do not close this window or refresh the page.</p>
                    
                    <!-- Progress Bar -->
                    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                        <span class="text-secondary small font-monospace" id="cleaningProgressStatus">Scanning company environment...</span>
                        <span class="fw-bold text-danger small font-monospace" id="cleaningProgressPercent">0%</span>
                    </div>
                    <div class="progress mb-4" style="height: 8px; border-radius: 10px; background-color: #f1f5f9;">
                        <div class="progress-bar bg-danger progress-bar-striped progress-bar-animated" id="cleaningProgressBar" role="progressbar" style="width: 0%; border-radius: 10px;"></div>
                    </div>
                    
                    <!-- Checklist items -->
                    <div class="text-start mx-auto" style="max-width: 320px; font-size: 13px;">
                        <div class="mb-2 text-secondary" id="step-scan">
                            <i class="far fa-circle me-2"></i>Scanning database tables...
                        </div>
                        <div class="mb-2 text-secondary" id="step-wipe">
                            <i class="far fa-circle me-2"></i>Wiping transactional records...
                        </div>
                        <div class="mb-2 text-secondary" id="step-media">
                            <i class="far fa-circle me-2"></i>Purging uploaded files...
                        </div>
                        <div class="mb-0 text-secondary" id="step-safeguard">
                            <i class="far fa-circle me-2"></i>Securing structural settings...
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fontStacks = <?= json_encode($fontStacksForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fontLabels = <?= json_encode($fontLabelsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fontGoogle = <?= json_encode($fontGoogleForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fontSelect = document.getElementById('system_font');
    var fontPreview = document.getElementById('fontPreviewBox');
    var loadedFontLinks = {};

    function loadGoogleFont(url) {
        if (!url || loadedFontLinks[url]) return;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
        loadedFontLinks[url] = true;
    }

    function updateFontPreview() {
        if (!fontSelect || !fontPreview) return;
        var key = fontSelect.value;
        var stack = fontStacks[key] || fontStacks.poppins;
        var label = fontLabels[key] || 'Preview';
        loadGoogleFont(fontGoogle[key] || '');
        fontPreview.style.fontFamily = stack;
        var title = fontPreview.querySelector('.font-preview-title');
        if (title) title.textContent = 'Preview — ' + label;
    }

    if (fontSelect) {
        fontSelect.addEventListener('change', updateFontPreview);
        updateFontPreview();
    }

    var resetForm = document.getElementById('factoryResetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            var confirmInput = document.getElementById('confirm_reset');
            if (confirmInput.value.trim().toUpperCase() !== 'RESET') {
                e.preventDefault();
                confirmInput.classList.add('is-invalid');
                return;
            }
            
            e.preventDefault(); // Prevent standard instant post
            
            // Hide standard modal components
            document.getElementById('resetModalHeader').classList.add('d-none');
            document.getElementById('resetModalNormalBody').classList.add('d-none');
            document.getElementById('resetModalNormalFooter').classList.add('d-none');
            
            // Show custom cleaning overlay screen
            var cleanScreen = document.getElementById('resetCleaningScreen');
            cleanScreen.classList.remove('d-none');
            
            var steps = [
                { id: 'step-scan', text: 'Scanning database tables...' },
                { id: 'step-wipe', text: 'Wiping transactional records...' },
                { id: 'step-media', text: 'Purging uploaded files...' },
                { id: 'step-safeguard', text: 'Securing structural settings...' }
            ];
            
            var progressBar = document.getElementById('cleaningProgressBar');
            var progressPercent = document.getElementById('cleaningProgressPercent');
            var progressStatus = document.getElementById('cleaningProgressStatus');
            
            var currentStep = 0;
            
            function runCleaningStep() {
                if (currentStep < steps.length) {
                    var step = steps[currentStep];
                    
                    // Mark previous step as successfully cleared
                    if (currentStep > 0) {
                        var prevStepEl = document.getElementById(steps[currentStep - 1].id);
                        if (prevStepEl) {
                            prevStepEl.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>' + steps[currentStep - 1].text;
                        }
                    }
                    
                    // Show current active sweeping task
                    var activeStepEl = document.getElementById(step.id);
                    if (activeStepEl) {
                        activeStepEl.innerHTML = '<i class="fas fa-spinner fa-spin text-danger me-2"></i><strong>' + step.text + '</strong>';
                        progressStatus.textContent = step.text;
                    }
                    
                    // Update smooth layout percentage
                    var percent = Math.round(((currentStep + 1) / steps.length) * 100);
                    progressBar.style.width = percent + '%';
                    progressPercent.textContent = percent + '%';
                    
                    currentStep++;
                    setTimeout(runCleaningStep, 900); // Wiping delay
                } else {
                    // Mark final step completed
                    var lastStepEl = document.getElementById(steps[steps.length - 1].id);
                    if (lastStepEl) {
                        lastStepEl.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>' + steps[steps.length - 1].text;
                    }
                    progressStatus.textContent = 'Reset completed! Reloading environments...';
                    
                    setTimeout(function() {
                        resetForm.submit(); // Submit the form to perform real data deletion
                    }, 400);
                }
            }
            
            runCleaningStep();
        });
    }
});
</script>
</body>
</html>
