<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// Filters (same as records.php)
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$routeFrom = trim((string) ($_GET['route_from'] ?? ''));
$routeTo = trim((string) ($_GET['route_to'] ?? ''));
$customer = trim((string) ($_GET['customer'] ?? ''));
$currency = 'TZS';

$periodLabel = 'All dates';
if ($dateFrom !== '' && $dateTo !== '') {
    $periodLabel = $dateFrom . ' to ' . $dateTo;
} elseif ($dateFrom !== '') {
    $periodLabel = 'From ' . $dateFrom;
} elseif ($dateTo !== '') {
    $periodLabel = 'Up to ' . $dateTo;
}

// Build query (dispatch_notes)
$where = [];
$params = [];
if ($dateFrom !== '') { $where[] = "dn.dispatch_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = "dn.dispatch_date <= ?"; $params[] = $dateTo; }
if ($routeFrom !== '') { $where[] = "dn.dispatch_from = ?"; $params[] = $routeFrom; }
if ($routeTo !== '') { $where[] = "dn.dispatch_to = ?"; $params[] = $routeTo; }
if ($customer !== '') { $where[] = "dn.address_to = ?"; $params[] = $customer; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $st = $pdo->prepare("
        SELECT dn.id, dn.dispatch_date, dn.dispatch_from, dn.dispatch_to, dn.address_to, dn.contents, dn.route_price
        FROM dispatch_notes dn
        $whereSql
        ORDER BY dn.dispatch_date ASC, dn.id ASC
    ");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

// Invoice header fields
$invoiceDate = $_GET['invoice_date'] ?? date('Y-m-d');
$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if ($invoiceNumber === '') {
    // simple generated number: INV-YYYYMM-####
    $prefix = 'INV-' . date('Ym') . '-';
    $invoiceNumber = $prefix . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Compute totals
$total = 0.0;
foreach ($rows as $r) {
    $total += (float) ($r['route_price'] ?? 0);
}

// Invoice To: if customer filter set, use it; else use single distinct address_to or dispatch_to; else "Multiple"
$invoiceTo = $customer;
if ($invoiceTo === '' && $rows) {
    $uniq = [];
    foreach ($rows as $r) {
        $k = trim((string) ($r['address_to'] ?? ''));
        if ($k !== '') { $uniq[$k] = true; }
    }
    if (count($uniq) === 1) {
        $invoiceTo = (string) array_key_first($uniq);
    } else {
        $uniq2 = [];
        foreach ($rows as $r) {
            $k = trim((string) ($r['dispatch_to'] ?? ''));
            if ($k !== '') { $uniq2[$k] = true; }
        }
        if (count($uniq2) === 1) {
            $invoiceTo = (string) array_key_first($uniq2);
        } elseif (count($uniq) > 1 || count($uniq2) > 1) {
            $invoiceTo = 'Multiple';
        }
    }
}

// Recipient details (from address book or explicit query params)
$recipientId = (int) ($_GET['recipient_id'] ?? 0);
$toDetails = [
    'company_name' => '',
    'address' => '',
    'email' => '',
    'phone' => '',
    'tin' => '',
    'vrn' => '',
];
if ($recipientId > 0) {
    try {
        $st = $pdo->prepare("SELECT company_name, address, email, phone, tin, vrn
                             FROM dispatch_invoice_recipients
                             WHERE id = ? AND user_id = ? AND is_active = 1
                             LIMIT 1");
        $st->execute([$recipientId, (int) ($_SESSION['user_id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $toDetails['company_name'] = (string) ($r['company_name'] ?? '');
            $toDetails['address'] = (string) ($r['address'] ?? '');
            $toDetails['email'] = (string) ($r['email'] ?? '');
            $toDetails['phone'] = (string) ($r['phone'] ?? '');
            $toDetails['tin'] = (string) ($r['tin'] ?? '');
            $toDetails['vrn'] = (string) ($r['vrn'] ?? '');
        }
    } catch (Throwable $e) {
        // ignore
    }
} else {
    $toDetails['company_name'] = trim((string) ($_GET['to_name'] ?? ''));
    $toDetails['address'] = trim((string) ($_GET['to_address'] ?? ''));
    $toDetails['email'] = trim((string) ($_GET['to_email'] ?? ''));
    $toDetails['phone'] = trim((string) ($_GET['to_phone'] ?? ''));
    $toDetails['tin'] = trim((string) ($_GET['to_tin'] ?? ''));
    $toDetails['vrn'] = trim((string) ($_GET['to_vrn'] ?? ''));
}
if ($toDetails['company_name'] !== '') {
    $invoiceTo = $toDetails['company_name'];
}

// Match Sales invoice/quotation print format (use sales_settings when present)
$dispatchCompanyId = (int) (currentCompanyId() ?? 0);
if ($dispatchCompanyId <= 0 && function_exists('getRequestedCompany')) {
    $reqCo = getRequestedCompany();
    if (is_array($reqCo) && !empty($reqCo['id'])) {
        $dispatchCompanyId = (int) $reqCo['id'];
    }
}

$company_settings = null;
try {
    if ($dispatchCompanyId > 0 && function_exists('columnExists') && columnExists('sales_settings', 'company_id', $pdo)) {
        $stCo = $pdo->prepare('SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1');
        $stCo->execute([$dispatchCompanyId]);
        $company_settings = $stCo->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $company_settings = $pdo->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $company_settings = null;
}
if (!$company_settings) {
    $company_settings = [
        'company_name' => (defined('COMPANY_NAME') ? (string) COMPANY_NAME : 'Ultimate General Trading Company'),
        'company_address' => (defined('COMPANY_ADDRESS') ? (string) COMPANY_ADDRESS : ''),
        'company_logo' => 'Untitled.jpg',
        'company_phone' => (defined('COMPANY_PHONE') ? (string) COMPANY_PHONE : ''),
        'company_email' => (defined('COMPANY_EMAIL') ? (string) COMPANY_EMAIL : ''),
        'company_website' => '',
        'bank_details' => '',
        'default_currency' => 'TZS',
    ];
}

if (($nameVal = getCompanySetting('company_name')) && trim($nameVal) !== '') {
    $company_settings['company_name'] = $nameVal;
}
if (($addrVal = getCompanySetting('company_address')) && trim($addrVal) !== '') {
    $company_settings['company_address'] = $addrVal;
}
if (($phoneVal = getCompanySetting('company_phone')) && trim($phoneVal) !== '') {
    $company_settings['company_phone'] = $phoneVal;
}
if (($emailVal = getCompanySetting('company_email')) && trim($emailVal) !== '') {
    $company_settings['company_email'] = $emailVal;
}
if (($bankVal = getCompanySetting('bank_details')) && trim($bankVal) !== '') {
    $company_settings['bank_details'] = $bankVal;
}
if (($logoVal = getCompanySetting('company_logo')) && trim($logoVal) !== '') {
    $company_settings['company_logo'] = trim($logoVal);
}

$companyProfile = null;
if (function_exists('getCurrentCompany')) {
    $companyProfile = getCurrentCompany();
}
if ((!is_array($companyProfile) || empty($companyProfile)) && function_exists('getRequestedCompany')) {
    $companyProfile = getRequestedCompany();
}
if (is_array($companyProfile) && !empty($companyProfile)) {
    $profileAddress = trim((string) ($companyProfile['address'] ?? ''));
    $profilePhone = trim((string) ($companyProfile['phone'] ?? ''));
    $profileEmail = trim((string) ($companyProfile['email'] ?? ''));
    if ($profileAddress !== '') {
        $company_settings['company_address'] = $profileAddress;
    }
    if ($profilePhone !== '') {
        $company_settings['company_phone'] = $profilePhone;
    }
    if ($profileEmail !== '') {
        $company_settings['company_email'] = $profileEmail;
    }
    if ($dispatchCompanyId <= 0 && !empty($companyProfile['id'])) {
        $dispatchCompanyId = (int) $companyProfile['id'];
    }
}

$company_settings['company_logo_url'] = function_exists('getCompanyLogoUrl')
    ? getCompanyLogoUrl($dispatchCompanyId > 0 ? $dispatchCompanyId : null)
    : '';
$invoiceLogoUrl = trim((string) ($company_settings['company_logo_url'] ?? ''));
if ($invoiceLogoUrl === '' && !empty($company_settings['company_logo'])) {
    $logoRel = (string) $company_settings['company_logo'];
    $logoIsData = (function_exists('str_starts_with') && str_starts_with($logoRel, 'data:'))
        || (strncmp($logoRel, 'data:', 5) === 0);
    if (preg_match('#^https?://#i', $logoRel) || $logoIsData) {
        $invoiceLogoUrl = $logoRel;
    } else {
        $logoStartsAssets = (function_exists('str_starts_with') && str_starts_with($logoRel, 'assets/'))
            || (strncmp($logoRel, 'assets/', 7) === 0);
        $logoRel = $logoStartsAssets ? $logoRel : 'assets/images/' . ltrim($logoRel, '/');
        $logoDisk = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($logoRel, '/'));
        if (is_file($logoDisk) && function_exists('app_url')) {
            $invoiceLogoUrl = app_url('/' . ltrim($logoRel, '/'));
        } elseif (is_file($logoDisk)) {
            $invoiceLogoUrl = '/' . ltrim($logoRel, '/');
        }
    }
}
if ($invoiceLogoUrl === '' && function_exists('app_url')) {
    $invoiceLogoUrl = app_url('/assets/images/logo.svg');
}

if (!empty($company_settings['default_currency'])) {
    $currency = (string) $company_settings['default_currency'];
}

// Load saved dispatch invoice footer/payment info (per-user)
$footer = null;
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatch_invoice_footer_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ac_number VARCHAR(80) NULL,
            ac_name VARCHAR(190) NULL,
            bank_name VARCHAR(190) NULL,
            phones VARCHAR(190) NULL,
            address_line VARCHAR(255) NULL,
            website VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_footer_user (user_id),
            CONSTRAINT fk_footer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // ignore
}
try {
    $stF = $pdo->prepare("SELECT ac_number, ac_name, bank_name, phones, address_line, website FROM dispatch_invoice_footer_settings WHERE user_id = ? LIMIT 1");
    $stF->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $footer = $stF->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $footer = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= htmlspecialchars($invoiceNumber) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <style>
        body {
            background: #525659;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 10.5pt;
        }

        .sheet-container {
            width: 210mm;
            min-height: 270mm;
            padding: 10mm 15mm;
            margin: 10mm auto;
            background: white;
            box-shadow: 0 0 0.5cm rgba(0,0,0,0.1);
            position: relative;
            page-break-after: always;
        }
        .sheet-container:last-child { page-break-after: auto; }

        .sheet-title { font-size: 16pt; font-weight: bold; color: #333; margin: 0; }

        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }
        .o-table th {
            border-bottom: 2px solid #000;
            text-transform: uppercase;
            font-size: 10pt;
            background-color: gold;
            color: #000;
            padding: 4px 5px;
            vertical-align: middle;
        }
        .o-table td { padding: 4px 6px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 10.5pt; }
        .o-table .num { text-align: right; }
        .o-table th.num { text-align: right; }

        .totals-table td { padding: 3px 8px; font-size: 10.5pt; }

        body.pdf-mode { background: white; }
        body.pdf-mode .sheet-container {
            box-shadow: none;
            margin: 0;
            border: none;
            width: 100% !important;
            min-height: auto !important;
            padding-top: 5mm !important;
            padding-bottom: 5mm !important;
        }
        /* Keep button visible while generating to show loader */

        tr { page-break-inside: avoid; }

        @media print {
            body { background: white; }
            .sheet-container { width: 100%; margin: 0; box-shadow: none; padding: 0mm; }
            #downloadBtn { display: none; }
            .dispatch-invoice-chrome { display: none !important; }
            .layout-main-wrapper { display: block !important; }
        }

        .dn-spinner{
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.55);
            border-top-color: rgba(255,255,255,1);
            border-radius: 999px;
            display: inline-block;
            animation: dnSpin .75s linear infinite;
        }
        @keyframes dnSpin { to { transform: rotate(360deg); } }
        #downloadBtn.is-busy{
            opacity: .92;
        }
    </style>
</head>
<body class="dashboard dispatch-page">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF() {
    document.body.classList.add('pdf-mode');
    const element = document.getElementById('pdf-content');
    const opt = {
        margin: [0,0,0,0],
        filename: 'Dispatch_Invoice_<?= htmlspecialchars($invoiceNumber, ENT_QUOTES) ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css','legacy'] }
    };
    const btn = document.getElementById('downloadBtn');
    if (btn) {
        btn.disabled = true;
        btn.classList.add('is-busy');
        btn.setAttribute('aria-busy', 'true');
        btn.setAttribute('data-prev-html', btn.innerHTML);
        btn.innerHTML = '<span class="dn-spinner" aria-hidden="true"></span><span>Preparingï¿½</span>';
    }
    html2pdf().set(opt).from(element).save().then(function(){
        document.body.classList.remove('pdf-mode');
        if (btn) {
            const prev = btn.getAttribute('data-prev-html');
            if (prev) btn.innerHTML = prev;
            btn.classList.remove('is-busy');
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        }
    }).catch(function(){
        document.body.classList.remove('pdf-mode');
        if (btn) {
            const prev = btn.getAttribute('data-prev-html');
            if (prev) btn.innerHTML = prev;
            btn.classList.remove('is-busy');
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        }
        alert('PDF generation failed. Please try again.');
    });
}
</script>

<?php
// App chrome (header/sidebar) ï¿½ hidden in print/pdf mode
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] pb-0 dispatch-invoice-chrome" style="min-height:0;">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="invoice_prepare.php?module=dispatch&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&route_from=<?= urlencode($routeFrom) ?>&route_to=<?= urlencode($routeTo) ?>&customer=<?= urlencode($customer) ?>&invoice_date=<?= urlencode((string)$invoiceDate) ?>&invoice_number=<?= urlencode((string)$invoiceNumber) ?>&recipient_id=<?= (int)$recipientId ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Invoice setup
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-file-invoice text-[#2563EB]"></i><span>Invoice</span>
                    </h1>
                    <span class="text-sm text-gray-500 fw-semibold"><?= htmlspecialchars($invoiceNumber) ?></span>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <button type="button" id="downloadBtn" class="btn mov-btn-primary rounded-md px-3 py-2 fw-semibold border-0" onclick="downloadPDF()">
                    <i class="fas fa-download me-2"></i>Download PDF
                </button>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1"></i><?= htmlspecialchars($periodLabel) ?></span>
                <span class="text-gray-300">|</span>
                <span class="text-gray-600"><i class="fas fa-user text-gray-400 me-1"></i><?= htmlspecialchars($invoiceTo !== '' ? $invoiceTo : '-') ?></span>
            </div>
        </div>

        <!-- no extra preview chrome space -->
    </div>
</main>

<?php
// Pagination similar to invoice/quotation templates
// Keep conservative limits so html2pdf doesn't auto-split inside a page
// (auto-split would skip our explicit continuation header/footer layout).
$first_page_limit = 14;
$subsequent_page_limit = 18;
$chunks = [];
if (count($rows) <= $first_page_limit) {
    $chunks[] = $rows;
} else {
    $chunks[] = array_slice($rows, 0, $first_page_limit);
    $remaining = array_slice($rows, $first_page_limit);
    if (!empty($remaining)) {
        $chunks = array_merge($chunks, array_chunk($remaining, $subsequent_page_limit));
    }
}
$total_pages = count($chunks);
?>

<div id="pdf-content" style="margin-top: 0;">
<?php foreach ($chunks as $page_idx => $page_rows): ?>
    <?php $is_last_page = ($page_idx === $total_pages - 1); ?>
    <div class="sheet-container">
        <div style="display: flex; flex-direction: column; min-height: 270mm;">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h1 class="sheet-title">
                    <?= htmlspecialchars($invoiceNumber) ?>
                    <?php if($total_pages > 1): ?>
                        <small style="font-size: 10pt; font-weight: normal; color: #666;">(Page <?= (int)$page_idx + 1 ?> of <?= (int)$total_pages ?>)</small>
                    <?php endif; ?>
                </h1>
                <div style="text-align: right; width: 60%;">
                    <?php if ($invoiceLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($invoiceLogoUrl) ?>" alt="Company Logo" style="max-height: 80px; max-width: 220px; object-fit: contain; margin-bottom: 10px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <!-- Company name intentionally hidden for this dispatch invoice -->
                    <div style="font-size: 0.9rem; color: #333; white-space: pre-line;"><?= htmlspecialchars((string)($company_settings['company_address'] ?? '')) ?></div>
                    <?php if(!empty($company_settings['company_phone'])): ?>
                        <div style="font-size: 0.9rem; color: #333;">Phone: <?= htmlspecialchars((string)$company_settings['company_phone']) ?></div>
                    <?php endif; ?>
                    <?php if(!empty($company_settings['company_email'])): ?>
                        <div style="font-size: 0.9rem; color: #333;">Email: <?= htmlspecialchars((string)$company_settings['company_email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer Details (Page 1 Only) -->
            <?php if ($page_idx === 0): ?>
                <div style="margin-bottom: 20px;">
                    <strong style="font-size: 1.1rem; color: #111;"><?= htmlspecialchars($invoiceTo !== '' ? $invoiceTo : '-') ?></strong><br>
                    <?php if (trim((string) ($toDetails['address'] ?? '')) !== ''): ?>
                        <span style="color: #555;"><?= nl2br(htmlspecialchars((string) $toDetails['address'])) ?></span><br>
                    <?php endif; ?>
                    <?php if (trim((string) ($toDetails['email'] ?? '')) !== ''): ?>
                        <span style="color: #666;"><?= htmlspecialchars((string) $toDetails['email']) ?></span><br>
                    <?php endif; ?>
                    <?php if (trim((string) ($toDetails['phone'] ?? '')) !== ''): ?>
                        <span style="color: #666;"><?= htmlspecialchars((string) $toDetails['phone']) ?></span><br>
                    <?php endif; ?>
                    <?php if (trim((string) ($toDetails['tin'] ?? '')) !== ''): ?>
                        <span style="color: #666;">TIN: <?= htmlspecialchars((string) $toDetails['tin']) ?></span><br>
                    <?php endif; ?>
                    <?php if (trim((string) ($toDetails['vrn'] ?? '')) !== ''): ?>
                        <span style="color: #666;">VRN: <?= htmlspecialchars((string) $toDetails['vrn']) ?></span><br>
                    <?php endif; ?>
                    <?php if ($routeFrom !== '' || $routeTo !== ''): ?>
                        <span style="color: #666;"><?= htmlspecialchars(trim($routeFrom . ($routeFrom !== '' && $routeTo !== '' ? ' ? ' : '') . $routeTo)) ?></span><br>
                    <?php endif; ?>
                    <span style="color: #666;">Period: <?= htmlspecialchars($periodLabel) ?></span>
                </div>

                <!-- Date Info Bar -->
                <div style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                    <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Invoice Date</div>
                        <div style="font-size: 0.85rem;"><?= htmlspecialchars(date('d/m/Y', strtotime((string)$invoiceDate))) ?></div>
                    </div>
                    <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Currency</div>
                        <div style="font-size: 0.85rem;"><?= htmlspecialchars($currency) ?></div>
                    </div>
                    <div style="flex: 1; padding: 4px 8px;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Prepared By</div>
                        <div style="font-size: 0.85rem;"><?= htmlspecialchars((string)($_SESSION['full_name'] ?? '-')) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 10px;"></div>
            <?php endif; ?>

            <!-- Items Table -->
            <table class="o-table">
                <thead>
                    <tr>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 28%;">Route</th>
                        <th style="width: 40%;">Product</th>
                        <th class="num" style="width: 20%;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($page_rows)): ?>
                        <tr><td colspan="4" style="color:#666; padding: 8px;">No dispatch records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($page_rows as $r): ?>
                            <?php
                                $dt = (string) ($r['dispatch_date'] ?? '');
                                $route = trim((string) (($r['dispatch_from'] ?? '') . ' TO ' . ($r['dispatch_to'] ?? '')));
                                $prod = trim((string) ($r['contents'] ?? ''));
                                $amt = (float) ($r['route_price'] ?? 0);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($dt !== '' ? date('j-M', strtotime($dt)) : '') ?></td>
                                <td><?= htmlspecialchars($route !== 'TO' ? $route : '') ?></td>
                                <td><?= htmlspecialchars($prod) ?></td>
                                <td class="num"><?= number_format($amt, 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($is_last_page): ?>
                <div class="totals-area" style="display: flex; justify-content: flex-end; margin-top: 5px;">
                    <table class="totals-table">
                        <tr>
                            <td class="grand-total" style="font-weight: bold;">Total:</td>
                            <td class="grand-total" style="font-weight: bold;"><?= htmlspecialchars($currency) ?> <?= number_format($total, 2) ?></td>
                        </tr>
                    </table>
                </div>

                <?php
                    $acNo = $footer && !empty($footer['ac_number']) ? (string) $footer['ac_number'] : '';
                    $acName = $footer && !empty($footer['ac_name']) ? (string) $footer['ac_name'] : '';
                    $bankName = $footer && !empty($footer['bank_name']) ? (string) $footer['bank_name'] : '';
                    $phones = $footer && !empty($footer['phones']) ? (string) $footer['phones'] : '';
                    $addrLine = $footer && !empty($footer['address_line']) ? (string) $footer['address_line'] : '';
                    $web = $footer && !empty($footer['website']) ? (string) $footer['website'] : (string)($company_settings['company_website'] ?? '');
                    $hasFooter = ($acNo !== '' || $acName !== '' || $bankName !== '' || $phones !== '' || $addrLine !== '' || $web !== '');
                ?>
            <?php endif; ?>

            <div style="margin-top: auto; padding-top: 16px; width: 100%;">
                <?php if ($is_last_page): ?>
                    <?php if ($hasFooter): ?>
                        <div style="border-top: 1px solid #e5e7eb; padding-top: 10px;">
                            <div style="font-weight: bold; margin-bottom: 6px; display: inline-block;">Payment Info</div>
                            <?php if ($acNo !== ''): ?><div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;">A/C NUMBER: <?= htmlspecialchars($acNo) ?></div><?php endif; ?>
                            <?php if ($acName !== ''): ?><div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;">A/C NAME: <?= htmlspecialchars($acName) ?></div><?php endif; ?>
                            <?php if ($bankName !== ''): ?><div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;">BANK NAME: <?= htmlspecialchars($bankName) ?></div><?php endif; ?>
                            <?php if ($phones !== ''): ?><div style="margin-top: 10px; color: #555; font-size: 0.85rem; line-height: 1.4;"><?= htmlspecialchars($phones) ?></div><?php endif; ?>
                            <?php if ($addrLine !== ''): ?><div style="color: #555; font-size: 0.85rem; line-height: 1.4;"><?= htmlspecialchars($addrLine) ?></div><?php endif; ?>
                            <?php if ($web !== ''): ?><div style="color: #555; font-size: 0.85rem; line-height: 1.4;"><?= htmlspecialchars($web) ?></div><?php endif; ?>
                        </div>
                    <?php elseif (!empty($company_settings['bank_details'])): ?>
                        <div style="border-top: 1px solid #e5e7eb; padding-top: 10px;">
                            <div style="font-weight: bold; margin-bottom: 4px; display: inline-block;">Bank Transfer Details</div>
                            <div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;"><?= htmlspecialchars((string)$company_settings['bank_details']) ?></div>
                            <?php if (!empty($company_settings['company_website'])): ?>
                                <div style="margin-top: 8px; font-size: 0.85rem;">
                                    Visit our website at <a href="<?= htmlspecialchars((string)$company_settings['company_website']) ?>" target="_blank" style="text-decoration: none; color: #008784; font-weight: bold;"><?= htmlspecialchars((string)$company_settings['company_website']) ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
</body>
</html>

