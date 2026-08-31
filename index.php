<?php
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    if (isSuperAdmin()) {
        header('Location: ' . app_url('/admin/companies.php'));
        exit;
    }

    $sessionSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
    header('Location: ' . company_dashboard_url($sessionSlug !== '' ? $sessionSlug : null));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltiTech ERP | Welcome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --purple: #7c3aed;
            --purple-soft: rgba(124, 58, 237, 0.16);
            --purple-glow: rgba(124, 58, 237, 0.35);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', Arial, sans-serif;
            background: #f8fbff;
            color: #10213f;
        }
        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 18px 20px 34px;
            position: relative;
            overflow: hidden;
        }
        .float-shape {
            position: absolute;
            border-radius: 18px;
            background: rgba(124, 58, 237, 0.08);
            animation: floatShape 6s ease-in-out infinite;
            pointer-events: none;
        }
        .float-shape.shape-1 { width: 46px; height: 46px; top: 22%; left: 20%; animation-delay: 0s; }
        .float-shape.shape-2 { width: 64px; height: 64px; top: 28%; right: 19%; animation-delay: 1.2s; }
        .float-shape.shape-3 { width: 28px; height: 28px; top: 13%; right: 34%; border-radius: 50%; animation-delay: 2s; }
        @keyframes floatShape {
            0%,100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-18px) rotate(8deg); }
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 4px 10px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            font-size: 30px;
            font-weight: 800;
            color: #0f1f40;
            text-decoration: none;
        }
        .logo i { color: #2563eb; font-size: 22px; }
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 26px;
            color: #4c5f80;
            font-size: 14px;
            font-weight: 500;
        }
        .nav-menu a {
            text-decoration: none;
            color: #4c5f80;
        }
        .btn-account {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 8px 18px rgba(109,40,217,.24);
        }

        .hero {
            text-align: center;
            padding: 52px 12px 24px;
        }
        .hero-title {
            margin: 0 auto;
            max-width: 780px;
            font-size: clamp(38px, 6vw, 66px);
            line-height: 1.12;
            letter-spacing: -1.4px;
            color: #0f1f40;
            animation: fadeUp 0.8s ease forwards;
        }
        .hero-subtitle {
            margin: 20px auto 0;
            max-width: 760px;
            color: #5a6c8d;
            line-height: 1.75;
            font-size: 20px;
            opacity: 0;
            animation: fadeUp 0.8s ease forwards;
            animation-delay: 0.18s;
        }
        .hero-actions {
            margin-top: 34px;
            display: flex;
            justify-content: center;
            opacity: 0;
            animation: fadeUp 0.8s ease forwards;
            animation-delay: 0.35s;
        }
        .start-trial-btn {
            min-width: 470px;
            max-width: 94%;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            color: #fff;
            font-size: 26px;
            font-weight: 700;
            padding: 18px 22px;
            box-shadow: 0 18px 45px rgba(124, 58, 237, 0.28);
            animation: softFloat 3.5s ease-in-out infinite;
            transition: all 0.25s ease;
        }
        .start-trial-btn i { margin-left: 10px; font-size: 18px; }
        .start-trial-btn:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 24px 60px rgba(124, 58, 237, 0.40);
        }

        .trust {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 24px;
            color: #4f6182;
            font-size: 13px;
            font-weight: 600;
        }
        .trust .item i {
            color: #2563eb;
            margin-right: 7px;
        }

        .platform {
            margin: 32px auto 0;
            background: #fff;
            border: 1px solid #e3ecfb;
            border-radius: 16px;
            box-shadow: 0 16px 34px rgba(15, 31, 64, 0.07);
            padding: 22px 18px;
        }
        .platform h2 {
            margin: 0;
            text-align: center;
            color: #12244a;
            font-size: 34px;
            font-weight: 800;
        }
        .platform .sub {
            margin: 10px 0 0;
            text-align: center;
            color: #607394;
            font-size: 17px;
        }
        .cards {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 14px;
        }
        .card.module-card {
            border: 1px solid #e6effe;
            border-radius: 12px;
            background: #fbfdff;
            padding: 20px 18px;
            min-height: 112px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transform: translateY(18px);
            opacity: 0;
            animation: cardReveal 0.6s ease forwards;
            transition: all 0.25s ease;
        }
        .module-card:nth-child(1) { animation-delay: 0.10s; }
        .module-card:nth-child(2) { animation-delay: 0.18s; }
        .module-card:nth-child(3) { animation-delay: 0.26s; }
        .module-card:nth-child(4) { animation-delay: 0.34s; }
        .module-card:nth-child(5) { animation-delay: 0.42s; }
        .module-card:nth-child(6) { animation-delay: 0.50s; }
        .module-card:nth-child(7) { animation-delay: 0.58s; }
        .module-card:nth-child(8) { animation-delay: 0.66s; }
        .module-card:hover {
            transform: translateY(-6px);
            border-color: rgba(124, 58, 237, 0.28);
            box-shadow: 0 20px 45px rgba(124, 58, 237, 0.14);
        }
        .module-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #2563eb;
            font-size: 18px;
            flex: 0 0 38px;
            animation: iconPulse 3s ease-in-out infinite;
        }
        .module-content h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #18315f;
            line-height: 1.3;
        }
        .module-content p {
            margin: 4px 0 0;
            color: #6f7f9d;
            font-size: 13px;
            line-height: 1.45;
        }
        .card.c1 .module-icon { background: #e6f8f8; color: #0f9ea8; }
        .card.c2 .module-icon { background: #fff3e4; color: #f08d1e; }
        .card.c3 .module-icon { background: #eaf1ff; color: #2f7df6; }
        .card.c4 .module-icon { background: #ffe8ef; color: #ef4b81; }
        .card.c5 .module-icon { background: #eefdf1; color: #3faa4a; }
        .card.c6 .module-icon { background: #f3eaff; color: #8a42e6; }
        .card.c7 .module-icon { background: #e8fbff; color: #19a6bf; }
        .card.c8 .module-icon { background: #edf9ea; color: #60a83d; }
        .foot {
            text-align: center;
            margin-top: 18px;
            color: #7d8fad;
            font-size: 12px;
        }

        @media (max-width: 1024px) {
            .cards { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .hero-title { font-size: clamp(32px, 6vw, 52px); }
            .hero-subtitle { font-size: 17px; }
            .platform h2 { font-size: 28px; }
        }
        @media (max-width: 760px) {
            .nav { flex-wrap: wrap; gap: 12px; }
            .nav-menu { width: 100%; justify-content: center; order: 3; }
            .start-trial-btn { min-width: 0; width: 100%; font-size: 20px; }
            .cards { grid-template-columns: 1fr; }
            .hero { padding-top: 24px; }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(22px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes softFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        @keyframes cardReveal {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes iconPulse {
            0%, 100% { box-shadow: 0 0 0 rgba(124, 58, 237, 0); }
            50% { box-shadow: 0 0 24px rgba(124, 58, 237, 0.22); }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-title,
            .hero-subtitle,
            .hero-actions,
            .start-trial-btn,
            .float-shape,
            .module-card,
            .module-icon {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <span class="float-shape shape-1"></span>
        <span class="float-shape shape-2"></span>
        <span class="float-shape shape-3"></span>

        <header class="nav">
            <a href="index.php" class="logo"><i class="fa-solid fa-circle-notch"></i> UltiTech ERP</a>
            <nav class="nav-menu">
                <a href="login.php">Modules</a>
                <a href="login.php">Industries</a>
                <a href="login.php">Pricing</a>
                <a href="login.php"><i class="fa-solid fa-bars"></i></a>
            </nav>
            <a href="my-account.php" class="btn-account"><i class="fa-solid fa-user"></i> My Account</a>
        </header>

        <section class="hero">
            <h1 class="hero-title">The Smarter Way to Run Your Business</h1>
            <p class="hero-subtitle">
                UltiTech ERP connects finance, sales, inventory, HR, logistics, and operations in one secure business platform.
            </p>
            <div class="hero-actions">
                <a href="my-account.php" class="start-trial-btn">Start Free Trial <i class="fa-solid fa-circle-arrow-right"></i></a>
            </div>
            <div class="trust">
                <div class="item"><i class="fa-regular fa-calendar-check"></i> Free 14-Day Trial</div>
                <div class="item"><i class="fa-solid fa-shield-heart"></i> No Credit Card Required</div>
                <div class="item"><i class="fa-solid fa-bolt"></i> Easy Setup</div>
                <div class="item"><i class="fa-solid fa-puzzle-piece"></i> All-in-One Modules</div>
            </div>
        </section>

        <section class="platform">
            <h2>All Your Business. One Smart Platform.</h2>
            <p class="sub">Streamline every department and gain real-time visibility across your organization.</p>
            <div class="cards">
                <div class="card module-card c1"><div class="module-icon"><i class="fa-solid fa-calculator"></i></div><div class="module-content"><h3>Finance & Accounting</h3><p>Manage budgets, cashflow, and reconciliations.</p></div></div>
                <div class="card module-card c2"><div class="module-icon"><i class="fa-solid fa-chart-column"></i></div><div class="module-content"><h3>Sales Management</h3><p>Control orders, quotations, and invoicing.</p></div></div>
                <div class="card module-card c3"><div class="module-icon"><i class="fa-solid fa-boxes-stacked"></i></div><div class="module-content"><h3>Inventory & Stock</h3><p>Track stock levels, movement, and audits.</p></div></div>
                <div class="card module-card c4"><div class="module-icon"><i class="fa-solid fa-user-group"></i></div><div class="module-content"><h3>HR & Payroll</h3><p>Handle attendance, payroll, and staff records.</p></div></div>
                <div class="card module-card c5"><div class="module-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div><div class="module-content"><h3>Voucher Workflow</h3><p>Create, review, and approve expense vouchers.</p></div></div>
                <div class="card module-card c6"><div class="module-icon"><i class="fa-solid fa-truck-fast"></i></div><div class="module-content"><h3>Logistics & Delivery</h3><p>Coordinate dispatch, delivery notes, and routes.</p></div></div>
                <div class="card module-card c7"><div class="module-icon"><i class="fa-solid fa-chart-line"></i></div><div class="module-content"><h3>Live Reports</h3><p>Monitor KPIs with real-time business visibility.</p></div></div>
                <div class="card module-card c8"><div class="module-icon"><i class="fa-solid fa-gears"></i></div><div class="module-content"><h3>Operations Control</h3><p>Automate workflows and cross-team processes.</p></div></div>
            </div>
        </section>

        <div class="foot">&copy; <?php echo date('Y'); ?> Ultimate General Trading</div>
    </div>
</body>
</html>
