<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/modules/email/includes/email_bootstrap.php';

if (isLoggedIn()) {
    $profileUrl = function_exists('user_profile_settings_url')
        ? user_profile_settings_url()
        : app_url('/employee/account.php');
    if (!headers_sent()) {
        header('Location: ' . $profileUrl, true, 302);
        exit;
    }
}

$controlDb = $GLOBALS['control_pdo'] ?? $GLOBALS['pdo'] ?? null;
$isAuthed = isLoggedIn();
$displayName = $isAuthed ? (string) ($_SESSION['full_name'] ?? 'My Account') : 'Guest Account';
$sessionCompanyId = $isAuthed ? (int) (currentCompanyId() ?: 0) : 0;

$selectedCompany = null;
$payments = [];
$companiesReady = false;

if ($isAuthed && $controlDb instanceof PDO && $sessionCompanyId > 0 && function_exists('tableExists') && tableExists('companies', $controlDb)) {
    try {
        $sql = "SELECT id, company_name, company_slug, db_name, status, updated_at FROM companies WHERE id = ? LIMIT 1";
        $stmtCompany = $controlDb->prepare($sql);
        $stmtCompany->execute([$sessionCompanyId]);
        $selectedCompany = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: null;
        $companiesReady = !empty($selectedCompany);
    } catch (Throwable $e) {
        $selectedCompany = null;
        $companiesReady = false;
    }
}

$accountRowName = (string) ($selectedCompany['company_name'] ?? $displayName);
$accountTitle = strtoupper($accountRowName);
$accountSlug = strtolower(trim((string) ($selectedCompany['company_slug'] ?? '')));
$accountUrl = $accountSlug !== '' ? app_url('/' . $accountSlug . '/login') : app_url('/login.php');
$enterWorkspaceUrl = $isAuthed
    ? ($accountSlug !== '' ? company_url('select-module', $accountSlug) : app_url('/select-module.php'))
    : app_url('/login.php?next=my-account.php');
$accountStatus = strtolower(trim((string) ($selectedCompany['status'] ?? 'active'))) ?: 'active';
$accountUpdated = !empty($selectedCompany['updated_at']) ? date('d F Y', strtotime((string) $selectedCompany['updated_at'])) : date('d F Y');

