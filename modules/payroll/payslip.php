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
           runner.full_name as runner_name,
           runner.department as runner_department,
           runner.role as runner_role,
           runner.signature_path as runner_signature_path
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

if ($isEmbed || $isPrintMode || isset($_GET['download'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

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
$companyStreet = $pickCompanyValue(['street', 'street_address', 'physical_address']);

// Build right-aligned from-block lines (letterhead style: one item per line).
$fromLines = [];
$companyNameLine = strtoupper(trim($companyName));
if ($companyNameLine !== '') {
    if (substr($companyNameLine, -1) !== ',') {
        $companyNameLine .= ',';
    }
    $fromLines[] = $companyNameLine;
}

$normalizeFromLine = static function (string $line): string {
    $line = strtoupper(trim($line));
    $line = preg_replace('/\s+/', ' ', $line) ?? $line;
    return rtrim($line, " \t.,;") . ',';
};

if ($companyStreet !== '') {
    $fromLines[] = $normalizeFromLine($companyStreet);
}

if ($companyBox !== '') {
    $boxLine = preg_match('/^\s*p\.?\s*o\.?\s*box/i', $companyBox)
        ? $companyBox
        : ('P.O. BOX ' . $companyBox);
    $fromLines[] = $normalizeFromLine($boxLine);
}

$cityCountry = trim(implode(', ', array_filter([$companyCity, $companyCountry], static fn ($p) => trim((string) $p) !== '')));
if ($cityCountry !== '') {
    $fromLines[] = strtoupper(rtrim($cityCountry, " \t.,;")) . '.';
}

// If structured fields are sparse, split the stored address into vertical lines.
if (count($fromLines) <= 1 && $companyAddress !== '') {
    $rawAddress = trim($companyAddress);
    $parts = preg_split('/\s*,\s*/', $rawAddress) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));

    $streetParts = [];
    $boxPart = '';
    $cityParts = [];
    foreach ($parts as $part) {
        if ($boxPart === '' && preg_match('/p\.?\s*o\.?\s*box/i', $part)) {
            $boxPart = $part;
            continue;
        }
        if ($boxPart !== '' || preg_match('/\b(dar\s*es\s*salaam|tanzania|tz)\b/i', $part)) {
            $cityParts[] = $part;
            continue;
        }
        $streetParts[] = $part;
    }

    if ($streetParts !== []) {
        $fromLines[] = $normalizeFromLine(implode(', ', $streetParts));
    }
    if ($boxPart !== '') {
        $fromLines[] = $normalizeFromLine($boxPart);
    }
    if ($cityParts !== []) {
        $fromLines[] = strtoupper(rtrim(implode(', ', $cityParts), " \t.,;")) . '.';
    } elseif ($streetParts === [] && $boxPart === '') {
        // Fallback: one comma-separated chunk per line.
        foreach ($parts as $idx => $part) {
            $isLast = ($idx === count($parts) - 1);
            $fromLines[] = $isLast
                ? (strtoupper(rtrim($part, " \t.,;")) . '.')
                : $normalizeFromLine($part);
        }
    }
}

$letterDateLabel = date('d-m-Y', strtotime((string) $slip['run_date']));
$fromLines[] = $letterDateLabel . '.';
// De-dupe consecutive identical lines.
$deduped = [];
foreach ($fromLines as $line) {
    $prev = $deduped[count($deduped) - 1] ?? null;
    if ($prev !== null && strcasecmp((string) $prev, (string) $line) === 0) {
        continue;
    }
    $deduped[] = $line;
}
$fromLines = $deduped;

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
$payslipRef = 'PAYSLIP NO. '
    . str_pad((string) $slip['payroll_run_id'], 3, '0', STR_PAD_LEFT)
    . '-'
    . str_pad((string) $slip['id'], 5, '0', STR_PAD_LEFT);

$sigUrl = '';
$runnerId = (int) ($slip['run_by'] ?? 0);
$sigPath = trim((string) ($slip['runner_signature_path'] ?? ''));
if ($sigPath === '' && $runnerId > 0 && function_exists('getUserSignaturePathById')) {
    $fromHelper = getUserSignaturePathById($runnerId);
    $sigPath = is_string($fromHelper) ? trim($fromHelper) : '';
}
if ($sigPath !== '') {
    $sigRel = ltrim(str_replace('\\', '/', $sigPath), '/');
    $sigFsCandidates = [
        dirname(__DIR__, 2) . '/' . $sigRel,
        dirname(__DIR__, 2) . '/assets/signatures/' . basename($sigRel),
    ];
    $sigFs = '';
    foreach ($sigFsCandidates as $candidate) {
        if (is_file($candidate)) {
            $sigFs = $candidate;
            break;
        }
    }
    if ($sigFs !== '') {
        if (function_exists('mediaUrlFromPath')) {
            $sigUrl = (string) mediaUrlFromPath($sigRel);
        } elseif (function_exists('app_url')) {
            $sigUrl = rtrim((string) app_url('/' . $sigRel), '/');
        } else {
            $sigUrl = $baseUrl . '/' . $sigRel;
        }
        if ($sigUrl !== '') {
            $sigUrl .= (strpos($sigUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($sigFs);
        }
    }
}

$runnerName = trim((string) ($slip['runner_name'] ?? ''));
if ($runnerName === '') {
    $runnerName = 'Authorized Signatory';
}
$runnerPosition = trim((string) ($slip['runner_department'] ?? ''));
if ($runnerPosition === '') {
    $roleRaw = strtolower(trim((string) ($slip['runner_role'] ?? '')));
    if ($roleRaw === 'admin') {
        $runnerPosition = 'Administrator';
    } elseif ($roleRaw === 'finance') {
        $runnerPosition = 'Finance';
    } elseif ($roleRaw !== '' && $roleRaw !== 'employee') {
        $runnerPosition = ucwords(str_replace('_', ' ', $roleRaw));
    }
}

$stampUrl = '';
$stampCandidates = [
    'letterhead/stamps/ultimate-stamp-white.png',
    'letterhead/stamps/ultimate-stamp-upright.png',
    'letterhead/stamps/ultimate-stamp-cutout.png',
    'modules/letter/frontend/src/assets/ultimate-stamp.png',
];
$appRootFs = dirname(__DIR__, 2);
foreach ($stampCandidates as $stampRel) {
    $stampFs = $appRootFs . '/' . $stampRel;
    if (!is_file($stampFs)) {
        continue;
    }
    $stampUrl = function_exists('app_url')
        ? rtrim((string) app_url('/' . $stampRel), '/')
        : ($baseUrl . '/' . $stampRel);
    $stampUrl .= (strpos($stampUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($stampFs);
    break;
}

$bankName = trim((string) ($slip['bank_name'] ?? ''));
$bankLogoFile = '';
$bankLogoAlt = $bankName !== '' ? $bankName : 'Bank';
$bankNameLower = strtolower($bankName);
$bankLogoCatalog = [
    ['file' => 'uba.png', 'alt' => 'UBA', 'match' => ['united bank for africa', 'united bank of africa', 'uba']],
    ['file' => 'crdb.png', 'alt' => 'CRDB', 'match' => ['crdb']],
    ['file' => 'nmb.jpg', 'alt' => 'NMB', 'match' => ['nmb']],
    ['file' => 'nbc.svg', 'alt' => 'NBC', 'match' => ['nbc']],
    ['file' => 'equity.png', 'alt' => 'Equity Bank', 'match' => ['equity']],
    ['file' => 'absa.svg', 'alt' => 'Absa', 'match' => ['absa', 'barclays']],
    ['file' => 'standard-chartered.svg', 'alt' => 'Standard Chartered', 'match' => ['standard chartered']],
    ['file' => 'kcb.png', 'alt' => 'KCB', 'match' => ['kcb']],
    ['file' => 'dtb.png', 'alt' => 'DTB', 'match' => ['diamond trust', 'dtb']],
    ['file' => 'exim.png', 'alt' => 'Exim Bank', 'match' => ['exim']],
    ['file' => 'azania.png', 'alt' => 'Azania Bank', 'match' => ['azania']],
    ['file' => 'boa.png', 'alt' => 'Bank of Africa', 'match' => ['bank of africa', 'boa']],
    ['file' => 'access.png', 'alt' => 'Access Bank', 'match' => ['access bank', 'access']],
    ['file' => 'ecobank.svg', 'alt' => 'Ecobank', 'match' => ['ecobank']],
    ['file' => 'tpb.png', 'alt' => 'TPB', 'match' => ['tpb']],
    ['file' => 'amana.png', 'alt' => 'Amana Bank', 'match' => ['amana']],
    ['file' => 'im.png', 'alt' => 'I&M Bank', 'match' => ['i&m', 'i and m']],
    ['file' => 'maendeleo.png', 'alt' => 'Maendeleo Bank', 'match' => ['maendeleo']],
    ['file' => 'pbz.png', 'alt' => 'PBZ', 'match' => ['pbz', 'people\'s bank of zanzibar']],
    ['file' => 'baroda.png', 'alt' => 'Bank of Baroda', 'match' => ['baroda']],
    ['file' => 'ubl.svg', 'alt' => 'UBL', 'match' => ['ubl']],
    ['file' => 'citi.svg', 'alt' => 'Citibank', 'match' => ['citi']],
    ['file' => 'canara.svg', 'alt' => 'Canara Bank', 'match' => ['canara']],
    ['file' => 'icici.svg', 'alt' => 'ICICI', 'match' => ['icici']],
    ['file' => 'hsbc.svg', 'alt' => 'HSBC', 'match' => ['hsbc']],
    ['file' => 'fnb.svg', 'alt' => 'FNB', 'match' => ['fnb', 'first national']],
    ['file' => 'letshego.png', 'alt' => 'Letshego', 'match' => ['letshego']],
    ['file' => 'gtbank.svg', 'alt' => 'GTBank', 'match' => ['guaranty trust', 'gtbank', 'gt bank']],
    ['file' => 'dcb.svg', 'alt' => 'DCB', 'match' => ['dcb']],
    ['file' => 'mwanga.png', 'alt' => 'Mwanga Hakika', 'match' => ['mwanga']],
    ['file' => 'mufindi.png', 'alt' => 'Mufindi', 'match' => ['mufindi', 'muco']],
    ['file' => 'mwalimu.svg', 'alt' => 'Mwalimu', 'match' => ['mwalimu']],
    ['file' => 'yetu.png', 'alt' => 'Yetu', 'match' => ['yetu']],
];
if ($bankNameLower !== '') {
    foreach ($bankLogoCatalog as $bankMeta) {
        foreach ($bankMeta['match'] as $needle) {
            if ($bankNameLower === $needle || strpos($bankNameLower, $needle) !== false) {
                $bankLogoFile = $bankMeta['file'];
                $bankLogoAlt = $bankMeta['alt'];
                break 2;
            }
        }
    }
}

$bankLogoUrl = '';
if ($bankLogoFile !== '') {
    $bankLogoRel = 'modules/payroll/frontend/src/assets/banks/' . $bankLogoFile;
    $bankLogoFs = dirname(__DIR__, 2) . '/' . $bankLogoRel;
    if (is_file($bankLogoFs)) {
        $bankLogoUrl = function_exists('app_url')
            ? rtrim((string) app_url('/' . $bankLogoRel), '/')
            : ($baseUrl . '/' . $bankLogoRel);
        $bankLogoUrl .= (strpos($bankLogoUrl, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($bankLogoFs);
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
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            font-size: 14px;
            line-height: 1.4;
        }

        .lh-from-block div {
            display: block;
            max-width: 100%;
        }

        .lh-from-block div + div { margin-top: 0.12rem; }

        .lh-from-ref {
            font-weight: 700;
            text-decoration: none;
            margin-bottom: 0.45rem !important;
            letter-spacing: 0.02em;
        }

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
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
            white-space: nowrap;
        }

        .payslip-bank-logo {
            width: 18px;
            height: 18px;
            object-fit: contain;
            flex: 0 0 18px;
            display: block;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            outline: none !important;
            padding: 0 !important;
            margin: 0;
            border-radius: 0 !important;
        }

        .bank-row {
            display: grid;
            grid-template-columns: 118px 1fr;
            gap: 0.35rem;
            font-size: 13px;
            margin-bottom: 0.35rem;
            align-items: center;
        }

        .bank-label { color: var(--muted); }
        .bank-val { font-weight: 600; }

        .totals-box { padding-top: 0; }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            margin: 0;
            padding: 0.12rem 0;
            font-size: 13px;
            line-height: 1.25;
        }

        .total-row.final {
            margin-top: 0.2rem;
            padding-top: 0.35rem;
            border-top: 1px solid #bbb;
            font-weight: 700;
            font-size: 13px;
            align-items: center;
        }

        .signature-section {
            margin-top: 1.75rem;
            display: flex;
            justify-content: flex-end;
            text-align: left;
            margin-right: -0.75rem;
        }

        .signature-block {
            width: 220px;
            margin-left: auto;
        }

        .payslip-stamp-wrap {
            width: 108px;
            height: 108px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }

        .payslip-stamp {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            background: transparent;
        }

        .signature-image-wrap {
            min-height: 48px;
            height: 56px;
            display: flex;
            align-items: flex-end;
            margin-bottom: 0.2rem;
        }

        .signature-image-wrap img {
            max-height: 56px;
            max-width: 100%;
            object-fit: contain;
            display: block;
        }

        .signer-name {
            font-weight: 700;
            font-size: 13px;
            margin: 0 0 0.1rem;
            color: #111;
            text-transform: none;
        }

        .signer-title {
            font-size: 12px;
            color: #111;
            margin: 0;
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
            .lh-from-block { text-align: left; align-items: flex-start; }
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
                <div class="lh-from-ref"><?= htmlspecialchars($payslipRef) ?></div>
                <?php foreach ($fromLines as $fromLine): ?>
                <div><?= htmlspecialchars((string) $fromLine) ?></div>
                <?php endforeach; ?>
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
                    <div class="bank-row">
                        <span class="bank-label">Payment Method :</span>
                        <span class="bank-val payment-method">
                            <?php if ($bankLogoUrl !== ''): ?>
                            <img
                                class="payslip-bank-logo"
                                src="<?= htmlspecialchars($bankLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($bankLogoAlt, ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <?php endif; ?>
                            <span>Bank Transfer</span>
                        </span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Bank Name :</span>
                        <span class="bank-val"><?= htmlspecialchars($bankName !== '' ? $bankName : 'N/A') ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Name :</span>
                        <span class="bank-val"><?= htmlspecialchars((string) $slip['full_name']) ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Number :</span>
                        <span class="bank-val"><?= htmlspecialchars((string) ($slip['account_number'] ?? 'N/A')) ?></span>
                    </div>
                    <?php if ($stampUrl !== ''): ?>
                    <div class="payslip-stamp-wrap" aria-hidden="true">
                        <img
                            class="payslip-stamp"
                            src="<?= htmlspecialchars($stampUrl, ENT_QUOTES, 'UTF-8') ?>"
                            alt=""
                        >
                    </div>
                    <?php endif; ?>
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
                            <div class="signature-image-wrap">
                                <?php if ($sigUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($sigUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Signature of <?= htmlspecialchars($runnerName, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </div>
                            <div class="signer-name"><?= htmlspecialchars($runnerName) ?></div>
                            <?php if ($runnerPosition !== ''): ?>
                            <div class="signer-title"><?= htmlspecialchars($runnerPosition) ?></div>
                            <?php endif; ?>
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
            if (!element) {
                return Promise.reject(new Error('Payslip content not found.'));
            }
            if (typeof html2pdf !== 'function') {
                return Promise.reject(new Error('PDF library not loaded.'));
            }
            if (btn) {
                btn.innerHTML = 'Generating...';
                btn.disabled = true;
            }

            const opt = {
                margin: 0,
                filename: 'Payslip_<?= str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $slip['full_name']) ?>_<?= date('M_Y', mktime(0,0,0,$slip['month'], 1, $slip['year'])) ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, letterRendering: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            return html2pdf()
                .from(element)
                .set(opt)
                .save()
                .then(() => {
                    if (btn) {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch((err) => {
                    if (btn) {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                    throw err;
                });
        }

        window.downloadPayslipPdf = downloadPDF;

        window.addEventListener('load', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('download')) {
                setTimeout(() => {
                    downloadPDF().catch(() => {});
                }, 800);
            }
        });
    </script>
</body>
</html>
