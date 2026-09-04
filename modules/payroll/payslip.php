<?php
// modules/payroll/payslip.php
require_once __DIR__ . '/config/database.php';

// Strict Access Control
if (!isset($_GET['id'])) die("Invalid ID");
$id = intval($_GET['id']);

// Fetch Payslip Data
$stmt = $pdo->prepare("
    SELECT p.*, pr.month, pr.year, pr.run_date, pr.run_by, 
           u.full_name, u.email, u.role, u.department,
           es.bank_name, es.account_number, es.nssf_number, es.tin_number,
           runner.full_name as runner_name, runner.role as runner_role
    FROM " . payroll_table('payslips') . " p
    JOIN " . payroll_table('payroll_runs') . " pr ON p.payroll_run_id = pr.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users runner ON pr.run_by = runner.id
    LEFT JOIN " . payroll_table('employee_salary') . " es ON u.id = es.user_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$slip = $stmt->fetch();

if (!$slip) die("Payslip not found.");

// Base URL for absolute paths
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$projectRoot = rtrim(dirname(dirname($scriptDir)), '/'); 
$baseUrl = $scheme . '://' . $host . $projectRoot;

// Access Control: Must be logged in
requireLogin();

// Access Control: Admin/Finance OR the specific employee
$current_user_id = $_SESSION['user_id'] ?? 0;
$is_owner = ($current_user_id > 0 && $current_user_id == $slip['user_id']);

if (!isFinanceOrAdmin() && !$is_owner) {
    die("Access denied. You can only view your own payslips.");
}

$isPrintMode = isset($_GET['print_mode']);
$isEmbed = isset($_GET['embed']) && !$isPrintMode;

$companyInfo = function_exists('getCompanyInfo') ? getCompanyInfo() : [];
$companyName = trim((string) ($companyInfo['company_name'] ?? ''));
if ($companyName === '' && function_exists('getCompanySetting')) {
    $companyName = trim((string) getCompanySetting('company_name', ''));
}
if ($companyName === '') {
    $companyName = defined('COMPANY_NAME') ? (string) COMPANY_NAME : 'ERP System';
}

$companyLogoUrl = function_exists('getCompanyLogoUrl') ? trim((string) getCompanyLogoUrl()) : '';
if ($companyLogoUrl === '') {
    $fallbackLogo = 'assets/images/Untitled.jpg';
    $fallbackDisk = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fallbackLogo);
    if (is_file($fallbackDisk)) {
        $companyLogoUrl = function_exists('app_url') ? app_url('/' . $fallbackLogo) : ($baseUrl . '/' . $fallbackLogo);
    }
}

