<?php
declare(strict_types=1);

define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

$homeUrl = app_url('/');
$trialUrl = app_url('/free-trial.php');
$lottieUrl = app_url('/assets/animations/page-not-found.lottie');
$year = (int) date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Page expired | UltiTech ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js" type="module"></script>
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
        .anim {
            width: min(100%, 280px);
            height: 220px;
            margin: 0 auto 8px;
            display: grid;
            place-items: center;
        }
        dotlottie-wc {
            width: 280px;
            height: 220px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 26px;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        p {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.55;
        }
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
            margin: 20px 0 0;
            font-size: 12px;
            color: #a8b2c3;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="anim" aria-hidden="true">
            <dotlottie-wc src="<?= htmlspecialchars($lottieUrl, ENT_QUOTES, 'UTF-8') ?>" autoplay loop speed="1"></dotlottie-wc>
        </div>
        <h1>This page expired</h1>
        <p>
            That form was already submitted, so refreshing it can&apos;t load safely.
            Go back home or start a new free trial.
        </p>
        <div class="actions">
            <a class="btn btn-primary" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Back to home</a>
            <a class="btn btn-ghost" href="<?= htmlspecialchars($trialUrl, ENT_QUOTES, 'UTF-8') ?>">Start free trial</a>
        </div>
        <p class="foot">&copy; <?= $year ?> UltiTech ERP</p>
    </main>
</body>
</html>
