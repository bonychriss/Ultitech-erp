<?php

declare(strict_types=1);

function mySalesDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/config.php';
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once dirname(__DIR__) . '/functions.php';
        $booted = true;
    }
}

function mySalesDeskRequireAccess(): void
{
    mySalesDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
    $_SESSION['active_module'] = 'sales';
}

function mySalesDeskModuleQuery(): string
{
    $module = strtolower(trim((string) ($_GET['module'] ?? 'sales')));

    return $module !== '' ? $module : 'sales';
}

function mySalesDeskWebBase(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (str_ends_with($dir, '/api')) {
            $dir = rtrim(dirname($dir), '/');
        }

        return $dir;
    }

    return sales_app_url('modules/sales/my-sales');
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function mySalesDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/my-sales/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;
    $base = mySalesDeskWebBase();
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
    $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
    $assetVersion = (string) max((int) $cssVersion, (int) $jsVersion, (int) filemtime($distIndex));

    return [
        'distHtml' => $distHtml,
        'assetBase' => $base . '/frontend/dist/assets/',
        'apiUrl' => $base . '/api',
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => $assetVersion,
        'jsVersion' => $assetVersion,
    ];
}

function mySalesDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    $dashCssPath = dirname(__DIR__) . '/dashboard/dashboard.css';
    $dashCssVer = is_file($dashCssPath) ? (int) filemtime($dashCssPath) : time();
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $salesModuleBase = $script !== '' ? rtrim(dirname(dirname($script)), '/') : rtrim(sales_app_url('modules/sales'), '/');
    $dashCssUrl = $salesModuleBase . '/dashboard/dashboard.css?v=' . $dashCssVer;
    $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars($dashCssUrl, ENT_QUOTES, 'UTF-8') . '">';

    return implode("\n    ", $parts);
}

/**
 * @param list<array<string, mixed>> $orders
 * @return list<array<string, mixed>>
 */
