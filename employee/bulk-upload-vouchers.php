<?php
require_once '../includes/functions.php';
requireLogin();

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$prefix = '../';
$backUrl = 'my-vouchers.php?module=voucher';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Vouchers - Coming Soon</title>
    <script>
        (function () {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        body.dashboard { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        body.dashboard .header, body.dashboard .employee-header { background: transparent !important; border: none !important; box-shadow: none !important; }

        .cs-wrap {
            min-height: calc(100vh - 160px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }
        .cs-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.06);
            padding: 52px 44px;
            max-width: 560px;
            width: 100%;
            text-align: center;
            font-family: 'Inter', sans-serif;
        }
        .cs-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #f3e8ff;
            color: #7c3aed;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 7px 15px;
            border-radius: 9999px;
            margin-bottom: 26px;
        }
        .cs-icon {
            width: 96px;
            height: 96px;
            margin: 0 auto 26px;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            box-shadow: 0 14px 30px rgba(124, 58, 237, 0.32);
            position: relative;
        }
        .cs-icon svg { color: #fff; }
        .cs-icon::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 32px;
            border: 2px dashed rgba(124, 58, 237, 0.35);
            animation: cs-spin 14s linear infinite;
        }
        @keyframes cs-spin { to { transform: rotate(360deg); } }
        .cs-card h1 { font-size: 1.7rem; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
        .cs-card p { font-size: 0.98rem; color: #64748b; line-height: 1.6; margin: 0 auto 26px; max-width: 420px; }
        .cs-progress {
            height: 8px;
            background: #eef2f7;
            border-radius: 9999px;
            overflow: hidden;
            max-width: 320px;
            margin: 0 auto 10px;
        }
        .cs-progress span {
            display: block;
            height: 100%;
            width: 62%;
            border-radius: 9999px;
            background: linear-gradient(90deg, #7c3aed, #a855f7);
            animation: cs-load 2.4s ease-in-out infinite alternate;
        }
        @keyframes cs-load { from { width: 42%; } to { width: 78%; } }
        .cs-progress-label { font-size: 12px; color: #94a3b8; margin-bottom: 30px; }
        .cs-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #fff;
            border: none;
            padding: 12px 26px;
            border-radius: 9999px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            transition: filter 0.15s ease;
        }
        .cs-btn:hover { filter: brightness(1.08); color: #fff; }

        [data-theme='dark'] body.dashboard, [data-theme='dark'].dashboard { background-color: #0f172a; }
        [data-theme='dark'] .cs-card { background: #1e293b; border-color: #334155; }
        [data-theme='dark'] .cs-card h1 { color: #f1f5f9; }
        [data-theme='dark'] .cs-card p { color: #94a3b8; }
        [data-theme='dark'] .cs-progress { background: #334155; }
    </style>
</head>
<body class="dashboard">
    <?php
    $hideHeaderCompanyBranding = true;
    if ($is_admin) {
        require_once '../includes/header_admin.php';
    } else {
        require_once '../includes/header_employee.php';
    }
    ?>

    <main class="main-content">
        <div class="cs-wrap">
            <div class="cs-card">
                <span class="cs-badge">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    Coming Soon
                </span>

                <div class="cs-icon">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                </div>

                <h1>Bulk Voucher Upload</h1>
                <p>
                    We're putting the finishing touches on bulk importing payment vouchers from a
                    CSV file. This feature will let you create many vouchers at once. It'll be
                    available very soon.
                </p>

                <div class="cs-progress"><span></span></div>
                <div class="cs-progress-label">Currently in development</div>

                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="cs-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Back to My Vouchers
                </a>
            </div>
        </div>
    </main>
</body>
</html>
