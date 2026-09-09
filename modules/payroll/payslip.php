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

/**
 * Prefer Admin → Company Settings (companies row + company_settings KV).
 * Skip demo placeholders like "123 Freight Road…".
 */
$companyProfile = [];
if (function_exists('getCurrentCompany')) {
    $companyProfile = getCurrentCompany() ?: [];
}
if ((!is_array($companyProfile) || $companyProfile === []) && function_exists('getCompanyInfo')) {
    $companyProfile = getCompanyInfo() ?: [];
}
if (!is_array($companyProfile)) {
    $companyProfile = [];
}

$pickCompanyValue = static function (array $keys) use ($companyProfile): string {
    foreach ($keys as $key) {
        $fromProfile = trim((string) ($companyProfile[$key] ?? ''));
        if ($fromProfile !== '') {
            return $fromProfile;
        }
        if (function_exists('getCompanySetting')) {
            $fromSettings = trim((string) getCompanySetting($key, ''));
            if ($fromSettings !== '') {
                return $fromSettings;
            }
        }
    }
    return '';
};

$isPlaceholderDetail = static function (string $value): bool {
    return $value !== '' && (bool) preg_match(
        '/123\s+Freight\s+Road|Logistics\s+Park|procurement@shippex\.co\.tz|\+255\s*123\s*456\s*789/i',
        $value
    );
};

$companyName = $pickCompanyValue(['company_name', 'legal_name', 'name']);
if ($companyName === '' && defined('COMPANY_NAME')) {
    $companyName = trim((string) COMPANY_NAME);
}
if ($companyName === '') {
    $companyName = 'ERP System';
}

$companyAddress = $pickCompanyValue(['address', 'company_address']);
$companyPhone = $pickCompanyValue(['phone', 'company_phone']);
$companyEmail = $pickCompanyValue(['email', 'company_email']);

if ($isPlaceholderDetail($companyAddress)) {
    $companyAddress = '';
}
if ($isPlaceholderDetail($companyPhone)) {
    $companyPhone = '';
}
if ($isPlaceholderDetail($companyEmail)) {
    $companyEmail = '';
}

// Build address from parts when only city/country exist on the company row.
if ($companyAddress === '') {
    $addressParts = array_filter([
        trim((string) ($companyProfile['address'] ?? '')),
        trim((string) ($companyProfile['city'] ?? '')),
        trim((string) ($companyProfile['country'] ?? getCompanySetting('country', ''))),
    ], static fn ($part) => $part !== '' && !$isPlaceholderDetail($part));
    $companyAddress = implode(', ', $addressParts);
}

$companyLogoUrl = '';
if (function_exists('resolveCompanyBrandingLogoUrl')) {
    $companyLogoUrl = trim((string) resolveCompanyBrandingLogoUrl());
}
if ($companyLogoUrl === '' && function_exists('getCompanyLogoUrl')) {
    $companyLogoUrl = trim((string) getCompanyLogoUrl());
}

$isDownload = isset($_GET['download']);

$companyBox = $pickCompanyValue(['po_box', 'postal_box', 'p_o_box', 'box']);
$companyCity = $pickCompanyValue(['city', 'company_city']);
$companyCountry = $pickCompanyValue(['country', 'company_country']);
if ($companyCity !== '' && $companyCountry !== '') {
    $companyCityLine = strtoupper($companyCity . ', ' . $companyCountry . '.');
} elseif ($companyAddress !== '') {
    $companyCityLine = strtoupper($companyAddress);
} else {
    $companyCityLine = '';
}
$companyBoxLine = $companyBox !== ''
    ? strtoupper(preg_match('/^\s*p\.?\s*o\.?\s*box/i', $companyBox) ? $companyBox : ('P.O.BOX ' . $companyBox))
    : '';
if ($companyBoxLine !== '' && substr($companyBoxLine, -1) !== ',') {
    $companyBoxLine .= ',';
}
$companyNameLine = strtoupper($companyName);
if ($companyNameLine !== '' && substr($companyNameLine, -1) !== ',') {
    $companyNameLine .= ',';
}

$letterheadHeaderUrl = function_exists('app_url')
    ? rtrim((string) app_url('/letterhead/header.png'), '/')
    : ($baseUrl . '/letterhead/header.png');
$letterheadFooterUrl = function_exists('app_url')
    ? rtrim((string) app_url('/letterhead/footer.png'), '/')
    : ($baseUrl . '/letterhead/footer.png');