function mySalesDeskNormalizeOrders(array $orders, string $module): array
{
    $out = [];
    foreach ($orders as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $out[] = [
            'id' => $id,
            'ref_number' => (string) ($row['order_number'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? $row['company_name'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'url' => sales_module_url('orders/view.php', ['id' => $id, 'module' => $module]),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $invoices
 * @return list<array<string, mixed>>
 */
function mySalesDeskNormalizeInvoices(array $invoices, string $module): array
{
    $out = [];
    foreach ($invoices as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $out[] = [
            'id' => $id,
            'ref_number' => (string) ($row['invoice_number'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? $row['company_name'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['invoice_date'] ?? $row['created_at'] ?? ''),
            'url' => sales_module_url('invoices/view.php', ['id' => $id, 'module' => $module]),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function mySalesDeskFetchUserOrders(int $userId, ?int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): array
{
    if ($userId <= 0) {
        return [];
    }

    $pdo = sales_pdo();
    try {
        $orderSql = "
            SELECT so.id, so.order_number, so.total_amount, so.status, so.created_at, c.company_name AS customer_name
            FROM sales_orders so
            JOIN customers c ON c.id = so.customer_id
            WHERE so.created_by = ?";
        $orderParams = [$userId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($orderSql, $orderParams, 'sales_orders', 'so');
        }
        if ($dateFrom !== null && $dateTo !== null) {
            $orderSql .= ' AND DATE(so.created_at) >= ? AND DATE(so.created_at) <= ?';
            $orderParams[] = $dateFrom;
            $orderParams[] = $dateTo;
        }
        $orderSql .= ' ORDER BY so.created_at DESC';
        if ($limit !== null && $limit > 0) {
            $orderSql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $pdo->prepare($orderSql);
        $stmt->execute($orderParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('my sales orders: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function mySalesDeskFetchUserInvoices(int $userId, ?int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): array
{
    if ($userId <= 0) {
        return [];
    }

    $pdo = sales_pdo();
    try {
        $invSql = "
            SELECT i.id, i.invoice_number, i.total_amount, i.status, i.invoice_date, i.created_at, c.company_name AS customer_name
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.created_by = ? AND i.status != 'cancelled'";
        $invParams = [$userId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($invSql, $invParams, 'invoices', 'i');
        }
        if ($dateFrom !== null && $dateTo !== null) {
            $invSql .= ' AND DATE(COALESCE(i.invoice_date, i.created_at)) >= ? AND DATE(COALESCE(i.invoice_date, i.created_at)) <= ?';
            $invParams[] = $dateFrom;
            $invParams[] = $dateTo;
        }
        $invSql .= ' ORDER BY COALESCE(i.invoice_date, i.created_at) DESC';
        if ($limit !== null && $limit > 0) {
            $invSql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $pdo->prepare($invSql);
        $stmt->execute($invParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('my sales invoices: ' . $e->getMessage());

        return [];
    }
}

function mySalesDeskParseDateParam(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);

    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

/**
 * @return array{include:string,date_from:string,date_to:string,period_label:string}
 */
function mySalesDeskParseExportOptions(): array
{
    $include = strtolower(trim((string) ($_GET['include'] ?? 'both')));
    if (!in_array($include, ['quotes', 'invoices', 'both'], true)) {
        $include = 'both';
    }

    $dateFrom = mySalesDeskParseDateParam((string) ($_GET['date_from'] ?? ''));
    $dateTo = mySalesDeskParseDateParam((string) ($_GET['date_to'] ?? ''));

    if ($dateFrom === null) {
        $dateFrom = date('Y-m-01');
    }
    if ($dateTo === null) {
        $dateTo = date('Y-m-d');
    }
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'include' => $include,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'period_label' => date('d M Y', strtotime($dateFrom)) . ' - ' . date('d M Y', strtotime($dateTo)),
    ];
}

function mySalesDeskDownloadUrl(string $module): string
{
    return mySalesDeskWebBase() . '/api/export.php?module=' . rawurlencode($module);
}

function mySalesDeskPdfText(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E]/', '?', $text) ?: '';
}

function mySalesDeskPdfMoney(float $amount, string $currency): string
{
    return $currency . ' ' . number_format($amount, 0, '.', ',');
}

function mySalesDeskPdfDate(string $value): string
{
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);

    return $ts ? date('d M Y', $ts) : $value;
}

function mySalesDeskPdfStatusLabel(string $status): string
{
    $st = strtolower(trim($status));
    $map = [
        'draft' => 'Draft',
        'quotation' => 'Quote',
        'confirmed' => 'Confirmed',
        'invoiced' => 'Invoiced',
        'sent' => 'Sent',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
    ];

    return $map[$st] ?? ucfirst($status !== '' ? $status : '-');
}

function mySalesDeskPdfLogoSupported(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
}

function mySalesDeskResolveLogoDiskPath(): ?string
{
    $rootDir = dirname(__DIR__, 3);

    $tryDisk = static function (string $disk) use ($rootDir): ?string {
        $disk = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $disk);
        if (!str_starts_with($disk, $rootDir) && !preg_match('#^[A-Za-z]:\\\\#', $disk)) {
            $disk = $rootDir . DIRECTORY_SEPARATOR . ltrim($disk, DIRECTORY_SEPARATOR);
        }
        if (is_file($disk) && mySalesDeskPdfLogoSupported($disk)) {
            return $disk;
        }

        return null;
    };

    if (function_exists('getCompanyLogoUrl')) {
        $url = trim(getCompanyLogoUrl());
        if ($url !== '' && !str_starts_with($url, 'data:') && !preg_match('#^https?://#i', $url)) {
            $path = parse_url($url, PHP_URL_PATH) ?? $url;
            if (function_exists('app_url')) {
                $appPath = parse_url(app_url(''), PHP_URL_PATH) ?? '';
                $appPath = rtrim((string) $appPath, '/');
                if ($appPath !== '' && str_starts_with($path, $appPath)) {
                    $rel = ltrim(substr($path, strlen($appPath)), '/');
                    $resolved = $tryDisk($rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
            $resolved = $tryDisk($rootDir . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    $logoRel = '';
    if (function_exists('getCompanySetting')) {
        $logoRel = trim((string) getCompanySetting('company_logo', ''));
    }
    if ($logoRel === '' && function_exists('getCompanyInfo')) {
        $info = getCompanyInfo();
        $logoRel = trim((string) ($info['company_logo'] ?? ''));
    }

    $candidates = [];
    if ($logoRel !== '') {
        if (str_starts_with($logoRel, 'assets/')) {
            $candidates[] = $rootDir . '/' . ltrim($logoRel, '/');
        } else {
            $candidates[] = $rootDir . '/assets/images/' . ltrim($logoRel, '/');
            $candidates[] = $rootDir . '/' . ltrim($logoRel, '/');
        }
    }

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    if ($companyId > 0) {
        $uploadDir = $rootDir . '/assets/images/company_logos/' . $companyId;
        if (is_dir($uploadDir)) {
            $matches = glob($uploadDir . '/*.{png,jpg,jpeg,gif}', GLOB_BRACE) ?: [];
            usort($matches, static function ($a, $b) {
                return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
            });
            foreach ($matches as $match) {
                $candidates[] = $match;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $resolved = $tryDisk((string) $candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

/**
 * @return array{width:float,height:float,yBottom:float}
 */
function mySalesDeskPdfDrawLogo(FPDF $pdf, ?string $logoPath, float $topY = 12.0): array
{
    if ($logoPath === null) {
        return ['width' => 0.0, 'height' => 0.0, 'yBottom' => $topY];
    }

    [$pxW, $pxH] = @getimagesize($logoPath) ?: [0, 0];
    $logoH = 16.0;
    $logoW = ($pxW > 0 && $pxH > 0) ? ($logoH * $pxW / $pxH) : 42.0;
    if ($logoW > 60.0) {
        $logoW = 60.0;
        $logoH = ($pxW > 0 && $pxH > 0) ? ($logoW * $pxH / $pxW) : 16.0;
    }

    $rightMargin = 10.0;
    $logoX = $pdf->GetPageWidth() - $rightMargin - $logoW;
    $pdf->Image($logoPath, $logoX, $topY, $logoW, $logoH);
    $yBottom = $topY + $logoH + 2.0;

    return ['width' => $logoW, 'height' => $logoH, 'yBottom' => $yBottom];
}

function mySalesDeskPdfBottomLimit(FPDF $pdf): float
{
    // Match SetMargins(10, 12, 10) in mySalesExportPdf � avoid protected FPDF properties.
    return (float) $pdf->GetPageHeight() - 10.0;
}

function mySalesDeskPdfNeedsPage(FPDF $pdf, float $requiredHeight): bool
{
    return $pdf->GetY() + $requiredHeight > mySalesDeskPdfBottomLimit($pdf);
}

function mySalesDeskPdfAddPage(FPDF $pdf): void
{
    $pdf->AddPage();
    $pdf->SetY(12.0);
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 * @param list<float> $widths
 */
function mySalesDeskPdfDrawTable(FPDF $pdf, string $title, array $headers, array $rows, array $widths, float $totalAmount, string $currency): void
{
    $tableWidth = array_sum($widths);
    $amountIndex = count($headers) - 2;
    $labelWidth = 0.0;
    for ($i = 0; $i < $amountIndex; $i++) {
        $labelWidth += $widths[$i];
    }
    $amountWidth = $widths[$amountIndex];
    $tailWidth = $tableWidth - $labelWidth - $amountWidth;

    $drawHeader = static function () use ($pdf, $headers, $widths): void {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(124, 58, 237);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetDrawColor(221, 214, 254);
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 8, mySalesDeskPdfText($header), 1, 0, 'L', true);
        }
        $pdf->Ln();
    };

    $drawTotal = static function () use ($pdf, $labelWidth, $amountWidth, $tailWidth, $totalAmount, $currency, $rows, $drawHeader): void {
        if (mySalesDeskPdfNeedsPage($pdf, 8.0)) {
            mySalesDeskPdfAddPage($pdf);
            $drawHeader();
        }

        $count = count($rows);
        $totalLabel = $count > 0
            ? 'Total (' . $count . ' ' . ($count === 1 ? 'record' : 'records') . ')'
            : 'Total';

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(237, 233, 254);
        $pdf->SetTextColor(91, 33, 182);
        $pdf->SetDrawColor(221, 214, 254);
        $pdf->Cell($labelWidth, 8, mySalesDeskPdfText($totalLabel), 1, 0, 'R', true);
        $pdf->Cell($amountWidth, 8, mySalesDeskPdfText(mySalesDeskPdfMoney($totalAmount, $currency)), 1, 0, 'R', true);
        $pdf->Cell($tailWidth, 8, mySalesDeskPdfText(''), 1, 1, 'L', true);
    };

    $sectionHeight = 5.0 + 7.0 + 1.0 + 8.0 + 8.0;
    if (mySalesDeskPdfNeedsPage($pdf, $sectionHeight)) {
        mySalesDeskPdfAddPage($pdf);
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 7, mySalesDeskPdfText($title), 0, 1, 'L');
    $pdf->Ln(1);
    $drawHeader();

    if ($rows === []) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell($tableWidth, 8, mySalesDeskPdfText('No records found.'), 1, 1, 'C', true);
        $drawTotal();

        return;
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->SetDrawColor(226, 232, 240);
    $fill = false;

    foreach ($rows as $row) {
        if (mySalesDeskPdfNeedsPage($pdf, 7.0)) {
            mySalesDeskPdfAddPage($pdf);
            $drawHeader();
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(51, 65, 85);
            $pdf->SetDrawColor(226, 232, 240);
        }

        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        foreach ($row as $i => $cell) {
            $align = $i === $amountIndex ? 'R' : 'L';
            $pdf->Cell($widths[$i], 7, mySalesDeskPdfText((string) $cell), 1, 0, $align, $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }

    $drawTotal();
}

function mySalesDeskLoadFpdf(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $fpdfPath = dirname(__DIR__, 3) . '/store-management-system/labels/fpdf.php';
    if (!is_file($fpdfPath)) {
        throw new RuntimeException('PDF engine is not available.');
    }

    require_once $fpdfPath;
    $loaded = true;
}

function mySalesDeskExportReject(string $message): void
{
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function mySalesExportPdf(): void
{
    mySalesDeskRequireAccess();
    mySalesDeskLoadFpdf();

    $exportOpts = mySalesDeskParseExportOptions();
    $includeQuotes = in_array($exportOpts['include'], ['quotes', 'both'], true);
    $includeInvoices = in_array($exportOpts['include'], ['invoices', 'both'], true);

    $data = mySalesInitData();
    $userId = (int) ($data['user']['id'] ?? 0);
    $currency = (string) ($data['currency'] ?? 'TZS');
    $displayName = (string) ($data['user']['display_name'] ?? 'User');
    $companyName = (string) ($data['company']['name'] ?? '');

    $quotes = $includeQuotes
        ? mySalesDeskFetchUserOrders($userId, null, $exportOpts['date_from'], $exportOpts['date_to'])
        : [];
    $invoices = $includeInvoices
        ? mySalesDeskFetchUserInvoices($userId, null, $exportOpts['date_from'], $exportOpts['date_to'])
        : [];

    $quoteCount = count($quotes);
    $invoiceCount = count($invoices);
    if ($includeQuotes && $includeInvoices) {
        if ($quoteCount === 0 && $invoiceCount === 0) {
            mySalesDeskExportReject('Nothing to download for these dates. Try choosing different dates.');
        }
    } elseif ($includeQuotes && $quoteCount === 0) {
        mySalesDeskExportReject('No quotes for these dates. Try choosing different dates.');
    } elseif ($includeInvoices && $invoiceCount === 0) {
        mySalesDeskExportReject('No invoices for these dates. Try choosing different dates.');
    }

    $quoteRows = [];
    $quoteTotal = 0.0;
    foreach ($quotes as $row) {
        $amount = (float) ($row['total_amount'] ?? 0);
        $quoteTotal += $amount;
        $quoteRows[] = [
            (string) ($row['order_number'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            mySalesDeskPdfStatusLabel((string) ($row['status'] ?? '')),
            mySalesDeskPdfMoney($amount, $currency),
            mySalesDeskPdfDate((string) ($row['created_at'] ?? '')),
        ];
    }

    $invoiceRows = [];
    $invoiceTotal = 0.0;
    foreach ($invoices as $row) {
        $amount = (float) ($row['total_amount'] ?? 0);
        $invoiceTotal += $amount;
        $invoiceRows[] = [
            (string) ($row['invoice_number'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            mySalesDeskPdfStatusLabel((string) ($row['status'] ?? '')),
            mySalesDeskPdfMoney($amount, $currency),
            mySalesDeskPdfDate((string) ($row['invoice_date'] ?? $row['created_at'] ?? '')),
        ];
    }

    $safeName = preg_replace('/[^a-z0-9_-]+/i', '_', $displayName) ?: 'user';
    $filename = 'my_sales_record_' . strtolower($safeName) . '_' . date('Y-m-d') . '.pdf';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(10, 12, 10);
    $pdf->AddPage();

    $logoPath = mySalesDeskResolveLogoDiskPath();
    $logoInfo = mySalesDeskPdfDrawLogo($pdf, $logoPath);
    $textWidth = $pdf->GetPageWidth() - 20.0;
    if ($logoInfo['width'] > 0) {
        $textWidth -= $logoInfo['width'] + 8.0;
    }

    $pdf->SetY(12);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor(124, 58, 237);
    $pdf->Cell($textWidth, 10, mySalesDeskPdfText('My Sales Record'), 0, 1, 'L');

    if ($companyName !== '') {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($textWidth, 6, mySalesDeskPdfText($companyName), 0, 1, 'L');
    }

    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($textWidth, 5, mySalesDeskPdfText('Salesperson: ' . $displayName), 0, 1, 'L');
    $pdf->Cell($textWidth, 5, mySalesDeskPdfText('Period: ' . $exportOpts['period_label']), 0, 1, 'L');
    $pdf->Cell($textWidth, 5, mySalesDeskPdfText('Generated: ' . date('d M Y H:i')), 0, 1, 'L');
    $pdf->SetY(max($pdf->GetY(), $logoInfo['yBottom']) + 2);

    $tableHeaders = ['Reference', 'Customer', 'Status', 'Amount', 'Date'];
    $tableWidths = [34.0, 68.0, 28.0, 34.0, 26.0];

    if ($includeQuotes) {
        mySalesDeskPdfDrawTable($pdf, 'My Quotes', $tableHeaders, $quoteRows, $tableWidths, $quoteTotal, $currency);
    }
    if ($includeInvoices) {
        mySalesDeskPdfDrawTable($pdf, 'My Invoices', $tableHeaders, $invoiceRows, $tableWidths, $invoiceTotal, $currency);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $pdf->Output('D', $filename);
    exit;
}

/**
 * @return array<string, mixed>
 */
function mySalesInitData(): array
{
    $module = mySalesDeskModuleQuery();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $displayName = (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
    $month = date('Y-m');
    $year = date('Y');
    $pdo = sales_pdo();

    $monthlySales = (float) (getUserMonthlySales($userId, $month) ?: 0);
    $pendingOrders = (int) (getPendingOrders($userId) ?: 0);
    $overdueInvoices = (int) (getOverdueInvoices($userId) ?: 0);
    $commissionEarned = (float) (getCommissionEarned($userId, $month) ?: 0);
    $yearlySales = (float) (getYearlySalesTotal($userId, $year) ?: 0);
    $yearlyTarget = (float) (getSalesTarget($userId, $year) ?: 0);
    if ($yearlyTarget <= 0) {
        $yearlyTarget = (float) (getSalesTarget($userId, $month) ?: 0);
    }
    $targetPct = $yearlyTarget > 0 ? min(100.0, ($yearlySales / $yearlyTarget) * 100) : 0.0;

    $lastMonth = date('Y-m', strtotime('-1 month'));
    $lastMonthSales = (float) (getUserMonthlySales($userId, $lastMonth) ?: 0);
    $salesTrend = $lastMonthSales > 0
        ? (int) round((($monthlySales - $lastMonthSales) / $lastMonthSales) * 100)
        : 0;

    $recentOrders = mySalesDeskFetchUserOrders($userId, 20);
    $recentInvoices = mySalesDeskFetchUserInvoices($userId, 20);

    $companyInfo = function_exists('getCompanyInfo') ? getCompanyInfo() : [];
    $currency = 'TZS';
    try {
        $row = $pdo->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['default_currency'])) {
            $currency = strtoupper(trim((string) $row['default_currency']));
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    return [
        'module' => $module,
        'currency' => $currency,
        'company' => [
            'name' => (string) ($companyInfo['company_name'] ?? ''),
            'theme_color' => (string) ($companyInfo['theme_color'] ?? '#3b82f6'),
        ],
        'user' => [
            'id' => $userId,
            'display_name' => $displayName,
        ],
        'metrics' => [
            'monthly_sales' => $monthlySales,
            'pending_orders' => $pendingOrders,
            'overdue_invoices' => $overdueInvoices,
            'commission_earned' => $commissionEarned,
            'sales_trend' => $salesTrend,
        ],
        'yearly' => [
            'target' => $yearlyTarget,
            'sales' => $yearlySales,
            'percent' => $targetPct,
            'year' => $year,
        ],
        'recent_orders' => mySalesDeskNormalizeOrders($recentOrders, $module),
        'recent_invoices' => mySalesDeskNormalizeInvoices($recentInvoices, $module),
        'list_preview_limit' => 5,
        'period_label' => date('F Y', strtotime($month . '-01')),
        'export_defaults' => [
            'include' => 'both',
            'date_from' => date('Y-m-01'),
            'date_to' => date('Y-m-d'),
        ],
        'urls' => [
            'dashboard' => sales_module_url('dashboard/index.php', ['module' => $module]),
            'create_quote' => sales_module_url('orders/create.php', ['module' => $module]),
            'create_invoice' => sales_module_url('invoices/create.php', ['module' => $module]),
            'download_record' => mySalesDeskDownloadUrl($module),
            'orders' => sales_module_url('orders/index.php', ['module' => $module]),
            'invoices' => sales_module_url('invoices/index.php', ['module' => $module]),
        ],
    ];
}

function mySalesRenderReactShell(): void
{
    $assets = mySalesDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>My Sales</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>My Sales</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/my-sales/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'My Sales';
    $employeeHeaderTitle = 'My Sales';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $init = mySalesInitData();
    $themeColor = (string) (($init['company']['theme_color'] ?? '') ?: '#3b82f6');

    $cfg = [
        'module' => mySalesDeskModuleQuery(),
        'theme_color' => $themeColor,
    ];

    $mySalesHeadMarkup = '<link rel="stylesheet" crossorigin href="'
        . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8')
        . '">'
        . "\n" . '<script>window.__MY_SALES_API_BASE__ = '
        . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES)
        . ';window.__MY_SALES_CFG__ = '
        . json_encode($cfg, JSON_UNESCAPED_SLASHES)
        . ';</script>';

    require dirname(__FILE__) . '/my-sales-react-shell.php';
    exit;
}
