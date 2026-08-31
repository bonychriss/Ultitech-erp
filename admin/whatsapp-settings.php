<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
ensureSystemSettingsSchema();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['active_module'] = 'settings';

$settingsHubUrl = function_exists('company_url')
    ? company_url('admin/settings.php?module=settings')
    : app_url('/admin/settings.php?module=settings');
$whatsappSettingsUrl = function_exists('company_url')
    ? company_url('admin/whatsapp-settings.php?module=settings')
    : app_url('/admin/whatsapp-settings.php?module=settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notification_settings'])) {
    $groupLink = trim((string) ($_POST['whatsapp_group_link'] ?? ''));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('whatsapp_group_link', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$groupLink]);

        $_SESSION['flash_message'] = 'WhatsApp settings updated successfully.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: ' . $whatsappSettingsUrl);
    exit();
}

$whatsappGroupLink = '';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'whatsapp_group_link' LIMIT 1");
    $stmt->execute();
    $whatsappGroupLink = (string) ($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('whatsapp-settings.php load: ' . $e->getMessage());
}

$flashMsg = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
if ($flashMsg !== null) {
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Settings | <?= $esc(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css', ENT_QUOTES, 'UTF-8') ?>">
    <?php if (function_exists('renderSystemFontHeadMarkup')) { renderSystemFontHeadMarkup(); } ?>
    <style>
        body { font-family: var(--erp-font-family, 'Poppins', sans-serif); background: #f8fafc; color: #1e293b; }
        .main-content-wrapper { padding: 2rem; }
        .page-shell { padding-left: 4rem; }
        .editor-shell { max-width: 1140px; margin: 0 auto; }
        .editor-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
        }
        .editor-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 2rem; align-items: start; }
        .section-nav { position: sticky; top: 96px; align-self: start; }
        .section-nav ul { list-style: none; margin: 0; padding: 0; }
        .section-nav li + li { margin-top: 0.5rem; }
        .section-nav a {
            display: block; padding: 0.45rem 0.75rem; border-radius: 8px;
            color: #64748b; font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s ease;
        }
        .section-nav a:hover { background: #eff6ff; color: #2563eb; }
        .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
        .editor-main { min-width: 0; }
        .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
        .editor-section:last-of-type { margin-bottom: 1.5rem; }
        .section-header { margin-bottom: 1.25rem; }
        .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
        .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
        .form-row:last-child { margin-bottom: 0; }
        .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
        .form-input {
            width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
        }
        .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
        .feature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px; }
        .feature-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff;
        }
        .feature-icon { color: #25d366; font-size: 16px; margin-top: 3px; flex-shrink: 0; }
        .feature-desc h4 { margin: 0 0 4px; font-size: 14px; font-weight: 600; color: #1e293b; }
        .feature-desc p { margin: 0; font-size: 12px; color: #64748b; line-height: 1.45; }
        .btn-save {
            background: #7c3aed !important; color: white !important; padding: 14px 48px;
            border-radius: 12px; font-weight: 600; font-size: 15px; border: none;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        }
        .btn-save:hover { background: #6d28d9 !important; }
        .btn-cancel { border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s; }
        .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
        .alert-flash { margin-bottom: 1.5rem; border-radius: 12px; padding: 12px 16px; font-size: 14px; font-weight: 500; }
        .alert-flash.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
        .alert-flash.error { border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; }
        @media (max-width: 992px) {
            .main-content-wrapper { padding: 1rem !important; }
            .page-shell { padding-left: 0; }
            .editor-topbar { flex-direction: column; align-items: flex-start; }
            .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
            .section-nav { position: static; }
            .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
            .section-nav li + li { margin-top: 0; }
            .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
            .form-label { padding-top: 0; font-size: 13px; }
            .feature-grid { grid-template-columns: 1fr; }
            .btn-save { width: 100%; padding: 14px 24px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">WhatsApp Integration</h1>
                <p class="text-sm text-slate-400 mt-1 mb-0">Connect office operations to WhatsApp for faster approvals</p>
            </div>
            <a href="<?= $esc($settingsHubUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Settings
            </a>
        </div>

        <?php if ($flashMsg !== null): ?>
            <div class="alert-flash <?= $flashType === 'error' ? 'error' : 'success' ?>"><?= $esc($flashMsg) ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="hidden" name="update_notification_settings" value="1">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#group-link" class="is-active">Group Link</a></li>
                        <li><a href="#features">Features</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="group-link">
                        <div class="section-header">
                            <h2 class="section-title">Departmental Group Sharing</h2>
                            <p class="section-subtitle">Configure the destination group for voucher notifications.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="whatsapp_group_link">Group Invitation Link</label>
                            <div>
                                <input type="url" name="whatsapp_group_link" id="whatsapp_group_link" class="form-input"
                                       placeholder="https://chat.whatsapp.com/invite/..."
                                       value="<?= $esc($whatsappGroupLink) ?>">
                                <p class="help-text">
                                    <i class="fab fa-whatsapp"></i>
                                    Find this in your WhatsApp Group settings under &ldquo;Invite via Link&rdquo;.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="features">
                        <div class="section-header">
                            <h2 class="section-title">What this enables</h2>
                            <p class="section-subtitle">How staff use the group link after it is saved.</p>
                        </div>

                        <div class="feature-grid">
                            <div class="feature-item">
                                <i class="fas fa-check-circle feature-icon"></i>
                                <div class="feature-desc">
                                    <h4>Instant Notifications</h4>
                                    <p>Alert your finance team as soon as a voucher is generated.</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-check-circle feature-icon"></i>
                                <div class="feature-desc">
                                    <h4>One-Tap Access</h4>
                                    <p>Group members can open the link to view voucher details directly.</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-check-circle feature-icon"></i>
                                <div class="feature-desc">
                                    <h4>Send to Group</h4>
                                    <p>Employees can share new payment vouchers to the configured group from the app.</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-check-circle feature-icon"></i>
                                <div class="feature-desc">
                                    <h4>Department Routing</h4>
                                    <p>Keep approvals moving without manual follow-ups across teams.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-4 mb-20 flex-wrap">
                        <button type="button" onclick="location.href='<?= $esc($settingsHubUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                        <button type="submit" class="btn-save">Save Configuration</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var navLinks = document.querySelectorAll('.section-nav a[href^="#"]');
    var sections = document.querySelectorAll('.editor-section[id]');
    if (!navLinks.length || !sections.length) return;

    function setActiveNav(id) {
        navLinks.forEach(function (link) {
            link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
        });
    }

    navLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            var target = document.querySelector(link.getAttribute('href'));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setActiveNav(target.id);
        });
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) setActiveNav(entry.target.id);
            });
        }, { rootMargin: '-20% 0px -60% 0px', threshold: 0 });
        sections.forEach(function (section) { observer.observe(section); });
    }
})();
</script>
</body>
</html>
