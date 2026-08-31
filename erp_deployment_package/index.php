<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ultimate General Trading â€¢ Ultimate Systems</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>" />
    <style>
        :root {
            --brand-primary: #f39c12;
            --brand-secondary: #e67e22;
            --bg-light: #f8f9fa;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --border-color: #dee2e6;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            width: 100%;
            text-align: center;
            animation: fadeIn 1s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo {
            height: 120px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 800;
            margin: 0.5em 0;
            line-height: 1.2;
        }
        h1 span {
            background: linear-gradient(45deg, var(--brand-primary), var(--brand-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-fill-color: transparent;
            text-fill-color: transparent;
        }
        .subtitle {
            font-size: 1.15rem;
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto 2.5rem;
        }
        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .btn-landing {
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(45deg, var(--brand-primary), var(--brand-secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
        }
        .btn-secondary {
            background-color: #fff;
            color: var(--text-dark);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
        }
        .footer {
            margin-top: 4rem;
            font-size: 0.9rem;
            color: var(--text-light);
        }
        /* Christmas Features */
        .christmas-greeting { color: #d42426; font-weight: bold; font-size: 1.2rem; margin: 0.5rem 0 1.5rem; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .snow-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; overflow: hidden; }
        .snowflake { position: absolute; top: -20px; color: #cbd5e1; font-size: 1.5em; animation-name: fall, shake; animation-duration: 10s, 3s; animation-timing-function: linear, ease-in-out; animation-iteration-count: infinite, infinite; opacity: 0.8; }
        @keyframes fall { 0% { top: -10%; } 100% { top: 100%; } }
        @keyframes shake { 0% { transform: translateX(0px); } 50% { transform: translateX(40px); } 100% { transform: translateX(0px); } }
        .snowflake:nth-of-type(1) { left: 10%; animation-delay: 1s, 1s; }
        .snowflake:nth-of-type(2) { left: 20%; animation-delay: 6s, .5s; }
        .snowflake:nth-of-type(3) { left: 30%; animation-delay: 4s, 2s; }
        .snowflake:nth-of-type(4) { left: 40%; animation-delay: 2s, 2s; }
        .snowflake:nth-of-type(5) { left: 50%; animation-delay: 8s, 3s; }
        .snowflake:nth-of-type(6) { left: 60%; animation-delay: 6s, 2s; }
        .snowflake:nth-of-type(7) { left: 70%; animation-delay: 2.5s, 1s; }
        .snowflake:nth-of-type(8) { left: 80%; animation-delay: 1s, 0s; }
        .snowflake:nth-of-type(9) { left: 90%; animation-delay: 3s, 1.5s; }
    </style>
    <meta name="description" content="Create, review, approve, and print branded payment vouchers with ease." />
    <link rel="icon" href="employee/images/ULTIMATE%20GENERAL%20LOGO.white_page-0001-cropped.svg" />
</head>
<body>
    <div class="snow-container" aria-hidden="true">
        <div class="snowflake">â…</div><div class="snowflake">â†</div><div class="snowflake">â…</div>
        <div class="snowflake">â†</div><div class="snowflake">â…</div><div class="snowflake">â†</div>
        <div class="snowflake">â…</div><div class="snowflake">â†</div><div class="snowflake">â…</div>
    </div>
    <div class="container">
        <img src="employee/images/ULTIMATE GENERAL LOGO.white_page-0001-cropped.svg" alt="Ultimate General Trading Logo" class="logo" />
        <h1>
            <span>Ultimate Systems</span>
        </h1>
        <div class="christmas-greeting">ðŸŽ„ Merry Christmas! ðŸŽ„</div>
        <p class="subtitle">
            Streamline your workflow. Create, manage, and approve payment vouchers with professional branding and efficiency.
        </p>
        <div class="cta-buttons">
            <a href="login.php" class="btn-landing btn-primary">Login</a>
            <a href="register.php" class="btn-landing btn-secondary">Register</a>
        </div>
        <div class="footer">
            <p>&copy; <?= date('Y') ?> Ultimate General Trading. All rights reserved.</p>
        </div>
    </div>
</body>
</html>