if ($isAuthed && $controlDb instanceof PDO && function_exists('tableExists') && tableExists('payment_vouchers', $controlDb)) {
    try {
        $hasDateCreated = columnExists('payment_vouchers', 'date_created', $controlDb);
        $hasCreatedAt = columnExists('payment_vouchers', 'created_at', $controlDb);
        $hasVoucherNo = columnExists('payment_vouchers', 'voucher_no', $controlDb);
        $hasAmount = columnExists('payment_vouchers', 'total_amount', $controlDb);
        $hasStatus = columnExists('payment_vouchers', 'status', $controlDb);
        $hasCompanyId = columnExists('payment_vouchers', 'company_id', $controlDb);

        $selectDate = $hasDateCreated ? 'date_created' : ($hasCreatedAt ? 'created_at' : 'NULL');
        $selectVoucher = $hasVoucherNo ? 'voucher_no' : 'CONCAT("PV-", id)';
        $selectAmount = $hasAmount ? 'total_amount' : '0';
        $selectStatus = $hasStatus ? 'status' : "'posted'";

        $sql = "SELECT id, {$selectVoucher} AS voucher_no, {$selectDate} AS payment_date, {$selectAmount} AS amount, {$selectStatus} AS payment_status FROM payment_vouchers";
        $params = array();
        if ($hasCompanyId && $sessionCompanyId > 0) {
            if (function_exists('companyScopeSql')) {
                list($scopeFrag, $scopeParams) = companyScopeSql('payment_vouchers', '', $controlDb);
                if ($scopeFrag !== '') {
                    $sql .= ' WHERE 1=1' . $scopeFrag;
                    $params = $scopeParams;
                }
            } else {
                $sql .= ' WHERE company_id = ?';
                $params[] = $sessionCompanyId;
            }
        } elseif (!(defined('IS_TENANT_DB') && IS_TENANT_DB)) {
            $payments = array();
            $sql = '';
        }
        if ($sql !== '') {
            $sql .= " ORDER BY id DESC LIMIT 12";

            $stmt = $controlDb->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $payments = [];
    }
}

// Fetch Personal Email Settings
$userEmailSettings = [
    'imap_host' => '', 'imap_port' => '993', 'imap_user' => '', 'imap_pass' => '', 'imap_ssl' => 'ssl',
    'smtp_host' => '', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_ssl' => 'ssl'
];
if ($isAuthed) {
    try {
        $emailPdo = email_module_pdo();
        if ($emailPdo) {
            $stmt = $emailPdo->prepare("SELECT * FROM module_email_user_settings WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $userEmailSettings = array_merge($userEmailSettings, $row);
            }
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account | UltiTech ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Inter', Arial, sans-serif;
            background: #f6f7fb;
            color: #111827;
        }
        .page {
            max-width: 1220px;
            margin: 12px auto 0;
            padding: 0 16px 0;
        }
        .topbar {
            height: 74px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e6e8f0;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #111827;
            font-size: 34px;
            font-weight: 800;
        }
        .logo i {
            font-size: 23px;
            color: #6a4bff;
        }
        .logo span {
            font-size: 35px;
            letter-spacing: .2px;
        }
        .nav {
            display: flex;
            align-items: center;
            gap: 28px;
            font-size: 14px;
            font-weight: 500;
        }
        .nav a {
            text-decoration: none;
            color: #3f4453;
        }
        .btn-account {
            background: linear-gradient(135deg, #6f45ff, #5e38e8);
            color: #fff !important;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600 !important;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 16px rgba(111, 69, 255, 0.28);
        }
        .layout {
            margin: 18px 0 12px;
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 14px;
        }
        .sidebar {
            background: #fff;
            border: 1px solid #e6e8f0;
            border-radius: 10px;
            padding: 12px;
            height: fit-content;
        }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #30384c;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 10px;
            border-radius: 8px;
            margin-bottom: 4px;
        }
        .menu-item i {
            width: 18px;
            text-align: center;
            color: #5d6478;
        }
        .menu-item.active {
            background: #f2efff;
            color: #5c36dd;
            font-weight: 600;
        }
        .menu-item.active i { color: #5c36dd; }
        .menu-spacer { height: 6px; border-top: 1px solid #eef0f6; margin: 8px 0 5px; }
        .main {
            display: grid;
            gap: 12px;
            align-content: start;
        }
        .account-card {
            background: #fff;
            border: 1px solid #e6e8f0;
            border-radius: 10px;
            padding: 18px 20px;
        }
        .account-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            padding-bottom: 14px;
            border-bottom: 1px solid #eceef5;
        }
        .account-left {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }
        .avatar {
            height: 62px;
            width: 62px;
            border-radius: 50%;
            background: #f4f0ff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5f3ae4;
            font-size: 27px;
        }
        .acc-title {
            margin: 0;
            font-size: 41px;
            font-weight: 800;
            letter-spacing: 0.4px;
            color: #161c2d;
        }
        .acc-url {
            color: #545b6f;
            font-size: 13px;
            margin-top: 5px;
            word-break: break-all;
        }
        .acc-meta {
            margin-top: 8px;
            color: #6a7287;
            font-size: 12px;
        }
        .badge {
            margin-left: 8px;
            background: #e6f7eb;
            color: #1f8f47;
            border: 1px solid #bae8c8;
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 600;
            text-transform: lowercase;
        }
        .acc-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            text-decoration: none;
            border-radius: 8px;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 11px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 164px;
            justify-content: center;
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.08);
        }
        .btn-upgrade { background: linear-gradient(135deg, #6f45ff, #5d35dd); }
        .btn-enter { background: linear-gradient(135deg, #18c2a3, #11b692); }
        .menu-dots {
            text-align: right;
            color: #7a8194;
            font-size: 16px;
            margin-top: 10px;
        }
        .notice {
            border: 1px solid #d5dff2;
            background: #f4f8ff;
            color: #445271;
            border-radius: 8px;
            font-size: 13px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notice i { color: #6782c4; }
        .payments {
            background: #fff;
            border: 1px solid #e6e8f0;
            border-radius: 10px;
        }
        .payments-head {
            padding: 14px 16px;
            border-bottom: 1px solid #eceef5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .payments-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #242b3e;
            font-weight: 700;
            font-size: 28px;
        }
        .payments-title i {
            color: #704bff;
            font-size: 16px;
            background: #f2eeff;
            border-radius: 6px;
            padding: 7px;
        }
        .view-all {
            text-decoration: none;
            color: #5c36dd;
            border: 1px solid #d9cef9;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        thead th {
            text-align: left;
            color: #79819a;
            font-weight: 600;
            font-size: 12px;
            padding: 12px 16px 11px;
            border-bottom: 2px solid #7251ff;
        }
        tbody td {
            padding: 12px 16px;
            color: #353f58;
            border-top: 1px solid #edf0f7;
        }
        .muted-action {
            color: #8891a8;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .receipt-link {
            color: #4f2fde;
            text-decoration: none;
            font-weight: 600;
        }
        .footer {
            margin-top: 14px;
            background: #fff;
            border-top: 1px solid #e6e8f0;
            border-bottom: 1px solid #e6e8f0;
            padding: 28px 24px;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1fr 1.4fr;
            gap: 28px;
        }
        .footer-brand {
            color: #111827;
            font-size: 40px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            text-decoration: none;
        }
        .footer-brand i { color: #6a4bff; }
        .footer-copy {
            color: #5f667a;
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 14px;
        }
        .social {
            display: flex;
            gap: 14px;
            color: #222b3d;
            font-size: 17px;
        }
        .footer h4 {
            margin: 0 0 12px;
            font-size: 16px;
            color: #1b2334;
        }
        .footer a {
            display: block;
            text-decoration: none;
            color: #5f667a;
            font-size: 14px;
            margin: 10px 0;
        }
        .legal {
            text-align: center;
            font-size: 13px;
            color: #6f778d;
            padding: 18px 0 20px;
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .legal a {
            color: #6f778d;
            text-decoration: none;
        }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .footer { grid-template-columns: 1fr 1fr; }
            .account-row { flex-direction: column; align-items: flex-start; }
            .acc-title { font-size: 29px; }
        }
        
        .email-settings-card {
            background: #fff;
            border: 1px solid #e6e8f0;
            border-radius: 10px;
            padding: 24px;
            margin-top: 16px;
        }
        .email-settings-card h3 {
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            color: #111827;
        }
        .email-settings-card h3 i { color: #6a4bff; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .form-group input, .form-group select {
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #6a4bff;
            box-shadow: 0 0 0 3px rgba(106, 75, 255, 0.1);
        }
        .btn-save-email {
            background: #111827;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-save-email:hover { background: #1f2937; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="page">
        <header class="topbar">
            <a href="index.php" class="logo"><i class="fa-solid fa-cube"></i> <span>UltiTech ERP</span></a>
            <nav class="nav">
                <a href="#">Modules</a>
                <a href="#">Industries</a>
                <a href="#">Pricing</a>
                <a href="my-account.php" class="btn-account"><i class="fa-regular fa-user"></i> My Account <i class="fa-solid fa-chevron-down"></i></a>
            </nav>
        </header>

        <section class="layout">
            <aside class="sidebar">
                <a class="menu-item active" href="#"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a class="menu-item" href="#"><i class="fa-regular fa-credit-card"></i> My Payments</a>
                <a class="menu-item" href="#"><i class="fa-solid fa-crown"></i> Subscription</a>
                <a class="menu-item" href="#"><i class="fa-regular fa-user"></i> Profile</a>
                <a class="menu-item" href="#"><i class="fa-solid fa-user-plus"></i> Create New Account</a>
                <div class="menu-spacer"></div>
                <a class="menu-item" href="<?= htmlspecialchars(app_url('/logout.php')) ?>"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </aside>

            <div class="main">
                <div class="account-card">
                    <div class="account-row">
                        <div class="account-left">
                            <div class="avatar"><i class="fa-regular fa-user"></i></div>
                            <div>
                            <h2 class="acc-title"><?= htmlspecialchars($accountTitle) ?></h2>
                            <div class="acc-url"><?= htmlspecialchars($accountUrl) ?></div>
                                <div class="acc-meta">
                                    Updated on: <?= htmlspecialchars($accountUpdated) ?>
                                    <span class="badge"><?= htmlspecialchars($accountStatus) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="acc-actions">
                            <a class="btn btn-upgrade" href="#"><i class="fa-regular fa-paper-plane"></i> Upgrade Plan</a>
                            <a class="btn btn-enter" href="<?= htmlspecialchars($enterWorkspaceUrl) ?>"><i class="fa-solid fa-arrow-right-to-bracket"></i> Enter Workspace</a>
                        </div>
                    </div>
                    <div class="menu-dots"><i class="fa-solid fa-ellipsis-vertical"></i></div>
                </div>

                <?php if (!$companiesReady): ?>
                    <div class="notice"><i class="fa-regular fa-circle-info"></i> System details are not available yet. Create or import your company to display live account data.</div>
                <?php endif; ?>

                <div class="payments">
                    <div class="payments-head">
                        <div class="payments-title"><i class="fa-solid fa-wallet"></i> Payments</div>
                        <a class="view-all" href="<?= htmlspecialchars(app_url('/all-vouchers.php')) ?>"><i class="fa-regular fa-list"></i> View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <?php
                                    $paymentDateRaw = (string) ($payment['payment_date'] ?? '');
                                    $paymentDate = $paymentDateRaw !== '' ? date('d F Y', strtotime($paymentDateRaw)) : '-';
                                    $paymentAmount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
                                    $paymentId = (int) ($payment['id'] ?? 0);
                                    $receiptLink = $paymentId > 0 ? app_url('/employee/view-voucher.php?id=' . $paymentId) : '#';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($selectedCompany['company_name'] ?? $accountRowName)) ?></td>
                                        <td><?= htmlspecialchars($paymentDate) ?></td>
                                        <td>$<?= number_format($paymentAmount, 2) ?></td>
                                        <td><a class="receipt-link" href="<?= htmlspecialchars($receiptLink) ?>">View Receipt</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($selectedCompany['company_name'] ?? $accountRowName)) ?></td>
                                    <td>-</td>
                                    <td>$0.00</td>
                                    <td><span class="muted-action"><i class="fa-regular fa-clock"></i> No payments yet</span></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Personal Email Settings Form -->
                <div class="email-settings-card">
                    <h3><i class="fa-regular fa-envelope"></i> Personal Email Settings</h3>
                    <p style="font-size: 14px; color: #4b5563; margin-top: -5px; margin-bottom: 20px;">Configure your personal IMAP/SMTP credentials so you can send and receive emails securely inside the ERP.</p>
                    
                    <form id="emailSettingsForm" onsubmit="saveEmailSettings(event)">
                        <h4 style="margin:0 0 10px; font-size: 16px; color: #374151;">Incoming Server (IMAP)</h4>
                        <div class="form-grid" style="margin-bottom: 24px;">
                            <div class="form-group">
                                <label>IMAP Host</label>
                                <input type="text" name="imap_host" placeholder="e.g. mail.domain.com" value="<?= htmlspecialchars($userEmailSettings['imap_host']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>IMAP Port & Encryption</label>
                                <div style="display:flex; gap:10px;">
                                    <input type="number" name="imap_port" placeholder="993" style="width:100px;" value="<?= htmlspecialchars($userEmailSettings['imap_port']) ?>" required>
                                    <select name="imap_ssl" style="flex:1;">
                                        <option value="ssl" <?= $userEmailSettings['imap_ssl'] === 'ssl' ? 'selected' : '' ?>>SSL (Recommended)</option>
                                        <option value="tls" <?= $userEmailSettings['imap_ssl'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="" <?= $userEmailSettings['imap_ssl'] === '' ? 'selected' : '' ?>>None</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Email Address / Username</label>
                                <input type="email" name="imap_user" placeholder="you@domain.com" value="<?= htmlspecialchars($userEmailSettings['imap_user']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Password</label>
                                <input type="password" name="imap_pass" placeholder="••••••••" value="<?= htmlspecialchars($userEmailSettings['imap_pass']) ?>" required>
                            </div>
                        </div>

                        <h4 style="margin:0 0 10px; font-size: 16px; color: #374151;">Outgoing Server (SMTP)</h4>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" placeholder="e.g. mail.domain.com" value="<?= htmlspecialchars($userEmailSettings['smtp_host']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>SMTP Port & Encryption</label>
                                <div style="display:flex; gap:10px;">
                                    <input type="number" name="smtp_port" placeholder="465" style="width:100px;" value="<?= htmlspecialchars($userEmailSettings['smtp_port']) ?>" required>
                                    <select name="smtp_ssl" style="flex:1;">
                                        <option value="ssl" <?= $userEmailSettings['smtp_ssl'] === 'ssl' ? 'selected' : '' ?>>SSL (Recommended)</option>
                                        <option value="tls" <?= $userEmailSettings['smtp_ssl'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="" <?= $userEmailSettings['smtp_ssl'] === '' ? 'selected' : '' ?>>None</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>SMTP Username (Usually same as IMAP)</label>
                                <input type="text" name="smtp_user" placeholder="you@domain.com" value="<?= htmlspecialchars($userEmailSettings['smtp_user']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>SMTP Password</label>
                                <input type="password" name="smtp_pass" placeholder="••••••••" value="<?= htmlspecialchars($userEmailSettings['smtp_pass']) ?>" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-save-email" id="saveEmailBtn">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <footer class="footer">
            <div>
                <a class="footer-brand" href="index.php"><i class="fa-solid fa-cube"></i> UltiTech ERP</a>
                <div class="footer-copy">Powerful ERP solutions to streamline your business and drive growth.</div>
                <div class="social">
                    <i class="fa-brands fa-linkedin-in"></i>
                    <i class="fa-brands fa-twitter"></i>
                    <i class="fa-brands fa-facebook-f"></i>
                    <i class="fa-brands fa-youtube"></i>
                </div>
            </div>
            <div>
                <h4>Product</h4>
                <a href="#">Overview</a>
                <a href="#">Pricing</a>
                <a href="#">Features</a>
                <a href="#">Updates</a>
            </div>
            <div>
                <h4>Modules</h4>
                <a href="#">Billing & Invoicing</a>
            </div>
            <div>
                <h4>Industries</h4>
                <a href="#">Law Firms</a>
            </div>
            <div>
                <h4>Support</h4>
                <a href="#">Help Center</a>
                <a href="#">Contact Us</a>
                <a href="#">System Status</a>
                <h4 style="margin-top:14px;">Contact Us</h4>
                <a href="#">support@ultitecherp.com</a>
                <a href="#">+1 (555) 123-4567</a>
                <a href="#">123 Business Ave, Suite 100, New York, NY 10001</a>
            </div>
        </footer>
        <div class="legal">
            <span>&copy; 2026 UltiTech ERP. All rights reserved.</span>
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Cookies</a>
        </div>
    </div>

    <script>
    function saveEmailSettings(e) {
        e.preventDefault();
        const btn = document.getElementById('saveEmailBtn');
        const icon = btn.querySelector('i');
        const form = document.getElementById('emailSettingsForm');
        
        btn.disabled = true;
        icon.className = 'fa-solid fa-circle-notch fa-spin';
        
        fetch('<?= app_url('api/save_user_email_settings.php') ?>', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            icon.className = 'fa-solid fa-cloud-arrow-up';
            if (data.status === 'success') {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Email settings saved successfully!', showConfirmButton: false, timer: 3000 });
            } else {
                Swal.fire('Error', data.message || 'Failed to save settings', 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            icon.className = 'fa-solid fa-cloud-arrow-up';
            Swal.fire('Error', 'Network connection failed', 'error');
        });
    }
    </script>
</body>
</html>