$companyAddress = '';
$companyPhone = '';
$companyEmail = '';
if (function_exists('getCompanySetting')) {
    $companyAddress = trim((string) getCompanySetting('company_address', ''));
    $companyPhone = trim((string) getCompanySetting('company_phone', ''));
    $companyEmail = trim((string) getCompanySetting('company_email', ''));
}
if ($companyAddress === '') {
    $companyAddress = trim((string) ($companyInfo['company_address'] ?? ''));
}
if ($companyPhone === '') {
    $companyPhone = trim((string) ($companyInfo['company_phone'] ?? ''));
}
if ($companyEmail === '') {
    $companyEmail = trim((string) ($companyInfo['company_email'] ?? ''));
}
if ($companyAddress === '') {
    $companyAddress = defined('COMPANY_ADDRESS') ? (string) COMPANY_ADDRESS : '';
}
if ($companyPhone === '') {
    $companyPhone = defined('COMPANY_PHONE') ? (string) COMPANY_PHONE : '';
}
if ($companyEmail === '') {
    $companyEmail = defined('COMPANY_EMAIL') ? (string) COMPANY_EMAIL : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?= htmlspecialchars($slip['full_name']) ?></title>
    <!-- Fonts: Playfair Display (Serif) & Inter (Sans) -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        /* Base Reset & Colors */
        :root {
            --bg-color: #ffffff;
            --text-color: #1a1a1a;
            --accent-color: #dcbfa4; /* Thin gold lines */
            --header-font: 'Playfair Display', serif;
            --body-font: 'Inter', sans-serif;
        }
        
        body { 
            background: <?= $isPrintMode ? 'white' : ($isEmbed ? '#e2e8f0' : '#525659') ?>;
            font-family: var(--body-font); 
            color: var(--text-color);
            margin: 0;
            padding: <?= $isPrintMode ? '0' : ($isEmbed ? '12px' : '40px 0') ?>;
            display: flex;
            justify-content: center;
            <?= $isEmbed ? 'align-items: flex-start; min-height: 100%; box-sizing: border-box; overflow-x: hidden;' : '' ?>
        }

        /* The "Paper" Sheet */
        .payslip-sheet {
            background: var(--bg-color);
            width: 100%;
            max-width: 210mm; /* A4 Width Limit */
            <?= $isPrintMode ? 'height: 297mm; overflow: hidden;' : ($isEmbed ? 'min-height: auto;' : 'min-height: 297mm;') ?>
            padding: <?= $isEmbed ? '28px 32px' : '50px' ?>;
            box-sizing: border-box;
            box-shadow: <?= ($isPrintMode || $isEmbed) ? 'none' : '0 0 25px rgba(0,0,0,0.2)' ?>;
            position: relative;
            <?= $isEmbed ? 'transform-origin: top center;' : '' ?>
        }

<?php if ($isEmbed): ?>
        html, body { height: auto; }
        .signature-section { margin-top: 36px; }
        .page-bottom { margin-top: 36px; }
<?php endif; ?>

        /* Responsive Mobile Adjustments */
        @media (max-width: 768px) {
            body {
                padding: 10px 0;
            }
            .payslip-sheet {
                padding: 30px 20px;
                border-radius: 0;
            }
            .main-title {
                font-size: 28px;
            }
            .info-section {
                flex-direction: column;
                gap: 25px;
                margin-bottom: 30px;
            }
            .meta-block {
                text-align: left;
            }
            .meta-row {
                justify-content: flex-start;
                gap: 20px;
            }
            .pay-table th, .pay-table td {
                padding: 12px 10px;
                font-size: 13px;
            }
        }

<?php if ($isPrintMode): ?>
        @page { size: A4; margin: 0; }
        .payslip-sheet { width: 210mm; height: 297mm; padding: 50px; }
<?php endif; ?>

        /* --- Header Section --- */
        .header-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
        
        .main-title {
            font-family: var(--header-font);
            font-size: 42px;
            font-weight: 700;
            margin: 0;
            line-height: 1.1;
            color: var(--text-color);
        }
        
        .ref-no {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
            letter-spacing: 0.5px;
        }

        .company-block { text-align: right; }
        .company-logo-img { height: 50px; width: auto; object-fit: contain; margin-bottom: 5px; }
        .company-name { font-family: var(--header-font); font-size: 18px; font-weight: 700; }
        .company-slogan { font-size: 12px; color: #666; font-style: italic; }

        /* --- Info Section --- */
        .info-section { display: flex; justify-content: space-between; margin-bottom: 50px; }
        
        .to-block h3 { margin: 0 0 8px 0; font-size: 14px; color: #555; font-weight: 500; }
        .recipient-name { font-family: var(--body-font); font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .recipient-details { font-size: 13px; line-height: 1.6; color: #444; }

        .meta-block { text-align: right; font-size: 13px; line-height: 1.8; }
        .meta-row { display: flex; justify-content: flex-end; gap: 30px; }
        .meta-label { color: #666; }
        .meta-val { font-weight: 600; }

        /* --- Table --- */
        .pay-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        
        .pay-table th {
            background: #1a1a1a;
            color: white;
            padding: 12px 15px;
            font-family: var(--body-font);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
            font-weight: 500;
        }
        .pay-table th.text-end { text-align: right; }
        
        .pay-table td {
            padding: 16px 15px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 14px;
            color: #333;
            vertical-align: top;
        }
        
        /* Grid Lines Style */
        .pay-table td:not(:last-child), .pay-table th:not(:last-child) {
            border-right: 1px solid rgba(255,255,255,0.1);
        }
        .pay-table td:not(:last-child) {
            border-right: 1px solid #e0e0e0;
        }
        
        .pay-table .text-end { text-align: right; }

        /* --- Footer Section --- */
        .footer-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 40px; margin-top: 20px; }
        
        /* Bank Info */
        .payment-info h4 { margin: 0 0 15px 0; font-size: 14px; color: #555; }
        .bank-row { display: grid; grid-template-columns: 120px 1fr; font-size: 13px; margin-bottom: 8px; }
        .bank-label { color: #666; }
        .bank-val { font-weight: 600; color: #000; }

        /* Totals Box */
        .totals-box { 
            border: 1px solid #dcdcdc; 
            padding: 20px; 
            background: rgba(255,255,255,0.3); /* Subtle highlight */
        }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; }
        .total-row.final { 
            margin-top: 15px; 
            padding-top: 15px; 
            border-top: 1px solid #ccc; 
            font-weight: 700; 
            font-size: 18px; 
            align-items: center;
        }
        
        /* Signature */
        .signature-section { margin-top: 60px; display: flex; justify-content: flex-end; text-align: center; }
        .signature-block { width: 220px; }
        .signer-name { font-family: var(--header-font); font-size: 20px; font-weight: 700; margin-bottom: 5px; }
        .signer-title { font-size: 12px; color: #666; margin-bottom: 40px; }
        .signature-line { border-top: 1px solid #000; padding-top: 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }

        /* Page Bottom Info */
        .page-bottom { 
            margin-top: 80px; 
            font-size: 12px; 
            color: #777; 
            display: flex; 
            flex-direction: column; 
            gap: 5px; 
        }



        /* Print Controls */
        .controls { position: fixed; top: 20px; right: 20px; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); z-index: 1000; display: flex; gap: 10px; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; font-family: var(--body-font); text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: white; }
        .btn-success { background: #198754; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }

        @media print {
            body { background: none; padding: 0; }
            .controls { display: none; }
            .payslip-sheet { box-shadow: none; margin: 0; width: 100%; height: 100%; padding: 30px; }
        }
    </style>
    <!-- html2pdf.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>
    
    <?php if (!$isPrintMode && !$isEmbed): ?>
    <div class="controls no-print">
        <button class="btn btn-success" id="downloadBtn" onclick="downloadPDF()">Download PDF</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>
    </div>
    <?php endif; ?>

    <div class="payslip-sheet" id="payslipContent">
        <!-- Header -->
        <div class="header-top">
            <div>
                <h1 class="main-title">Payslip</h1>
                <div class="ref-no">No. <?= str_pad($slip['payroll_run_id'], 3, '0', STR_PAD_LEFT) ?>-<?= str_pad($slip['id'], 5, '0', STR_PAD_LEFT) ?></div>
            </div>
            <div class="company-block">
                <?php if ($companyLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>" class="company-logo-img">
                <?php endif; ?>
                <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
                <div class="company-slogan">Excellence in Service</div>
            </div>
        </div>

        <!-- Info -->
        <div class="info-section">
            <div class="to-block">
                <h3>Payslip For:</h3>
                <div class="recipient-name"><?= htmlspecialchars($slip['full_name']) ?></div>
                <div class="recipient-details">
                    <?= htmlspecialchars($slip['department']) ?><br>
                    <?= ucfirst($slip['role']) ?><br>
                    TIN: <?= htmlspecialchars($slip['tin_number'] ?? 'N/A') ?>
                </div>
            </div>
            <div class="meta-block">
                <div class="meta-row">
                    <span class="meta-label">Email</span>
                    <span class="meta-val"><?= $slip['email'] ?? 'Not set' ?></span>
                </div>
                <div class="meta-row" style="margin-top: 5px;">
                    <span class="meta-label">Period</span>
                    <span class="meta-val"><?= date('F Y', mktime(0,0,0,$slip['month'], 1, $slip['year'])) ?></span>
                </div>
                <div class="meta-row" style="margin-top: 5px;">
                    <span class="meta-label">Run Date</span>
                    <span class="meta-val"><?= date('M d, Y', strtotime($slip['run_date'])) ?></span>
                </div>
            </div>
        </div>

        <!-- Table -->
        <table class="pay-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 45%;">Item Description</th>
                    <th style="width: 25%;" class="text-end">Earnings</th>
                    <th style="width: 25%;" class="text-end">Deductions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Basic -->
                <tr>
                    <td>1.</td>
                    <td>Basic Salary</td>
                    <td class="text-end"><?= number_format($slip['basic_salary'], 2) ?></td>
                    <td class="text-end">-</td>
                </tr>
                <!-- Allowances -->
                <?php if($slip['total_allowances'] > 0): ?>
                <tr>
                    <td>2.</td>
                    <td>Total Allowances</td>
                    <td class="text-end"><?= number_format($slip['total_allowances'], 2) ?></td>
                    <td class="text-end">-</td>
                </tr>
                <?php endif; ?>
                <!-- Adjustments -->
                <?php if($slip['monthly_adjustment'] != 0): ?>
                <tr>
                    <td>3.</td>
                    <td>Monthly Adjustment</td>
                    <td class="text-end"><?= $slip['monthly_adjustment'] > 0 ? number_format($slip['monthly_adjustment'], 2) : '-' ?></td>
                    <td class="text-end"><?= $slip['monthly_adjustment'] < 0 ? number_format(abs($slip['monthly_adjustment']), 2) : '-' ?></td>
                </tr>
                <?php endif; ?>
                
                <!-- Spacer Lines to fill visual space if needed, or just specific items -->
                
                <!-- Deductions -->
                <tr>
                    <td>4.</td>
                    <td>NSSF Contribution (10%)</td>
                    <td class="text-end">-</td>
                    <td class="text-end"><?= number_format($slip['nssf_deduction'], 2) ?></td>
                </tr>
                <tr>
                    <td>5.</td>
                    <td>P.A.Y.E (Tax)</td>
                    <td class="text-end">-</td>
                    <td class="text-end"><?= number_format($slip['tax_deduction'], 2) ?></td>
                </tr>
                <?php if($slip['other_deductions'] > 0): ?>
                <tr>
                    <td>6.</td>
                    <td>Other Deductions</td>
                    <td class="text-end">-</td>
                    <td class="text-end"><?= number_format($slip['other_deductions'], 2) ?></td>
                </tr>
                <?php endif; ?>
                
                <!-- Empty Rows for visuals (Optional, to match height) -->
                <tr>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <!-- Footer Grid -->
        <div class="footer-grid">
            
            <!-- Left: Bank & Method -->
            <div class="payment-info">
                <h4>Payment Method :</h4>
                <div style="font-weight: 700; margin-bottom: 20px; font-size: 15px;">Bank Transfer</div>

                <div class="bank-row">
                    <span class="bank-label">Bank Name :</span>
                    <span class="bank-val"><?= htmlspecialchars($slip['bank_name'] ?? 'N/A') ?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">Account Name :</span>
                    <span class="bank-val"><?= htmlspecialchars($slip['full_name']) ?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">Account Number :</span>
                    <span class="bank-val"><?= htmlspecialchars($slip['account_number'] ?? 'N/A') ?></span>
                </div>

                <div class="page-bottom">
                    <?php if ($companyAddress !== ''): ?><div><?= htmlspecialchars($companyAddress) ?></div><?php endif; ?>
                    <?php if ($companyPhone !== ''): ?><div><?= htmlspecialchars($companyPhone) ?></div><?php endif; ?>
                    <?php if ($companyEmail !== ''): ?><div><?= htmlspecialchars($companyEmail) ?></div><?php endif; ?>
                </div>
            </div>

            <!-- Right: Totals -->
            <div>
                <div class="totals-box">
                    <div class="total-row">
                        <span class="bank-label">Gross Salary</span>
                        <span class="bank-val"><?= number_format($slip['gross_salary'], 2) ?></span>
                    </div>
                    <div class="total-row">
                        <span class="bank-label">Total Deductions</span>
                        <span class="bank-val text-danger">-<?= number_format($slip['nssf_deduction'] + $slip['tax_deduction'] + $slip['other_deductions'], 2) ?></span>
                    </div>
                    <div class="total-row final">
                        <span>Total Net Pay</span>
                        <span style="font-size: 20px;"><?= number_format($slip['net_salary'], 2) ?></span>
                    </div>
                </div>

                <!-- Signature -->
                <div class="signature-section">
                    <div class="signature-block">
                        <div class="signer-name"><?= htmlspecialchars($slip['runner_name'] ?? 'Authorized Signatory') ?></div>
                        <div class="signer-title"><?= htmlspecialchars($slip['runner_role'] ?? 'Finance Director') ?></div>
                        
                        <!-- Dynamic Signature Image -->
                        <div style="height: 60px; display: flex; justify-content: center; align-items: flex-end; margin-bottom: 5px;">
                            <?php 
                                $sigPath = getUserSignaturePathById($slip['run_by']);
                                if ($sigPath && file_exists('../../' . $sigPath)): 
                            ?>
                                <img src="<?= $baseUrl ?>/<?= htmlspecialchars($sigPath) ?>" alt="Signature" style="max-height: 80px; max-width: 100%; object-fit: contain;">
                            <?php endif; ?>
                        </div>

                        <!-- Signature Line & Label -->
                        <div class="signature-line">
                            Authorized Signature
                        </div>
                    </div>
                </div>
            </div>

        </div>

    <script>
        function downloadPDF() {
            const element = document.getElementById('payslipContent');
            const btn = document.getElementById('downloadBtn');
            const originalText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = 'Generating...';
                btn.disabled = true;
            }

            const opt = {
                margin: 0,
                filename: 'Payslip_<?= str_replace("'", "\\'", $slip['full_name']) ?>_<?= date('M_Y', mktime(0,0,0,$slip['month'], 1, $slip['year'])) ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, letterRendering: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().from(element).set(opt).save().then(() => {
                if (btn) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }).catch(() => {
                if (btn) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

<?php if ($isEmbed): ?>
        function fitPayslipToViewport() {
            const sheet = document.getElementById('payslipContent');
            if (!sheet) return;
            sheet.style.transform = 'none';
            sheet.style.width = '';
            sheet.style.marginBottom = '';
            const pad = 24;
            const availW = Math.max(280, window.innerWidth - pad);
            const availH = Math.max(320, window.innerHeight - pad);
            const naturalW = sheet.offsetWidth;
            const naturalH = sheet.scrollHeight;
            if (naturalW <= 0 || naturalH <= 0) return;
            const scale = Math.min(availW / naturalW, availH / naturalH, 1);
            sheet.style.transform = 'scale(' + scale + ')';
            sheet.style.transformOrigin = 'top center';
            sheet.style.marginBottom = Math.max(0, (naturalH * scale) - naturalH) + 'px';
            document.body.style.minHeight = Math.ceil(naturalH * scale + pad) + 'px';
        }

        window.addEventListener('load', () => {
            fitPayslipToViewport();
            setTimeout(fitPayslipToViewport, 150);
            setTimeout(fitPayslipToViewport, 500);
        });
        window.addEventListener('resize', fitPayslipToViewport);
<?php endif; ?>

        // Auto-download if ?download=1 is present
        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('download')) {
                // Small delay to ensure styles are fully ready
                setTimeout(downloadPDF, 1000);
            }
        });
    </script>
</body>
</html>
