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
$timeSettingsUrl = function_exists('company_url')
    ? company_url('admin/time-settings.php?module=settings')
    : app_url('/admin/time-settings.php?module=settings');

if (!function_exists('time_settings_datetime_local')) {
    function time_settings_datetime_local($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $dt = date_create($value);
        return $dt ? $dt->format('Y-m-d\TH:i') : '';
    }
}

$currentTz = 'Africa/Dar_es_Salaam';
$currentFmt = '24';
$overrideEnabled = 0;
$overrideTimeVal = '';

try {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
    $dbSettings = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $currentTz = (string) ($dbSettings['system_timezone'] ?? 'Africa/Dar_es_Salaam');
    $currentFmt = (string) ($dbSettings['system_time_format'] ?? '24');
    $overrideEnabled = (int) ($dbSettings['system_time_override_enabled'] ?? 0);
    $overrideTimeVal = (string) ($dbSettings['system_override_time'] ?? '');
} catch (Throwable $e) {
    error_log('time-settings.php load: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_time_settings'])) {
    $timezone = $_POST['timezone'] ?? 'Africa/Dar_es_Salaam';
    $time_format = $_POST['time_format'] ?? '24';
    $enable_override = isset($_POST['enable_time_override']) ? 1 : 0;
    $override_time = trim((string) ($_POST['override_time'] ?? ''));
    if ($override_time !== '') {
        $override_time = str_replace('T', ' ', $override_time);
        if (strlen($override_time) === 16) {
            $override_time .= ':00';
        }
    }

    $settings = [
        'system_timezone' => $timezone,
        'system_time_format' => $time_format,
        'system_time_override_enabled' => $enable_override,
        'system_override_time' => $override_time,
    ];

    try {
        foreach ($settings as $key => $val) {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $val]);
        }
        $_SESSION['flash_message'] = 'Time settings updated successfully.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: ' . $timeSettingsUrl);
    exit();
}

$flashMsg = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
if ($flashMsg !== null) {
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$overrideTimeInput = time_settings_datetime_local($overrideTimeVal);
$arimaCssUrl = function_exists('app_url') ? app_url('/assets/css/arima-local.css') : '/assets/css/arima-local.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Settings | <?= $esc(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="<?= $esc($arimaCssUrl) ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Arima:wght@400;500;600;700&display=swap">
    <style>
        :root {
            --time-settings-font: 'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif;
        }
        body { background: #f8fafc; color: #1e293b; }
        body.time-settings-page .main-content-wrapper,
        body.time-settings-page .main-content-wrapper *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad) {
            font-family: var(--time-settings-font) !important;
        }
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
        .warning-box {
            background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 20px;
        }
        .warning-header {
            display: flex; align-items: center; gap: 10px;
            color: #b91c1c; font-weight: 700; font-size: 13px; margin-bottom: 10px;
        }
        .warning-box > p { font-size: 13px; color: #7f1d1d; margin: 0 0 16px; line-height: 1.5; }
        .override-toggle {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px; background: #fff; border: 1px solid #fecaca;
            border-radius: 10px; cursor: pointer; margin-bottom: 16px;
        }
        .override-toggle input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .override-toggle label { cursor: pointer; font-weight: 600; font-size: 14px; color: #1e293b; margin: 0; }
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
            .btn-save { width: 100%; padding: 14px 24px; }
        }
    </style>
</head>
<body class="time-settings-page">
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Time &amp; Format</h1>
                <p class="text-sm text-slate-400 mt-1 mb-0">Configure how time is recorded and displayed system-wide</p>
            </div>
            <a href="<?= $esc($settingsHubUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Settings
            </a>
        </div>

        <?php if ($flashMsg !== null): ?>
            <div class="alert-flash <?= $flashType === 'error' ? 'error' : 'success' ?>"><?= $esc($flashMsg) ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="hidden" name="update_time_settings" value="1">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#timezone-format" class="is-active">Timezone &amp; Format</a></li>
                        <li><a href="#manual-override">Manual Override</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="timezone-format">
                        <div class="section-header">
                            <h2 class="section-title">Global Time Synchronization</h2>
                            <p class="section-subtitle">Master timezone and display format for all timestamps.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="timezone">System Timezone</label>
                            <div>
                                <select name="timezone" id="timezone" class="form-input">
                                    <option value="Africa/Dar_es_Salaam" <?= $currentTz === 'Africa/Dar_es_Salaam' ? 'selected' : '' ?>>
                                        Africa/Dar es Salaam (EAT — GMT+3)
                                    </option>
                                    <option value="UTC" <?= $currentTz === 'UTC' ? 'selected' : '' ?>>
                                        UTC (Standard Time)
                                    </option>
                                </select>
                                <p class="help-text">Used for attendance logs, vouchers, and reports.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="time_format">Display Format</label>
                            <div>
                                <select name="time_format" id="time_format" class="form-input">
                                    <option value="24" <?= $currentFmt === '24' ? 'selected' : '' ?>>24-Hour (e.g. 14:30)</option>
                                    <option value="12" <?= $currentFmt === '12' ? 'selected' : '' ?>>12-Hour (e.g. 2:30 PM)</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="manual-override">
                        <div class="section-header">
                            <h2 class="section-title">Manual Override</h2>
                            <p class="section-subtitle">Emergency fixed time for all attendance records (admin only).</p>
                        </div>

                        <div class="warning-box">
                            <div class="warning-header">
                                <i class="fas fa-triangle-exclamation"></i>
                                <span>USE WITH CAUTION</span>
                            </div>
                            <p>
                                Enabling this forces the system to use a fixed manual time for <strong>all</strong> attendance records.
                                Use only for emergencies or testing.
                            </p>

                            <div class="override-toggle" id="override_toggle_wrap">
                                <input type="checkbox" name="enable_time_override" id="enable_time_override" value="1"
                                    <?= $overrideEnabled ? 'checked' : '' ?>>
                                <label for="enable_time_override">Activate manual time override</label>
                            </div>

                            <div id="override_group" style="display: <?= $overrideEnabled ? 'block' : 'none' ?>;">
                                <div class="form-row" style="grid-template-columns: 1fr; margin-bottom: 0;">
                                    <label class="form-label" for="override_time" style="padding-top: 0;">Target system time</label>
                                    <div>
                                        <input type="datetime-local" name="override_time" id="override_time" class="form-input"
                                               value="<?= $esc($overrideTimeInput) ?>">
                                        <p class="help-text">Attendance will be recorded using exactly this timestamp.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-4 mb-20 flex-wrap">
                        <button type="button" onclick="location.href='<?= $esc($settingsHubUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                        <button type="submit" class="btn-save">Save Time Config</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var chk = document.getElementById('enable_time_override');
    var group = document.getElementById('override_group');
    var wrap = document.getElementById('override_toggle_wrap');

    function toggleOverrideInput() {
        if (chk && group) {
            group.style.display = chk.checked ? 'block' : 'none';
        }
    }

    if (chk) {
        chk.addEventListener('change', toggleOverrideInput);
    }
    if (wrap && chk) {
        wrap.addEventListener('click', function (e) {
            if (e.target === chk || e.target.tagName === 'LABEL') return;
            chk.checked = !chk.checked;
            toggleOverrideInput();
        });
    }

    var navLinks = document.querySelectorAll('.section-nav a[href^="#"]');
    var sections = document.querySelectorAll('.editor-section[id]');
    if (navLinks.length && sections.length) {
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
    }
})();
</script>
</body>
</html>