$headerFs = dirname(__DIR__, 2) . '/letterhead/header.png';
$footerFs = dirname(__DIR__, 2) . '/letterhead/footer.png';
if (is_file($headerFs)) {
    $letterheadHeaderUrl .= (strpos($letterheadHeaderUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($headerFs);
}
if (is_file($footerFs)) {
    $letterheadFooterUrl .= (strpos($letterheadFooterUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($footerFs);
}

$periodLabel = date('F Y', mktime(0, 0, 0, (int) $slip['month'], 1, (int) $slip['year']));
$payslipRef = 'REF: PAYSLIP NO. '
    . str_pad((string) $slip['payroll_run_id'], 3, '0', STR_PAD_LEFT)
    . '-'
    . str_pad((string) $slip['id'], 5, '0', STR_PAD_LEFT);
$letterDateLabel = date('d-m-Y', strtotime((string) $slip['run_date']));

$sigUrl = '';
$sigPath = function_exists('getUserSignaturePathById') ? getUserSignaturePathById($slip['run_by']) : null;
if (is_string($sigPath) && $sigPath !== '') {
    $sigFs = dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $sigPath), '/');
    if (is_file($sigFs)) {
        if (function_exists('mediaUrlFromPath')) {
            $sigUrl = (string) mediaUrlFromPath($sigPath);
        } else {
            $sigUrl = $baseUrl . '/' . ltrim(str_replace('\\', '/', $sigPath), '/');
        }
        $sigUrl .= (strpos($sigUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($sigFs);
    }
}

$rowNo = 0;

// Full-page Laravel + React document viewer (embed/download/print stay on legacy HTML).
if (
    !$isPrintMode
    && !$isEmbed
    && !$isDownload
    && !defined('PAYROLL_PAYSLIP_LEGACY_FALLBACK')
) {
    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'payroll';
    }
    $_GET['desk'] = 'payslip';
    $_GET['id'] = $id;
    require dirname(__DIR__, 2) . '/payroll.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?= htmlspecialchars($slip['full_name']) ?></title>
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #111111;
            --accent: #FBC51C;
            --muted: #555555;
            --line: #d4d4d4;
            --body-font: 'Times New Roman', Times, serif;
            --ui-font: Georgia, 'Times New Roman', Times, serif;
        }

        * { box-sizing: border-box; }

        body {
            background: <?= $isPrintMode ? 'white' : ($isEmbed ? '#ffffff' : '#525659') ?>;
            font-family: var(--body-font);
            color: var(--text-color);
            margin: 0;
            padding: <?= $isPrintMode ? '0' : ($isEmbed ? '0' : '40px 0') ?>;
            display: flex;
            justify-content: center;
            <?= $isEmbed ? 'align-items: stretch; min-height: 100%; overflow-x: hidden;' : '' ?>
        }

        .payslip-sheet {
            background: var(--bg-color);
            width: 100%;
            max-width: <?= $isEmbed ? 'none' : '210mm' ?>;
            <?= $isPrintMode ? 'min-height: 297mm; overflow: hidden;' : ($isEmbed ? 'min-height: auto;' : 'min-height: 297mm;') ?>
            padding: 0;
            box-shadow: <?= ($isPrintMode || $isEmbed) ? 'none' : '0 0 25px rgba(0,0,0,0.2)' ?>;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .lh-template-header,
        .lh-template-footer {
            width: 100%;
            line-height: 0;
            flex-shrink: 0;
        }

        .lh-template-banner {
            width: 100%;
            height: auto;
            display: block;
        }

        .lh-content {
            flex: 1 1 auto;
            padding: 28px 54px 36px;
            font-size: 15px;
            line-height: 1.55;
        }

        .lh-from-block {
            text-align: right;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            font-size: 14px;
            line-height: 1.45;
        }

        .lh-from-block div + div { margin-top: 0.08rem; }

        .lh-recipient {
            margin-bottom: 1.1rem;
            text-transform: uppercase;
            font-size: 14px;
            line-height: 1.45;
        }

        .lh-recipient-name { font-weight: 700; }

        .lh-meta {
            margin: 0.85rem 0 1.15rem;
            font-size: 13px;
            line-height: 1.55;
            text-transform: none;
        }

        .lh-meta-row {
            display: grid;
            grid-template-columns: 78px 1fr;
            gap: 0.35rem;
        }

        .lh-meta-row + .lh-meta-row { margin-top: 0.15rem; }
        .lh-meta-label { color: var(--muted); }
        .lh-meta-val { font-weight: 600; }

        .lh-subject {
            text-align: center;
            margin: 0 0 1.25rem;
        }

        .lh-subject-text {
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 4px;
            letter-spacing: 0.02em;
        }

        .pay-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 1.5rem;
            font-size: 13px;
        }

        .pay-table th {
            background: #111;
            color: #fff;
            padding: 10px 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            text-align: left;
            font-weight: 600;
            font-family: var(--ui-font);
        }

        .pay-table th.text-end,
        .pay-table td.text-end { text-align: right; }

        .pay-table td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .pay-table td:not(:last-child),
        .pay-table th:not(:last-child) {
            border-right: 1px solid rgba(255,255,255,0.12);
        }

        .pay-table td:not(:last-child) {
            border-right: 1px solid var(--line);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 1.75rem;
            margin-top: 0.35rem;
        }

        .payment-info h4 {
            margin: 0 0 0.55rem;
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
            text-transform: none;
        }

        .payment-method {
            font-weight: 700;
            margin-bottom: 0.9rem;
            font-size: 15px;
        }

        .bank-row {
            display: grid;
            grid-template-columns: 118px 1fr;
            gap: 0.35rem;
            font-size: 13px;
            margin-bottom: 0.35rem;
        }

        .bank-label { color: var(--muted); }
        .bank-val { font-weight: 600; }

        .totals-box { padding-top: 0.15rem; }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.55rem;
            font-size: 13px;
        }

        .total-row.final {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #bbb;
            font-weight: 700;
            font-size: 16px;
            align-items: center;
        }

        .signature-section {
            margin-top: 1.75rem;
            display: flex;
            justify-content: flex-end;
            text-align: left;
        }

        .signature-block { width: 220px; }

        .signer-name {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 0.15rem;
        }

        .signer-title {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 0.35rem;
        }

        .signature-image-wrap {
            height: 56px;
            display: flex;
            align-items: flex-end;
            margin-bottom: 0.25rem;
        }

        .signature-image-wrap img {
            max-height: 56px;
            max-width: 100%;
            object-fit: contain;
        }

        .signature-line {
            border-top: 1px solid #111;
            padding-top: 0.4rem;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #333;
        }

        .controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            gap: 10px;
            font-family: system-ui, sans-serif;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }

        .btn-success { background: #198754; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }

<?php if ($isEmbed): ?>
        html, body { height: auto; width: 100%; }
        .lh-content { padding: 22px 36px 28px; }
<?php endif; ?>

<?php if ($isPrintMode): ?>
        @page { size: A4; margin: 0; }
        .payslip-sheet { width: 210mm; min-height: 297mm; }
<?php endif; ?>

        @media (max-width: 768px) {
            body { padding: 10px 0; }
            .lh-content { padding: 18px 18px 24px; }
            .footer-grid { grid-template-columns: 1fr; gap: 1.25rem; }
            .lh-from-block { text-align: left; }
        }

        @media print {
            body { background: none; padding: 0; }
            .controls { display: none; }
            .payslip-sheet { box-shadow: none; margin: 0; width: 100%; max-width: none; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

    <?php if (!$isPrintMode && !$isEmbed): ?>
    <div class="controls no-print">
        <button class="btn btn-success" id="downloadBtn" onclick="downloadPDF()" type="button">Download PDF</button>
        <button class="btn btn-secondary" onclick="window.close()" type="button">Close</button>
    </div>
    <?php endif; ?>

    <article class="payslip-sheet" id="payslipContent">
        <header class="lh-template-header">
            <img
                src="<?= htmlspecialchars($letterheadHeaderUrl, ENT_QUOTES, 'UTF-8') ?>"
                alt=""
                class="lh-template-banner"
            >
        </header>

        <div class="lh-content">
            <div class="lh-from-block">
                <?php if ($companyNameLine !== ''): ?><div><?= htmlspecialchars($companyNameLine) ?></div><?php endif; ?>
                <?php if ($companyBoxLine !== ''): ?><div><?= htmlspecialchars($companyBoxLine) ?></div><?php endif; ?>
                <?php if ($companyCityLine !== ''): ?><div><?= htmlspecialchars($companyCityLine) ?></div><?php endif; ?>
                <div><?= htmlspecialchars($letterDateLabel) ?>.</div>
            </div>

            <div class="lh-recipient">
                <div class="lh-recipient-name"><?= htmlspecialchars(strtoupper((string) $slip['full_name'])) ?>,</div>
                <?php if (trim((string) ($slip['department'] ?? '')) !== ''): ?>
                <div><?= htmlspecialchars(strtoupper((string) $slip['department'])) ?></div>
                <?php endif; ?>
                <div>TIN: <?= htmlspecialchars((string) ($slip['tin_number'] ?? 'N/A')) ?></div>
            </div>

            <div class="lh-meta">
                <div class="lh-meta-row">
                    <span class="lh-meta-label">Email</span>
                    <span class="lh-meta-val"><?= htmlspecialchars((string) ($slip['email'] ?? 'Not set')) ?></span>
                </div>
                <div class="lh-meta-row">
                    <span class="lh-meta-label">Period</span>
                    <span class="lh-meta-val"><?= htmlspecialchars($periodLabel) ?></span>
                </div>
                <div class="lh-meta-row">
                    <span class="lh-meta-label">Run Date</span>
                    <span class="lh-meta-val"><?= htmlspecialchars(date('M d, Y', strtotime((string) $slip['run_date']))) ?></span>
                </div>
            </div>

            <div class="lh-subject">
                <span class="lh-subject-text"><?= htmlspecialchars($payslipRef) ?></span>
            </div>

            <table class="pay-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">No</th>
                        <th style="width: 44%;">Item Description</th>
                        <th style="width: 24%;" class="text-end">Earnings</th>
                        <th style="width: 24%;" class="text-end">Deductions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>Basic Salary</td>
                        <td class="text-end"><?= number_format((float) $slip['basic_salary'], 2) ?></td>
                        <td class="text-end">-</td>
                    </tr>
                    <?php if ((float) $slip['total_allowances'] > 0): ?>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>Total Allowances</td>
                        <td class="text-end"><?= number_format((float) $slip['total_allowances'], 2) ?></td>
                        <td class="text-end">-</td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float) $slip['monthly_adjustment'] != 0): ?>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>Monthly Adjustment</td>
                        <td class="text-end"><?= (float) $slip['monthly_adjustment'] > 0 ? number_format((float) $slip['monthly_adjustment'], 2) : '-' ?></td>
                        <td class="text-end"><?= (float) $slip['monthly_adjustment'] < 0 ? number_format(abs((float) $slip['monthly_adjustment']), 2) : '-' ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>NSSF Contribution (10%)</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= number_format((float) $slip['nssf_deduction'], 2) ?></td>
                    </tr>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>P.A.Y.E (Tax)</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= number_format((float) $slip['tax_deduction'], 2) ?></td>
                    </tr>
                    <?php if ((float) $slip['other_deductions'] > 0): ?>
                    <tr>
                        <td><?= ++$rowNo ?>.</td>
                        <td>Other Deductions</td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= number_format((float) $slip['other_deductions'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="footer-grid">
                <div class="payment-info">
                    <h4>Payment Method :</h4>
                    <div class="payment-method">Bank Transfer</div>
                    <div class="bank-row">
                        <span class="bank-label">Bank Name :</span>
                        <span class="bank-val"><?= htmlspecialchars((string) ($slip['bank_name'] ?? 'N/A')) ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Name :</span>
                        <span class="bank-val"><?= htmlspecialchars((string) $slip['full_name']) ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Number :</span>
                        <span class="bank-val"><?= htmlspecialchars((string) ($slip['account_number'] ?? 'N/A')) ?></span>
                    </div>
                </div>

                <div>
                    <div class="totals-box">
                        <div class="total-row">
                            <span class="bank-label">Gross Salary</span>
                            <span class="bank-val"><?= number_format((float) $slip['gross_salary'], 2) ?></span>
                        </div>
                        <div class="total-row">
                            <span class="bank-label">Total Deductions</span>
                            <span class="bank-val">-<?= number_format((float) $slip['nssf_deduction'] + (float) $slip['tax_deduction'] + (float) $slip['other_deductions'], 2) ?></span>
                        </div>
                        <div class="total-row final">
                            <span>Total Net Pay</span>
                            <span><?= number_format((float) $slip['net_salary'], 2) ?></span>
                        </div>
                    </div>

                    <div class="signature-section">
                        <div class="signature-block">
                            <div class="signer-name"><?= htmlspecialchars((string) ($slip['runner_name'] ?? 'Authorized Signatory')) ?></div>
                            <div class="signer-title"><?= htmlspecialchars((string) ($slip['runner_role'] ?? 'Finance Director')) ?></div>
                            <div class="signature-image-wrap">
                                <?php if ($sigUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($sigUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Signature">
                                <?php endif; ?>
                            </div>
                            <div class="signature-line">Authorized Signature</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="lh-template-footer">
            <img
                src="<?= htmlspecialchars($letterheadFooterUrl, ENT_QUOTES, 'UTF-8') ?>"
                alt=""
                class="lh-template-banner"
            >
        </footer>
    </article>

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

        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('download')) {
                setTimeout(downloadPDF, 1000);
            }
        });
    </script>
</body>
</html>
