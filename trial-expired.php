<?php
declare(strict_types=1);

/**
 * Shown when a free-trial company has passed trial_ends_at.
 * Data stays on the shared database; access unlocks when marked paid.
 */
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$plan = function_exists('getCompanyPlanInfo') ? getCompanyPlanInfo() : [];
$planStatus = (string) ($plan['plan_status'] ?? 'expired');
$endsLabel = !empty($plan['trial_ends_at'])
    ? date('d M Y', strtotime((string) $plan['trial_ends_at']))
    : '';

// Still on an active trial — send them back to work.
if (!empty($plan['access_allowed'])) {
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $dest = $slug !== '' ? company_url('select-module', $slug) : app_url('/select-module.php');
    header('Location: ' . $dest);
    exit;
}

$logoutUrl = app_url('/logout.php');
$homeUrl = app_url('/');
$year = (int) date('Y');
$companyName = (string) ($_SESSION['company_name'] ?? 'Your company');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Trial ended | UltiTech ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --font: 'DM Sans', system-ui, sans-serif;
            --ink: #1e2a44;
            --muted: #6b7a90;
            --line: #d9e0ec;
            --green: #2ecc71;
            --green-dark: #27ae60;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background:
                radial-gradient(ellipse 70% 50% at 50% 0%, #f7fbff 0%, transparent 55%),
                linear-gradient(165deg, #f8fafc 0%, #eef3f9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 28px 28px 30px;
            box-shadow: 0 18px 50px rgba(30, 42, 68, 0.08);
            text-align: center;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 26px;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        p {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.55;
        }
        .meta {
            margin: 0 0 22px;
            font-size: 13px;
            color: var(--ink);
            background: #f4f7fb;
            border-radius: 10px;
            padding: 12px 14px;
            text-align: left;
        }
        .meta strong { font-weight: 700; }
        .actions {
            display: grid;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            border: 0;
        }
        .btn-primary {
            background: var(--green);
            color: #fff;
            box-shadow: 0 10px 24px rgba(46, 204, 113, 0.28);
        }
        .btn-primary:hover { background: var(--green-dark); }
        .btn-ghost {
            background: #fff;
            color: var(--ink);
            border: 1px solid var(--line);
        }
        .foot {
            margin-top: 18px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Your free trial has ended</h1>
        <p>
            <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?> can no longer open modules until the plan is activated.
            Your data stays safe on the shared workspace database — nothing is deleted.
        </p>
        <div class="meta">
            <div><strong>Plan:</strong> <?= htmlspecialchars($planStatus, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($endsLabel !== ''): ?>
                <div><strong>Trial ended:</strong> <?= htmlspecialchars($endsLabel, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div><strong>Next step:</strong> Contact UltiTech to activate (same database — no migration).</div>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Back to home</a>
            <a class="btn btn-ghost" href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>">Log out</a>
        </div>
        <p class="foot">&copy; <?= $year ?> UltiTech ERP</p>
    </div>
</body>
</html>
