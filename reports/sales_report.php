<?php
require_once '../includes/functions.php';
requireLogin();

global $pdo;

// Date range filter (default: last 6 months)
$startDate = $_GET['start_date'] ?? date('Y-m-01', strtotime('-5 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
// Top products count: 5, 10, 15, or 20 (default 5)
$topProductsLimit = (int)($_GET['top_n'] ?? 5);
$allowedTopN = [5, 10, 15, 20];
if (!in_array($topProductsLimit, $allowedTopN, true)) {
    $topProductsLimit = 5;
}
// Monthly target card: selected month (YYYY-MM)
$targetMonth = $_GET['target_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
    $targetMonth = date('Y-m');
}

// Handle Export (CSV, Excel, Word, PDF)
if (isset($_GET['export'])) {
    try {
        $exportFormat = strtolower($_GET['format'] ?? 'csv');
        $exportSection = $_GET['section'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-01', strtotime('-5 months'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Validate date range
        if (strtotime($startDate) === false || strtotime($endDate) === false) {
            throw new Exception('Invalid date range');
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            throw new Exception('Start date must be before end date');
        }
        
        // Ensure we have sales module tables
        $useSalesModule = false;
        try {
            $pdo->query("SELECT 1 FROM invoices LIMIT 1");
            $useSalesModule = true;
        } catch (Exception $e) {
            error_log("Sales module check failed: " . $e->getMessage());
        }
        if ($useSalesModule && file_exists(__DIR__ . '/../modules/sales/functions.php')) {
            require_once __DIR__ . '/../modules/sales/functions.php';
        }
    } catch (Exception $e) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(400);
        die('<html><body><h1>Export Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="sales_report.php">Return to Report</a></p></body></html>');
    }
    
    // Helper function to generate HTML table for Excel/Word/PDF
    function generateHTMLTable($headers, $rows, $title = '', $hasImages = false, $format = 'excel') {
        $html = '';
        if ($title) {
            $html .= '<h2 style="margin-bottom: 15px; color: #333;">' . htmlspecialchars($title) . '</h2>';
        }
        
        // Determine column widths based on content
        $colWidths = [];
        $isImageCol = [];
        foreach ($headers as $idx => $h) {
            $headerLower = strtolower($h);
            if ($headerLower === 'image') {
                $colWidths[] = '60px';
                $isImageCol[$idx] = true;
            } elseif (in_array($headerLower, ['product', 'customer', 'rep'])) {
                $colWidths[] = '200px';
                $isImageCol[$idx] = false;
            } elseif (in_array($headerLower, ['invoice', 'invoices'])) {
                $colWidths[] = '120px';
                $isImageCol[$idx] = false;
            } elseif (strpos($headerLower, 'amount') !== false || strpos($headerLower, 'revenue') !== false || strpos($headerLower, 'total') !== false) {
                $colWidths[] = '150px';
                $isImageCol[$idx] = false;
            } else {
                $colWidths[] = '100px';
                $isImageCol[$idx] = false;
            }
        }
        
        // Only add colgroup for non-Excel formats (Excel has issues with it)
        $useColgroup = ($format !== 'excel');
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 11pt;' . ($useColgroup ? ' table-layout: fixed;' : '') . '">';
        if ($useColgroup) {
            $html .= '<colgroup>';
            foreach ($colWidths as $width) {
                $html .= '<col style="width: ' . $width . ';" />';
            }
            $html .= '</colgroup>';
        }
        $html .= '<thead><tr style="background-color: #4361ee; color: white; font-weight: bold;">';
        foreach ($headers as $idx => $h) {
            $align = ($isImageCol[$idx] ?? false) ? 'center' : (strpos(strtolower($h), 'amount') !== false || strpos(strtolower($h), 'revenue') !== false || strpos(strtolower($h), 'total') !== false ? 'right' : 'left');
            $html .= '<th style="padding: 8px; text-align: ' . $align . '; border: 1px solid #333; background-color: #4361ee; color: white;">' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            $colIndex = 0;
            foreach ($row as $cell) {
                $align = ($isImageCol[$colIndex] ?? false) ? 'center' : 
                        (is_array($cell) ? 'left' : 
                        (is_numeric(str_replace([',', ' '], '', $cell)) ? 'right' : 'left'));
                
                // Check if this is an image cell (array with 'image' key)
                if (is_array($cell) && isset($cell['image'])) {
                    $imgHtml = '';
                    if (!empty($cell['image'])) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $imgPath = htmlspecialchars($cell['image']);
                        // Build absolute URL
                        $imgUrl = $scheme . '://' . $host . '/' . $imgPath;
                        // Use img tag with proper attributes for Word/Excel compatibility
                        $imgHtml = '<img src="' . $imgUrl . '" alt="' . htmlspecialchars($cell['text'] ?? '') . '" width="40" height="40" style="width: 40px; height: 40px; object-fit: cover;" />';
                    } else {
                        $icon = $cell['icon'] ?? 'ðŸ“·';
                        $imgHtml = '<span style="font-size: 20px;">' . $icon . '</span>';
                    }
                    $html .= '<td style="padding: 5px; text-align: center; vertical-align: middle; border: 1px solid #333; width: 60px;">' . $imgHtml . '</td>';
                } else {
                    $cellValue = is_array($cell) ? ($cell['text'] ?? '') : $cell;
                    $html .= '<td style="padding: 5px; text-align: ' . $align . '; border: 1px solid #333; vertical-align: middle; word-wrap: break-word;">' . htmlspecialchars($cellValue) . '</td>';
                }
                $colIndex++;
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
    
    // Generate data based on section
    $data = [];
    $sectionTitle = '';
    
    switch ($exportSection) {
        case 'sales_by_rep':
            $sectionTitle = 'Sales by Rep';
            $headers = ['Image', 'Rep', 'Invoice', 'Customer', 'Amount (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                try {
                    $pdo->query("SELECT profile_photo FROM users LIMIT 1");
                    $hasProfilePhoto = true;
                } catch (Exception $e) {
                    $hasProfilePhoto = false;
                }
                $photoField = $hasProfilePhoto ? ', u.profile_photo' : '';
                $stmt = $pdo->prepare("
                    SELECT i.id, i.created_by, u.username, u.full_name, i.invoice_number, i.total_amount, c.company_name as customer_name $photoField
                    FROM invoices i
                    LEFT JOIN users u ON i.created_by = u.id
                    LEFT JOIN customers c ON i.customer_id = c.id
                    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
                    ORDER BY i.created_by, i.invoice_date DESC
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $repName = $row['full_name'] ?: $row['username'] ?: 'Unknown';
                    $profilePhoto = $hasProfilePhoto ? ($row['profile_photo'] ?? null) : null;
                    $rows[] = [
                        ['image' => $profilePhoto, 'text' => $repName, 'icon' => 'ðŸ‘¤'],
                        $repName,
                        $row['invoice_number'],
                        $row['customer_name'] ?: 'â€”',
                        number_format($row['total_amount'])
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            break;
            
        case 'top_customers':
            $sectionTitle = 'Top Customers';
            $headers = ['Image', 'Customer', 'Invoices', 'Total (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                try {
                    $pdo->query("SELECT logo FROM customers LIMIT 1");
                    $hasLogo = true;
                } catch (Exception $e) {
                    $hasLogo = false;
                }
                $logoField = $hasLogo ? ', c.logo' : '';
                $stmt = $pdo->prepare("
                    SELECT c.company_name, COUNT(i.id) as invoice_count, COALESCE(SUM(i.total_amount), 0) as total $logoField
                    FROM customers c
                    JOIN invoices i ON i.customer_id = c.id
                    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
                    GROUP BY c.id ORDER BY total DESC LIMIT 10
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $logo = $hasLogo ? ($row['logo'] ?? null) : null;
                    $rows[] = [
                        ['image' => $logo, 'text' => $row['company_name'], 'icon' => 'ðŸ¢'],
                        $row['company_name'],
                        $row['invoice_count'],
                        number_format($row['total'])
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            break;
            
        case 'top_products':
            $sectionTitle = 'Top Selling Products';
            $topN = (int)($_GET['top_n'] ?? 5);
            $headers = ['Image', 'Product', 'Units Sold', 'Revenue (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                $stmt = $pdo->prepare("
                    SELECT soi.product_id, COALESCE(p.name, soi.description) AS name, p.main_image,
                           SUM(soi.quantity) AS sold, SUM(soi.quantity * soi.unit_price) AS revenue
                    FROM sales_order_items soi
                    JOIN sales_orders so ON so.id = soi.order_id
                    LEFT JOIN products p ON p.id = soi.product_id
                    WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
                    AND DATE(so.created_at) BETWEEN ? AND ?
                    AND soi.product_id IS NOT NULL
                    GROUP BY soi.product_id, p.name, p.main_image, soi.description
                    ORDER BY revenue DESC LIMIT " . (int)$topN . "
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $productName = $row['name'] ?: 'Item';
                    $imagePath = null;
                    if (!empty($row['main_image']) && !empty($row['product_id'])) {
                        $imagePath = 'stock/uploads/products/' . (int)$row['product_id'] . '/medium/' . htmlspecialchars($row['main_image']);
                    }
                    $rows[] = [
                        ['image' => $imagePath, 'text' => $productName, 'icon' => 'ðŸ“¦'],
                        $productName,
                        number_format($row['sold'], 2),
                        number_format($row['revenue'])
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            break;
            
        case 'quotations':
            $sectionTitle = 'Quotations by Status';
            $headers = ['Status', 'Count', 'Total (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                $stmt = $pdo->prepare("
                    SELECT status, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total
                    FROM sales_orders
                    WHERE status IN ('draft', 'quotation')
                    AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY status ORDER BY cnt DESC
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rows[] = [
                        ucfirst($row['status']),
                        $row['cnt'],
                        number_format($row['total'])
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows];
            break;
            
        case 'invoices':
            $sectionTitle = 'Invoices by Status';
            $headers = ['Status', 'Count', 'Total (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                $stmt = $pdo->prepare("
                    SELECT status, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total
                    FROM invoices
                    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
                    GROUP BY status ORDER BY cnt DESC
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rows[] = [
                        ucfirst($row['status']),
                        $row['cnt'],
                        number_format($row['total'])
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows];
            break;
            
        case 'sales_trend':
            $sectionTitle = 'Sales Trend';
            $headers = ['Month', 'Invoices (TZS)', 'Quotations (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                $stmt = $pdo->prepare("
                    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, COALESCE(SUM(total_amount), 0) as total
                    FROM invoices
                    WHERE status != 'cancelled'
                    AND invoice_date BETWEEN ? AND ?
                    GROUP BY month ORDER BY month ASC
                ");
                $stmt->execute([$startDate, $endDate]);
                $invoicesByMonth = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $invoicesByMonth[$row['month']] = (float)$row['total'];
                }
                
                $stmtQ = $pdo->prepare("
                    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(total_amount), 0) as total
                    FROM sales_orders
                    WHERE status IN ('draft', 'quotation')
                    AND created_at BETWEEN ? AND ?
                    GROUP BY month ORDER BY month ASC
                ");
                $stmtQ->execute([$startDate, $endDate]);
                $quotationsByMonth = [];
                while ($row = $stmtQ->fetch(PDO::FETCH_ASSOC)) {
                    $quotationsByMonth[$row['month']] = (float)$row['total'];
                }
                
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                $end->modify('first day of next month');
                $period = new DatePeriod($start, new DateInterval('P1M'), $end);
                foreach ($period as $d) {
                    $month = $d->format('Y-m');
                    $monthLabel = $d->format('M Y');
                    $rows[] = [
                        $monthLabel,
                        number_format($invoicesByMonth[$month] ?? 0),
                        number_format($quotationsByMonth[$month] ?? 0)
                    ];
                }
            }
            $data = ['headers' => $headers, 'rows' => $rows];
            break;
            
        case 'all':
        default:
            $sectionTitle = 'Sales Performance Report';
            // For "all", we'll generate multiple sections
            $data = ['sections' => []];
            
            // Sales by Rep
            $headers = ['Image', 'Rep', 'Invoice', 'Customer', 'Amount (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                try {
                    $pdo->query("SELECT profile_photo FROM users LIMIT 1");
                    $hasProfilePhoto = true;
                } catch (Exception $e) {
                    $hasProfilePhoto = false;
                }
                $photoField = $hasProfilePhoto ? ', u.profile_photo' : '';
                $stmt = $pdo->prepare("
                    SELECT i.id, i.created_by, u.username, u.full_name, i.invoice_number, i.total_amount, c.company_name as customer_name $photoField
                    FROM invoices i
                    LEFT JOIN users u ON i.created_by = u.id
                    LEFT JOIN customers c ON i.customer_id = c.id
                    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
                    ORDER BY i.created_by, i.invoice_date DESC
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $repName = $row['full_name'] ?: $row['username'] ?: 'Unknown';
                    $profilePhoto = $hasProfilePhoto ? ($row['profile_photo'] ?? null) : null;
                    $rows[] = [
                        ['image' => $profilePhoto, 'text' => $repName, 'icon' => 'ðŸ‘¤'],
                        $repName,
                        $row['invoice_number'],
                        $row['customer_name'] ?: 'â€”',
                        number_format($row['total_amount'])
                    ];
                }
            }
            $data['sections'][] = ['title' => 'Sales by Rep', 'headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            
            // Top Customers
            $headers = ['Image', 'Customer', 'Invoices', 'Total (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                try {
                    $pdo->query("SELECT logo FROM customers LIMIT 1");
                    $hasLogo = true;
                } catch (Exception $e) {
                    $hasLogo = false;
                }
                $logoField = $hasLogo ? ', c.logo' : '';
                $stmt = $pdo->prepare("
                    SELECT c.company_name, COUNT(i.id) as invoice_count, COALESCE(SUM(i.total_amount), 0) as total $logoField
                    FROM customers c
                    JOIN invoices i ON i.customer_id = c.id
                    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
                    GROUP BY c.id ORDER BY total DESC LIMIT 10
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $logo = $hasLogo ? ($row['logo'] ?? null) : null;
                    $rows[] = [
                        ['image' => $logo, 'text' => $row['company_name'], 'icon' => 'ðŸ¢'],
                        $row['company_name'],
                        $row['invoice_count'],
                        number_format($row['total'])
                    ];
                }
            }
            $data['sections'][] = ['title' => 'Top Customers', 'headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            
            // Top Products
            $topN = (int)($_GET['top_n'] ?? 5);
            $headers = ['Image', 'Product', 'Units Sold', 'Revenue (TZS)'];
            $rows = [];
            if ($useSalesModule) {
                $stmt = $pdo->prepare("
                    SELECT soi.product_id, COALESCE(p.name, soi.description) AS name, p.main_image,
                           SUM(soi.quantity) AS sold, SUM(soi.quantity * soi.unit_price) AS revenue
                    FROM sales_order_items soi
                    JOIN sales_orders so ON so.id = soi.order_id
                    LEFT JOIN products p ON p.id = soi.product_id
                    WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
                    AND DATE(so.created_at) BETWEEN ? AND ?
                    AND soi.product_id IS NOT NULL
                    GROUP BY soi.product_id, p.name, p.main_image, soi.description
                    ORDER BY revenue DESC LIMIT " . (int)$topN . "
                ");
                $stmt->execute([$startDate, $endDate]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $productName = $row['name'] ?: 'Item';
                    $imagePath = null;
                    if (!empty($row['main_image']) && !empty($row['product_id'])) {
                        $imagePath = 'stock/uploads/products/' . (int)$row['product_id'] . '/medium/' . htmlspecialchars($row['main_image']);
                    }
                    $rows[] = [
                        ['image' => $imagePath, 'text' => $productName, 'icon' => 'ðŸ“¦'],
                        $productName,
                        number_format($row['sold'], 2),
                        number_format($row['revenue'])
                    ];
                }
            }
            $data['sections'][] = ['title' => 'Top Selling Products', 'headers' => $headers, 'rows' => $rows, 'hasImages' => true];
            break;
    }
    
    // Generate filename
    $fnameSafe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $sectionTitle ?: 'sales_report');
    $extensions = [
        'csv' => 'csv',
        'excel' => 'xls',
        'word' => 'doc',
        'pdf' => 'pdf'
    ];
    $ext = $extensions[$exportFormat] ?? 'csv';
    $filename = $fnameSafe . '_' . date('Y-m-d') . '.' . $ext;
    
    // Output based on format
    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
        
        // Helper function to convert row data to CSV format (extract text from image arrays)
        $convertRowForCSV = function($row) {
            $csvRow = [];
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['text'])) {
                    $csvRow[] = $cell['text']; // Use text representation for CSV
                } else {
                    $csvRow[] = $cell;
                }
            }
            return $csvRow;
        };
        
        // Helper function to convert headers (remove Image column for CSV)
        $convertHeadersForCSV = function($headers) {
            return array_filter($headers, function($h) {
                return strtolower($h) !== 'image';
            });
        };
        
        if ($exportSection === 'all') {
            fputcsv($output, ['Sales Performance Report - ' . date('d M Y', strtotime($startDate)) . ' to ' . date('d M Y', strtotime($endDate))]);
            fputcsv($output, []);
            foreach ($data['sections'] as $section) {
                fputcsv($output, ['=== ' . strtoupper($section['title']) . ' ===']);
                fputcsv($output, $convertHeadersForCSV($section['headers']));
                foreach ($section['rows'] as $row) {
                    fputcsv($output, $convertRowForCSV($row));
                }
                fputcsv($output, []);
            }
        } else {
            fputcsv($output, $convertHeadersForCSV($data['headers']));
            foreach ($data['rows'] as $row) {
                fputcsv($output, $convertRowForCSV($row));
            }
        }
        
        fclose($output);
        exit;
    } else {
        // Generate HTML for Excel/Word/PDF
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title><?= htmlspecialchars($sectionTitle ?: 'Sales Report') ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; border-bottom: 2px solid #4361ee; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; table-layout: fixed; }
        th { background-color: #4361ee; color: white; padding: 8px; text-align: left; border: 1px solid #333; font-weight: bold; }
        td { padding: 5px; border: 1px solid #333; vertical-align: middle; word-wrap: break-word; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .report-info { margin-bottom: 20px; color: #666; }
        img { max-width: 40px; max-height: 40px; display: block; margin: 0 auto; }
        colgroup col { width: auto; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
            table { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($sectionTitle ?: 'Sales Performance Report') ?></h1>
    <div class="report-info">
        <p><strong>Period:</strong> <?= date('d M Y', strtotime($startDate)) ?> to <?= date('d M Y', strtotime($endDate)) ?></p>
        <p><strong>Generated:</strong> <?= date('d M Y H:i:s') ?></p>
    </div>
        <?php
        if ($exportSection === 'all') {
            foreach ($data['sections'] as $section) {
                echo generateHTMLTable($section['headers'], $section['rows'], $section['title'], $section['hasImages'] ?? false, $exportFormat);
            }
        } else {
            echo generateHTMLTable($data['headers'], $data['rows'], '', $data['hasImages'] ?? false, $exportFormat);
        }
        ?>
</body>
</html>
        <?php
        $html = ob_get_clean();
        
        switch ($exportFormat) {
            case 'excel':
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                // Excel-compatible HTML - remove problematic elements and ensure proper structure
                $excelHtml = $html;
                // Remove colgroup for Excel compatibility (Excel doesn't always handle it well)
                $excelHtml = preg_replace('/<colgroup>.*?<\/colgroup>/s', '', $excelHtml);
                // Ensure all img tags have proper attributes
                $excelHtml = preg_replace('/<img([^>]*)\s*>/i', '<img$1 />', $excelHtml);
                // Add Excel-specific meta
                $excelHtml = str_replace('<!DOCTYPE html>', '', $excelHtml);
                $excelHtml = '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Sheet1</x:Name></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->' . $excelHtml;
                echo $excelHtml;
                break;
            case 'word':
                header('Content-Type: application/msword');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo $html;
                break;
            case 'pdf':
                // Generate actual PDF file - use mPDF or TCPDF if available, otherwise create PDF-ready HTML
                $pdfGenerated = false;
                
                // Try mPDF first (commonly used)
                if (file_exists(__DIR__ . '/../vendor/mpdf/mpdf/src/Mpdf.php')) {
                    require_once(__DIR__ . '/../vendor/mpdf/mpdf/src/Mpdf.php');
                    try {
                        $mpdf = new \Mpdf\Mpdf([
                            'mode' => 'utf-8',
                            'format' => 'A4',
                            'margin_left' => 15,
                            'margin_right' => 15,
                            'margin_top' => 15,
                            'margin_bottom' => 15,
                        ]);
                        $mpdf->SetTitle($sectionTitle ?: 'Sales Performance Report');
                        $mpdf->WriteHTML($html);
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: attachment; filename="' . $filename . '"');
                        $mpdf->Output($filename, 'D');
                        exit;
                    } catch (Exception $e) {
                        // Continue to next method
                    }
                }
                
                // Try TCPDF
                if (file_exists(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
                    require_once(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php');
                    try {
                        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                        $pdf->SetCreator('Ultimate ERP');
                        $pdf->SetAuthor('Sales Report System');
                        $pdf->SetTitle($sectionTitle ?: 'Sales Performance Report');
                        $pdf->setPrintHeader(false);
                        $pdf->setPrintFooter(false);
                        $pdf->SetMargins(15, 15, 15);
                        $pdf->SetAutoPageBreak(TRUE, 15);
                        $pdf->AddPage();
                        
                        // Clean HTML for TCPDF
                        $cleanHtml = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
                        $pdf->writeHTML($cleanHtml, true, false, true, false, '');
                        
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: attachment; filename="' . $filename . '"');
                        $pdf->Output($filename, 'D');
                        exit;
                    } catch (Exception $e) {
                        // Continue to fallback
                    }
                }
                
                // Fallback: Generate HTML optimized for PDF conversion with embedded JavaScript
                // This will use browser's print-to-PDF functionality via JavaScript
                $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
                $html = str_replace('</head>', '
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        window.onload = function() {
            setTimeout(function() {
                html2canvas(document.body, {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    backgroundColor: "#ffffff"
                }).then(function(canvas) {
                    const imgData = canvas.toDataURL("image/png");
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF("p", "mm", "a4");
                    const imgWidth = 210;
                    const pageHeight = 295;
                    const imgHeight = (canvas.height * imgWidth) / canvas.width;
                    let heightLeft = imgHeight;
                    let position = 0;
                    
                    pdf.addImage(imgData, "PNG", 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                    
                    while (heightLeft >= 0) {
                        position = heightLeft - imgHeight;
                        pdf.addPage();
                        pdf.addImage(imgData, "PNG", 0, position, imgWidth, imgHeight);
                        heightLeft -= pageHeight;
                    }
                    
                    pdf.save("' . $filename . '");
                }).catch(function(error) {
                    console.error("PDF generation error:", error);
                    alert("PDF generation failed. Please use browser Print to PDF instead.");
                });
            }, 500);
        };
    </script>
</head>', $html);
                
                header('Content-Type: text/html; charset=utf-8');
                echo $html;
                break;
            default:
                header('Content-Type: text/html; charset=utf-8');
                echo $html;
        }
        exit;
    }
}

// Handle suggestion submit (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_suggestion'])) {
    $part = $_POST['suggestion_part'] ?? '';
    $allowedParts = ['invoice', 'quotations', 'customers', 'sales_orders', 'targets'];
    if (in_array($part, $allowedParts, true)) {
        $message = trim($_POST['suggestion_message'] ?? '');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['suggestion_feedback'] = 'Thank you. Your suggestion for ' . ucfirst(str_replace('_', ' ', $part)) . ' has been submitted.';
    }
    $params = [
        'start_date' => $_POST['start_date'] ?? date('Y-m-01', strtotime('-5 months')),
        'end_date' => $_POST['end_date'] ?? date('Y-m-d'),
        'top_n' => $_POST['top_n'] ?? 5,
        'target_month' => $_POST['target_month'] ?? date('Y-m')
    ];
    header('Location: sales_report.php?' . http_build_query($params));
    exit;
}

// Ensure we have sales module tables; fallback to ERP if needed
$useSalesModule = false;
try {
    $pdo->query("SELECT 1 FROM invoices LIMIT 1");
    $useSalesModule = true;
} catch (Exception $e) {}
if ($useSalesModule && file_exists(__DIR__ . '/../modules/sales/functions.php')) {
    require_once __DIR__ . '/../modules/sales/functions.php';
}

// --- 1. Sales Trend: Invoices + Quotations (monthly in selected date range) ---
$salesTrend = [];
$quotationsByMonth = [];
if ($useSalesModule) {
    // Invoices: monthly revenue
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, COALESCE(SUM(total_amount), 0) as total
        FROM invoices
        WHERE status != 'cancelled'
        AND invoice_date BETWEEN ? AND ?
        GROUP BY month ORDER BY month ASC
    ");
    $stmt->execute([$startDate, $endDate]);
    $byMonth = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byMonth[$r['month']] = (float) $r['total'];
    }
    // Quotations: monthly total value (draft + quotation status)
    try {
        $stmtQ = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COALESCE(SUM(total_amount), 0) as total
            FROM sales_orders
            WHERE status IN ('draft', 'quotation')
            AND created_at BETWEEN ? AND ?
            GROUP BY month ORDER BY month ASC
        ");
        $stmtQ->execute([$startDate, $endDate]);
        foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $quotationsByMonth[$r['month']] = (float) $r['total'];
        }
    } catch (Exception $e) {}
    // Build all months in selected range so every month has a point (0 if none)
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('first day of next month');
    $period = new DatePeriod($start, new DateInterval('P1M'), $end);
    foreach ($period as $d) {
        $month = $d->format('Y-m');
        $salesTrend[] = [
            'month' => $month,
            'total' => $byMonth[$month] ?? 0,
            'quotations' => $quotationsByMonth[$month] ?? 0
        ];
    }
} else {
    try {
        require_once '../erp/includes/dashboard_stats.php';
        $dashboard = new DashboardStats($pdo);
        $salesTrend = $dashboard->getSalesTrend();
        foreach ($salesTrend as &$row) {
            $row['quotations'] = 0;
        }
        unset($row);
    } catch (Exception $e) {}
}
// Format month as readable date label below each point (e.g. "Feb 2025")
$chartLabels = json_encode(array_map(function ($row) {
    $t = strtotime($row['month'] . '-01');
    return $t ? date('M Y', $t) : $row['month'];
}, $salesTrend));
$chartData = json_encode(array_map('floatval', array_column($salesTrend, 'total')));
$chartDataQuotations = json_encode(array_map('floatval', array_map(function ($row) {
    return $row['quotations'] ?? 0;
}, $salesTrend)));

// --- 2. Sales targets (overall yearly + selected month) ---
$currentYear = date('Y');
$yearlyTarget = 0;
$yearlySales = 0;
$monthlyTarget = 0;
$monthlySales = 0;
if ($useSalesModule && function_exists('getGlobalYearlyTarget')) {
    $yearlyTarget = (float) getGlobalYearlyTarget($currentYear);
    $yearlySales = (float) getGlobalYearlySales($currentYear);
    $monthlySales = (float) getGlobalSalesTotal($targetMonth);
    try {
        $pdo->query("SELECT 1 FROM sales_targets LIMIT 1");
        $st = $pdo->prepare("SELECT COALESCE(SUM(target_amount), 0) FROM sales_targets WHERE period = ? AND user_id != 0");
        $st->execute([$targetMonth]);
        $monthlyTarget = (float) $st->fetchColumn();
    } catch (Exception $e) {}
}
$yearlyRemaining = max(0, $yearlyTarget - $yearlySales);
$yearlyPct = $yearlyTarget > 0 ? min(100, ($yearlySales / $yearlyTarget) * 100) : 0;
$monthlyPct = $monthlyTarget > 0 ? min(100, ($monthlySales / $monthlyTarget) * 100) : 0;

// --- 3. Top Selling Products (sales module: sales_order_items) ---
$top_products = [];
if ($useSalesModule) {
    try {
        // Optimized query with proper indexing hints
        $stmt = $pdo->prepare("
            SELECT soi.product_id, COALESCE(p.name, soi.description) AS name, p.main_image,
                   SUM(soi.quantity) AS sold, SUM(soi.quantity * soi.unit_price) AS revenue
            FROM sales_order_items soi
            INNER JOIN sales_orders so ON so.id = soi.order_id
            LEFT JOIN products p ON p.id = soi.product_id
            WHERE so.status IN ('confirmed','invoiced','shipped','paid','delivered')
            AND DATE(so.created_at) BETWEEN ? AND ?
            AND soi.product_id IS NOT NULL
            GROUP BY soi.product_id, p.name, p.main_image, soi.description
            ORDER BY revenue DESC
            LIMIT " . (int)$topProductsLimit . "
        ");
        $stmt->execute([$startDate, $endDate]);
        $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Top products query error: " . $e->getMessage());
    }
}
if (empty($top_products) && !$useSalesModule) {
    try {
        $limit = (int)$topProductsLimit;
        $stmt = $pdo->query("SELECT p.name, p.main_image, SUM(soi.quantity) as sold, SUM(soi.line_total) as revenue 
                             FROM sales_order_items soi JOIN products p ON soi.product_id = p.id 
                             JOIN sales_orders so ON soi.order_id = so.id WHERE so.status != 'cancelled'
                             GROUP BY p.id, p.name, p.main_image ORDER BY revenue DESC LIMIT $limit");
        $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// --- 4. Quotations by Status (sales module: draft & quotation) ---
$quotations_by_status = [];
if ($useSalesModule) {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total
        FROM sales_orders
        WHERE status IN ('draft', 'quotation')
        AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY status ORDER BY cnt DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $quotations_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 5. Invoices by Status (sales module) ---
$invoices_by_status = [];
if ($useSalesModule) {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total
        FROM invoices
        WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
        GROUP BY status ORDER BY cnt DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $invoices_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 6. Top Customers by Revenue (sales module) ---
$top_customers = [];
if ($useSalesModule) {
    // Try to get logo field if it exists
    try {
        $pdo->query("SELECT logo FROM customers LIMIT 1");
        $hasLogo = true;
    } catch (Exception $e) {
        $hasLogo = false;
    }
    $logoField = $hasLogo ? ', c.logo' : '';
    $stmt = $pdo->prepare("
        SELECT c.company_name, c.contact_person, COUNT(i.id) as invoice_count, COALESCE(SUM(i.total_amount), 0) as total $logoField
        FROM customers c
        JOIN invoices i ON i.customer_id = c.id
        WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
        GROUP BY c.id ORDER BY total DESC LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate]);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 7. Sales by Sales Rep with invoices and customers (sales module) ---
$sales_by_rep = [];
if ($useSalesModule) {
    // Try to get profile_photo field if it exists
    try {
        $pdo->query("SELECT profile_photo FROM users LIMIT 1");
        $hasProfilePhoto = true;
    } catch (Exception $e) {
        $hasProfilePhoto = false;
    }
    $photoField = $hasProfilePhoto ? ', u.profile_photo' : '';
    $stmt = $pdo->prepare("
        SELECT i.created_by, u.username, u.full_name, COUNT(i.id) as invoice_count, COALESCE(SUM(i.total_amount), 0) as total $photoField
        FROM invoices i
        LEFT JOIN users u ON i.created_by = u.id
        WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
        GROUP BY i.created_by, u.username, u.full_name" . ($hasProfilePhoto ? ', u.profile_photo' : '') . " ORDER BY total DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $sales_by_rep = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Fetch invoice details (invoice ID, invoice number + customer name) per rep
    $stmt2 = $pdo->prepare("
        SELECT i.id, i.created_by, i.invoice_number, i.total_amount, c.company_name as customer_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
        ORDER BY i.created_by, i.invoice_date DESC
    ");
    $stmt2->execute([$startDate, $endDate]);
    $invoices_by_rep = [];
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $uid = $row['created_by'] ?? 'null';
        if (!isset($invoices_by_rep[$uid])) $invoices_by_rep[$uid] = [];
        $invoices_by_rep[$uid][] = [
            'invoice_id' => (int)($row['id'] ?? 0),
            'invoice_number' => $row['invoice_number'],
            'customer_name' => $row['customer_name'] ?: 'â€”',
            'total_amount' => (float)($row['total_amount'] ?? 0)
        ];
    }
    foreach ($sales_by_rep as &$r) {
        $uid = $r['created_by'] ?? 'null';
        $r['invoices'] = $invoices_by_rep[$uid] ?? [];
    }
    unset($r);
}

// --- 8. Overdue Invoices (sales module) ---
$overdue_count = 0;
$overdue_total = 0;
if ($useSalesModule) {
    $stmt = $pdo->query("SELECT COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE status = 'overdue'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $overdue_count = (int)($row['c'] ?? 0);
    $overdue_total = (float)($row['total'] ?? 0);
}

// --- 9. Commission Summary (if table exists) ---
$commission_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(commission_amount), 0) as total FROM sales_commissions
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $commission_total = (float) $stmt->fetchColumn();
} catch (Exception $e) {}

// --- 10. KPIs ---
$todaySalesCount = 0;
$periodTotal = 0;
if ($useSalesModule) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status != 'cancelled' AND DATE(invoice_date) = CURDATE()");
    $todaySalesCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    $periodTotal = (float) $stmt->fetchColumn();
} else {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status != 'cancelled' AND invoice_date = CURDATE()");
        $todaySalesCount = (int) $stmt->fetchColumn();
    } catch (Exception $e) {}
}

// --- Company Sales Performance Metrics ---
$salesPerformance = [
    'yearly_target_pct' => 0,
    'monthly_target_pct' => 0,
    'conversion_rate' => 0,
    'revenue_per_rep' => 0,
    'sales_status' => 'Good',
    'total_quotations' => 0,
    'total_invoices' => 0
];
if ($useSalesModule) {
    // Yearly target achievement
    if ($yearlyTarget > 0) {
        $salesPerformance['yearly_target_pct'] = $yearlyPct;
    }
    // Monthly target achievement
    if ($monthlyTarget > 0) {
        $salesPerformance['monthly_target_pct'] = $monthlyPct;
    }
    // Conversion rate: quotations to invoices (if we have both)
    $totalQuotations = 0;
    foreach ($quotations_by_status as $q) { $totalQuotations += (int)$q['cnt']; }
    $salesPerformance['total_quotations'] = $totalQuotations;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate]);
        $salesPerformance['total_invoices'] = (int) $stmt->fetchColumn();
    } catch (Exception $e) {}
    if ($totalQuotations > 0 && $salesPerformance['total_invoices'] > 0) {
        // Simple conversion: invoices / (quotations + invoices) * 100
        $salesPerformance['conversion_rate'] = ($salesPerformance['total_invoices'] / ($totalQuotations + $salesPerformance['total_invoices'])) * 100;
    }
    // Revenue per rep
    $activeReps = count($sales_by_rep);
    if ($activeReps > 0 && $periodTotal > 0) {
        $salesPerformance['revenue_per_rep'] = $periodTotal / $activeReps;
    }
    // Sales status: Good / Needs Improvement / Excellent
    if ($yearlyTarget > 0) {
        if ($yearlyPct >= 100) {
            $salesPerformance['sales_status'] = 'Excellent';
        } elseif ($yearlyPct >= 80) {
            $salesPerformance['sales_status'] = 'Good';
        } else {
            $salesPerformance['sales_status'] = 'Needs Improvement';
        }
    } elseif ($monthlyTarget > 0) {
        if ($monthlyPct >= 100) {
            $salesPerformance['sales_status'] = 'Excellent';
        } elseif ($monthlyPct >= 80) {
            $salesPerformance['sales_status'] = 'Good';
        } else {
            $salesPerformance['sales_status'] = 'Needs Improvement';
        }
    }
}

// AI-style suggestions: actionable advice (get customers, maintain customers, increase sales, etc.)
require_once __DIR__ . '/includes/suggestion_library.php';

$metrics = [
    'num_customers' => count($top_customers),
    'num_products' => count($top_products),
    'period_revenue' => $periodTotal,
    'yearly_target' => $yearlyTarget,
    'yearly_pct' => $yearlyPct,
    'monthly_target' => $monthlyTarget,
    'monthly_pct' => $monthlyPct,
    'num_quotes' => $salesPerformance['total_quotations'],
    'overdue_count' => $overdue_count,
    'overdue_total' => $overdue_total,
    'use_sales_module' => $useSalesModule
];

$suggestions = getSalesSuggestions($metrics);

// System looks at the report and tells the user (report summary / narrative)
$reportNarrative = [];
$reportNarrative[] = 'This report covers <strong>' . date('d M Y', strtotime($startDate)) . '</strong> to <strong>' . date('d M Y', strtotime($endDate)) . '</strong>.';
$reportNarrative[] = 'Period revenue (invoices) is <strong>TZS ' . number_format($periodTotal) . '</strong>.';
if ($todaySalesCount > 0) {
    $reportNarrative[] = 'You have <strong>' . $todaySalesCount . '</strong> invoice(s) created today.';
}
if ($overdue_count > 0) {
    $reportNarrative[] = 'There are <strong>' . $overdue_count . '</strong> overdue invoice(s) worth TZS ' . number_format($overdue_total) . ' â€” follow these up.';
} else {
    $reportNarrative[] = 'No overdue invoices.';
}
if ($yearlyTarget > 0) {
    $reportNarrative[] = 'Yearly target: <strong>' . number_format($yearlyPct, 1) . '%</strong> achieved (TZS ' . number_format($yearlySales) . ' of ' . number_format($yearlyTarget) . '). ' . number_format($yearlyRemaining) . ' TZS remaining.';
}
if ($monthlyTarget > 0) {
    $reportNarrative[] = 'Monthly target (' . date('F Y', strtotime($targetMonth . '-01')) . '): <strong>' . number_format($monthlyPct, 1) . '%</strong> (TZS ' . number_format($monthlySales) . ' of ' . number_format($monthlyTarget) . ').';
}
if ($salesPerformance['total_quotations'] > 0) {
    $reportNarrative[] = 'You have <strong>' . $salesPerformance['total_quotations'] . '</strong> quotation(s) in this period â€” consider converting them to orders or invoices.';
}
if (count($top_customers) > 0) {
    $reportNarrative[] = 'Top <strong>' . count($top_customers) . '</strong> customers and top products by revenue are shown in the sidebar.';
}
if (count($sales_by_rep) > 0) {
    $reportNarrative[] = 'Sales by rep: <strong>' . count($sales_by_rep) . '</strong> rep(s) with invoiced sales in this period.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report | Ultimate ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #4361ee; --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #2b2d42; --text-muted: #8d99ae; --radius-lg: 16px; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); }
        h1, h2, h3, h4, h5 { font-family: 'Poppins', sans-serif; }
        .wrapper { padding: 2rem; max-width: 1600px; margin: 0 auto; }
        .chart-panel { background: var(--bg-card); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.06); }
        .table-custom th { font-weight: 600; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; }
        .table-custom td { vertical-align: middle; font-size: 0.9rem; }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .back-link { text-decoration: none; color: var(--text-muted); font-weight: 500; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; transition: color 0.2s; }
        .back-link:hover { color: var(--primary); }
        .stat-card { border-radius: 12px; padding: 1rem; }
        .report-section { }
        /* KPI cards: equal height, clear separation */
        .kpi-card { height: 100%; border-radius: var(--radius-lg); padding: 1.25rem 1.5rem; border: 1px solid rgba(0,0,0,0.08); transition: box-shadow 0.2s; }
        .kpi-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .kpi-card.bg-primary { border: none; }
        /* Main content columns */
        .report-main .chart-panel { margin-bottom: 1.5rem; }
        .report-main .chart-panel:last-child { margin-bottom: 0; }
        .report-sidebar .chart-panel { margin-bottom: 1.5rem; }
        .report-sidebar .chart-panel:last-child { margin-bottom: 0; }
        .report-header-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .report-header-actions .form-control-sm { width: auto; min-width: 130px; }
        .report-header-actions .btn { white-space: nowrap; }
        .suggestions-list li:last-child { border-bottom: none !important; }
        .sales-by-rep-table { table-layout: fixed; width: 100%; max-width: 100%; font-size: 0.85rem; }
        .sales-by-rep-table th, .sales-by-rep-table td { overflow: hidden; }
        .sales-by-rep-table .col-rep { width: 20%; max-width: 200px; }
        .sales-by-rep-table .col-invoice { width: 15%; }
        .sales-by-rep-table .col-customer { width: 40%; word-wrap: break-word; overflow-wrap: break-word; }
        .sales-by-rep-table .col-amount { width: 25%; white-space: nowrap; }
        .sales-by-rep-table .col-invoice a { color: #0d6efd; transition: color 0.2s ease; }
        .sales-by-rep-table .col-invoice a:hover { color: #0a58ca; text-decoration: underline; }
        .sales-by-rep-table .col-invoice a i { transition: opacity 0.2s ease; }
        .sales-by-rep-table .col-invoice a:hover i { opacity: 1; }
        .sales-perf-row { display: flex; flex-wrap: nowrap; gap: 0.75rem; overflow-x: auto; padding-bottom: 4px; }
        .sales-perf-row .sales-perf-item { flex: 1 1 0; min-width: 120px; text-align: center; padding: 0.75rem 0.5rem; border: 1px solid rgba(0,0,0,0.08); border-radius: 12px; }
        .sales-perf-row .sales-perf-item .perf-value { font-size: 1.1rem; font-weight: 700; }
        .sales-perf-row .sales-perf-item .perf-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 2px; }
        .sales-perf-row .sales-perf-item .perf-sub { font-size: 0.7rem; color: var(--text-muted); }
        .top-customers-card { padding: 0.75rem 1rem !important; }
        .top-customers-card h5 { font-size: 0.9rem; margin-bottom: 0.5rem !important; }
        .top-customers-card .table { font-size: 0.75rem; }
        .top-customers-card .table th { font-size: 0.7rem; padding: 0.4rem 0.5rem; }
        .top-customers-card .table td { padding: 0.4rem 0.5rem; font-size: 0.75rem; }
        .top-customers-card .table .fw-medium { font-size: 0.75rem; }
        .top-customers-card .text-warning { font-size: 0.7rem !important; }
        .top-products-card { padding: 0.75rem 1rem !important; }
        .top-products-card h5 { font-size: 0.9rem; margin-bottom: 0.5rem !important; }
        .top-products-card p { font-size: 0.7rem !important; }
        .top-products-card .form-select-sm { font-size: 0.7rem !important; }
        .top-products-card label { font-size: 0.7rem !important; }
        .top-products-card .list-group-item { padding: 0.5rem 0 !important; font-size: 0.75rem; }
        .top-products-card .list-group-item .fw-bold { font-size: 0.75rem !important; }
        .top-products-card .list-group-item .small { font-size: 0.65rem !important; }
        .top-products-card .list-group-item .fs-6 { font-size: 0.75rem !important; }
        .top-products-card .rounded-circle { width: 32px !important; height: 32px !important; }
        .top-products-card .rounded-circle span { font-size: 0.7rem !important; }
        /* Loading state */
        .export-loading { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; display: none; align-items: center; justify-content: center; flex-direction: column; }
        .export-loading.show { display: flex; }
        .export-loading .spinner { color: white; font-size: 2rem; }
        .export-loading .spinner-text { color: white; margin-top: 1rem; font-size: 1.1rem; }
        /* Accessibility improvements */
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        button:focus, a:focus, select:focus, input:focus { outline: 2px solid #4361ee; outline-offset: 2px; }
        /* Keyboard navigation */
        .skip-link { position: absolute; top: -40px; left: 0; background: #4361ee; color: white; padding: 8px; text-decoration: none; z-index: 100; }
        .skip-link:focus { top: 0; }
        /* Print styles */
        @media print {
            .no-print { display: none !important; }
            .chart-panel { page-break-inside: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            .btn, button { display: none !important; }
        }
        /* Performance: reduce animations on low-end devices */
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="wrapper animate-fade-in" role="main" aria-label="Sales Performance Report" id="main-content">

    <a href="advanced_insights.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Hub</a>

    <?php if (!empty($_SESSION['suggestion_feedback'])): ?>
    <div class="alert alert-success alert-dismissible fade show small py-2" role="alert" aria-live="polite">
        <i class="fas fa-check-circle me-2" aria-hidden="true"></i><?= htmlspecialchars($_SESSION['suggestion_feedback']) ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close alert"></button>
    </div>
    <?php unset($_SESSION['suggestion_feedback']); endif; ?>
    
    <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show small py-2" role="alert" aria-live="assertive">
        <i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i><?= htmlspecialchars($_GET['error']) ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close alert"></button>
    </div>
    <?php endif; ?>

    <header class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4" role="banner">
        <div>
            <h1 class="mb-1" style="font-size: 1.75rem;">Sales Performance Report</h1>
            <p class="text-muted small m-0">Revenue, orders, invoices, customers, and sales rep performance.</p>
        </div>
        <div class="report-header-actions">
            <form method="get" class="d-flex align-items-center flex-wrap gap-2">
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-control form-control-sm" title="Start date">
                <span class="text-muted small">to</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-control form-control-sm" title="End date">
                <input type="hidden" name="top_n" value="<?= (int)$topProductsLimit ?>">
                <input type="hidden" name="target_month" value="<?= htmlspecialchars($targetMonth) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm" aria-label="Apply date filter"><i class="fas fa-filter me-1" aria-hidden="true"></i>Apply</button>
            </form>
            <span class="vr d-none d-md-inline align-self-stretch"></span>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-light btn-sm border" onclick="window.print()" title="Print" aria-label="Print report"><i class="fas fa-print me-1" aria-hidden="true"></i>Print</button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#exportModal" title="Export Report" aria-label="Export report in various formats" accesskey="e"><i class="fas fa-download me-1" aria-hidden="true"></i>Export CSV</button>
            </div>
        </div>
    </header>

    <!-- KPI summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kpi-card bg-primary text-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;"><i class="fas fa-receipt fa-lg"></i></div>
                    <div class="min-w-0">
                        <div class="opacity-75 small">Invoices Today</div>
                        <div class="fs-4 fw-bold"><?= $todaySalesCount ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;"><i class="fas fa-coins text-primary"></i></div>
                    <div class="min-w-0">
                        <div class="text-muted small">Period Total (TZS)</div>
                        <div class="fs-5 fw-bold text-break"><?= number_format($periodTotal) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;"><i class="fas fa-exclamation-triangle text-danger"></i></div>
                    <div class="min-w-0">
                        <div class="text-muted small">Overdue Invoices</div>
                        <div class="fs-5 fw-bold"><?= $overdue_count ?> <small class="text-muted fw-normal">(TZS <?= number_format($overdue_total) ?>)</small></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card bg-white">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;"><i class="fas fa-hand-holding-usd text-success"></i></div>
                    <div class="min-w-0">
                        <div class="text-muted small">Commission (Period)</div>
                        <div class="fs-5 fw-bold text-break">TZS <?= number_format($commission_total) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Company Sales Performance Card -->
    <div class="chart-panel mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Sales Performance</h5>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#aiSuggestionsModal"><i class="fas fa-lightbulb me-1"></i>Suggestions</button>
        </div>
        <div class="sales-perf-row">
            <?php if ($yearlyTarget > 0): ?>
            <div class="sales-perf-item">
                <div class="perf-label">Yearly Target</div>
                <div class="perf-value <?= $yearlyPct >= 100 ? 'text-success' : ($yearlyPct >= 80 ? 'text-primary' : 'text-warning') ?>"><?= number_format($salesPerformance['yearly_target_pct'], 1) ?>%</div>
                <div class="perf-sub"><?= number_format($yearlySales) ?> / <?= number_format($yearlyTarget) ?></div>
                <div class="progress mt-1" style="height: 3px;"><div class="progress-bar <?= $yearlyPct >= 100 ? 'bg-success' : ($yearlyPct >= 80 ? 'bg-primary' : 'bg-warning') ?>" style="width: <?= min(100, $yearlyPct) ?>%"></div></div>
            </div>
            <?php endif; ?>
            <?php if ($monthlyTarget > 0): ?>
            <div class="sales-perf-item">
                <div class="perf-label">Monthly Target</div>
                <div class="perf-value <?= $monthlyPct >= 100 ? 'text-success' : ($monthlyPct >= 80 ? 'text-primary' : 'text-warning') ?>"><?= number_format($salesPerformance['monthly_target_pct'], 1) ?>%</div>
                <div class="perf-sub"><?= date('M Y', strtotime($targetMonth . '-01')) ?></div>
                <div class="progress mt-1" style="height: 3px;"><div class="progress-bar <?= $monthlyPct >= 100 ? 'bg-success' : ($monthlyPct >= 80 ? 'bg-primary' : 'bg-warning') ?>" style="width: <?= min(100, $monthlyPct) ?>%"></div></div>
            </div>
            <?php endif; ?>
            <div class="sales-perf-item">
                <div class="perf-label">Period Revenue</div>
                <div class="perf-value text-success"><?= number_format($periodTotal) ?></div>
                <div class="perf-sub"><?= number_format($salesPerformance['total_invoices']) ?> inv</div>
            </div>
            <?php if ($salesPerformance['revenue_per_rep'] > 0): ?>
            <div class="sales-perf-item">
                <div class="perf-label">Revenue/Rep</div>
                <div class="perf-value text-info"><?= number_format($salesPerformance['revenue_per_rep']) ?></div>
                <div class="perf-sub">TZS avg</div>
            </div>
            <?php endif; ?>
            <?php if ($salesPerformance['conversion_rate'] > 0): ?>
            <div class="sales-perf-item">
                <div class="perf-label">Conversion</div>
                <div class="perf-value text-primary"><?= number_format($salesPerformance['conversion_rate'], 1) ?>%</div>
                <div class="perf-sub">Quote â†’ Inv</div>
            </div>
            <?php endif; ?>
            <div class="sales-perf-item">
                <div class="perf-label">Sales Status</div>
                <div class="perf-value <?= $salesPerformance['sales_status'] === 'Excellent' ? 'text-success' : ($salesPerformance['sales_status'] === 'Good' ? 'text-primary' : 'text-warning') ?>"><?= htmlspecialchars($salesPerformance['sales_status']) ?></div>
                <div class="perf-sub">Overall</div>
            </div>
        </div>
        <?php if ($salesPerformance['total_quotations'] > 0): ?>
        <div class="mt-3 pt-3 border-top">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <span class="text-muted small">Pending Quotations:</span>
                    <span class="fw-bold ms-2"><?= number_format($salesPerformance['total_quotations']) ?></span>
                    <span class="text-muted small ms-2">(Conversion opportunity)</span>
                </div>
                <a href="../modules/sales/orders/index.php" class="btn btn-sm btn-outline-primary">View Quotations</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- Left column: chart -->
        <div class="col-lg-8 report-main">
            <div class="chart-panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
                    <div>
                        <h5 class="mb-0"><i class="fas fa-chart-area me-2 text-primary"></i>Sales Trend</h5>
                        <span class="text-muted small">Invoices &amp; quotations by month</span>
                    </div>
                    <span class="badge bg-primary bg-opacity-10 text-primary">Live Data</span>
                </div>
                <div style="height: 320px;"><canvas id="mainTrendChart"></canvas></div>
            </div>

            <!-- Quotations & Invoices Status Row -->
            <div class="row mb-4">
                <?php if (!empty($quotations_by_status)): ?>
                <div class="col-md-6">
                    <div class="chart-panel h-100">
                        <h5 class="mb-3"><i class="fas fa-file-signature me-2"></i>Quotations by Status</h5>
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead class="bg-light"><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Total (TZS)</th></tr></thead>
                                <tbody>
                                    <?php foreach ($quotations_by_status as $r): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                                        <td class="text-end"><?= (int)$r['cnt'] ?></td>
                                        <td class="text-end fw-bold"><?= number_format($r['total']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($invoices_by_status)): ?>
                <div class="col-md-6">
                    <div class="chart-panel h-100">
                        <h5 class="mb-3"><i class="fas fa-file-invoice-dollar me-2"></i>Invoices by Status</h5>
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead class="bg-light"><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Total</th></tr></thead>
                                <tbody>
                                    <?php foreach ($invoices_by_status as $r): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                                        <td class="text-end"><?= (int)$r['cnt'] ?></td>
                                        <td class="text-end fw-bold"><?= number_format($r['total']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sales target cards -->
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="chart-panel h-100">
                        <h5 class="mb-3"><i class="fas fa-bullseye me-2 text-primary"></i>Overall Sales Target</h5>
                        <p class="text-muted small mb-3">Company target for <?= $currentYear ?> (year to date)</p>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="text-muted small">Target (TZS)</span>
                            <span class="fw-bold"><?= number_format($yearlyTarget) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="text-muted small">Achieved (TZS)</span>
                            <span class="fw-bold text-success"><?= number_format($yearlySales) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline mb-3">
                            <span class="text-muted small">Remaining (TZS)</span>
                            <span class="fw-bold"><?= number_format($yearlyRemaining) ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= round($yearlyPct) ?>%"></div>
                        </div>
                        <div class="small text-muted mt-2 text-end"><?= number_format($yearlyPct, 1) ?>% of target</div>
                        <?php if ($yearlyTarget <= 0): ?>
                        <p class="text-muted small mt-2 mb-0">Set company yearly target in <a href="../modules/sales/admin/targets.php">Sales â†’ Admin â†’ Targets</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6" id="monthly-target-card">
                    <div class="chart-panel h-100">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <h5 class="mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Monthly Sales Target</h5>
                            <form method="get" class="d-flex align-items-center gap-2 flex-wrap" id="target-month-form">
                                <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                                <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                                <input type="hidden" name="top_n" value="<?= (int)$topProductsLimit ?>">
                                <input type="hidden" name="target_month" id="target_month_value" value="<?= htmlspecialchars($targetMonth) ?>">
                                <label for="target_month_select" class="text-muted small mb-0">Month</label>
                                <select id="target_month_select" class="form-select form-select-sm" style="width: auto;" onchange="updateMonthlyTarget(this.form);">
                                    <?php for ($m = 1; $m <= 12; $m++): $v = sprintf('%02d', $m); ?>
                                    <option value="<?= $v ?>"<?= $v === substr($targetMonth, 5, 2) ? ' selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <label for="target_year_select" class="text-muted small mb-0">Year</label>
                                <select id="target_year_select" class="form-select form-select-sm" style="width: auto;" onchange="updateMonthlyTarget(this.form);">
                                    <?php for ($y = date('Y') + 1; $y >= date('Y') - 10; $y--): ?>
                                    <option value="<?= $y ?>"<?= $y === (int)substr($targetMonth, 0, 4) ? ' selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </form>
                        </div>
                        <p class="text-muted small mb-3"><?= date('F Y', strtotime($targetMonth . '-01')) ?></p>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="text-muted small">Target (TZS)</span>
                            <span class="fw-bold"><?= number_format($monthlyTarget) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="text-muted small">Achieved (TZS)</span>
                            <span class="fw-bold text-success"><?= number_format($monthlySales) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline mb-3">
                            <span class="text-muted small">Remaining (TZS)</span>
                            <span class="fw-bold"><?= number_format(max(0, $monthlyTarget - $monthlySales)) ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= round($monthlyPct) ?>%"></div>
                        </div>
                        <div class="small text-muted mt-2 text-end"><?= number_format($monthlyPct, 1) ?>% of target</div>
                        <?php if ($monthlyTarget <= 0): ?>
                        <p class="text-muted small mt-2 mb-0">Set rep monthly targets in <a href="../modules/sales/admin/targets.php">Sales â†’ Admin â†’ Targets</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- What the report shows (hidden) -->
            <div class="chart-panel report-section mt-3 d-none">
                <h5 class="mb-2"><i class="fas fa-chart-pie me-2 text-primary"></i>What this report shows</h5>
                <p class="text-muted small mb-3">The system has looked at your report and summarises:</p>
                <ul class="list-unstyled mb-0 report-narrative">
                    <?php foreach ($reportNarrative as $line): ?>
                    <li class="d-flex align-items-start gap-2 py-1 small">
                        <i class="fas fa-angle-right text-primary mt-1" style="font-size: 0.7rem;"></i>
                        <span><?= $line ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- AI Suggestions: shown in modal only (opened from Sales Performance button) -->
        </div>

        <!-- Right column: lists -->
        <div class="col-lg-4 report-sidebar">
            <div class="chart-panel top-products-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Top Selling Products</h5>
                    <form method="get" class="d-flex align-items-center gap-2" id="top-n-form">
                        <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                        <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                        <label for="top_n" class="text-muted small mb-0">Show</label>
                        <select name="top_n" id="top_n" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()" aria-label="Number of products to display">
                            <?php foreach ($allowedTopN as $n): ?>
                            <option value="<?= $n ?>"<?= $topProductsLimit === $n ? ' selected' : '' ?>><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted small mb-0">products</span>
                    </form>
                </div>
                <p class="text-muted small mb-3">By revenue in selected period.</p>
                <ul class="list-group list-group-flush">
                    <?php foreach ($top_products as $index => $prod): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center overflow-hidden" style="width: 40px; height: 40px; border: 1px solid #eee;">
                                <?php if (!empty($prod['main_image']) && !empty($prod['product_id'])): ?>
                                    <img src="/stock/uploads/products/<?= (int)$prod['product_id'] ?>/medium/<?= htmlspecialchars($prod['main_image']) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<?= $index + 1 ?>'">
                                <?php else: ?>
                                    <span style="font-weight: bold; color: var(--text-muted);"><?= $index + 1 ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($prod['name'] ?? 'Item') ?></div>
                                <div class="small text-muted"><?= (int)($prod['sold'] ?? 0) ?> units sold</div>
                            </div>
                        </div>
                        <div class="fw-bold text-success fs-6"><?= number_format($prod['revenue'] ?? 0) ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($top_products)): ?>
                <div class="text-center py-4 text-muted">No product sales in this period.</div>
                <?php endif; ?>
            </div>

            <?php if (!empty($top_customers)): ?>
            <div class="chart-panel report-section top-customers-card">
                <h5 class="mb-2"><i class="fas fa-users me-2 text-primary"></i>Top Customers</h5>
                <div class="table-responsive">
                    <table class="table table-custom table-sm mb-0">
                        <thead class="bg-light"><tr><th style="width: 50%;">Customer</th><th class="text-end" style="width: 20%;">Invoices</th><th class="text-end" style="width: 30%;">Total (TZS)</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_customers as $index => $c): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0" style="width: 28px; height: 28px; border: 1px solid #eee;">
                                            <?php if (!empty($c['logo'])): ?>
                                                <img src="/<?= htmlspecialchars($c['logo']) ?>" alt="<?= htmlspecialchars($c['company_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<i class=\'fas fa-building text-muted\' style=\'font-size: 0.7rem;\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-building text-muted" style="font-size: 0.7rem;"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <span class="fw-medium"><?= htmlspecialchars($c['company_name']) ?></span>
                                            <?php if ($index < 3): ?>
                                                <?php $stars = 3 - $index; ?>
                                                <span class="text-warning ms-1">
                                                    <?php for ($i = 0; $i < $stars; $i++): ?>
                                                        <i class="fas fa-star" style="font-size: 0.65rem;"></i>
                                                    <?php endfor; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end"><?= (int)$c['invoice_count'] ?></td>
                                <td class="text-end fw-bold"><?= number_format($c['total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sales by Rep: full width section -->
    <?php if (!empty($sales_by_rep)): ?>
    <div class="chart-panel report-section mt-4" role="region" aria-labelledby="sales-by-rep-heading">
        <h5 class="mb-3" id="sales-by-rep-heading"><i class="fas fa-user-tie me-2 text-primary" aria-hidden="true"></i>Sales by Rep</h5>
        <p class="text-muted small mb-3">Invoices and customers per rep in the selected period.</p>
                <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;" role="region" aria-label="Sales by Rep table">
                    <table class="table table-sm table-hover table-custom mb-0 sales-by-rep-table" role="table" aria-label="Sales by Rep">
                        <thead class="table-light">
                            <tr role="row">
                                <th class="col-rep" style="width: 25%;" scope="col">Rep</th>
                                <th class="col-invoice" style="width: 15%;" scope="col">Invoice</th>
                                <th class="col-customer" style="width: 35%;" scope="col">Customer</th>
                                <th class="text-end col-amount" style="width: 25%;" scope="col">Amount (TZS)</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($sales_by_rep as $index => $r): ?>
                        <?php 
                        $repName = htmlspecialchars($r['full_name'] ?: $r['username'] ?: 'Unknown');
                        $invoiceCount = count($r['invoices']);
                        $rowspan = $invoiceCount > 0 ? $invoiceCount + 1 : 1; // +1 for total row
                        $profilePhoto = $r['profile_photo'] ?? null;
                        ?>
                        <?php if ($invoiceCount > 0): ?>
                            <?php foreach ($r['invoices'] as $invIndex => $inv): ?>
                            <tr>
                                <?php if ($invIndex === 0): ?>
                                <td class="align-top border-end col-rep" rowspan="<?= $rowspan ?>">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0" style="width: 36px; height: 36px; border: 1px solid #eee;">
                                            <?php if (!empty($profilePhoto)): ?>
                                                <img src="/<?= htmlspecialchars($profilePhoto) ?>" alt="<?= $repName ?>" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<i class=\'fas fa-user text-muted\'></i>'">
                                            <?php else: ?>
                                                <i class="fas fa-user text-muted"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <?php if ($index === 0): ?>
                                                <span class="text-warning me-1" style="font-size: 0.85rem;"><i class="fas fa-star"></i></span>
                                            <?php endif; ?>
                                            <strong><?= $repName ?></strong>
                                            <div class="small text-muted mt-1"><?= (int)$r['invoice_count'] ?> inv Â· <?= number_format($r['total']) ?> TZS</div>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td class="fw-medium col-invoice">
                                    <?php if (!empty($inv['invoice_id'])): ?>
                                        <?php 
                                        // Build return URL with current filters
                                        $returnUrl = urlencode($_SERVER['PHP_SELF'] . '?' . http_build_query([
                                            'start_date' => $startDate,
                                            'end_date' => $endDate,
                                            'top_n' => $topProductsLimit
                                        ]));
                                        $invoiceUrl = '../modules/sales/invoices/view.php?id=' . (int)$inv['invoice_id'] . '&return=' . $returnUrl;
                                        ?>
                                        <a href="<?= htmlspecialchars($invoiceUrl) ?>" class="text-decoration-none text-primary" title="View invoice details">
                                            <?= htmlspecialchars($inv['invoice_number']) ?>
                                            <i class="fas fa-external-link-alt ms-1" style="font-size: 0.7rem; opacity: 0.6;" aria-hidden="true"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($inv['invoice_number']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-customer"><?= htmlspecialchars($inv['customer_name']) ?></td>
                                <td class="text-end col-amount"><?= number_format($inv['total_amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Total row for this rep -->
                            <tr class="table-light" style="border-top: 2px solid #dee2e6;">
                                <td class="col-invoice"></td>
                                <td class="col-customer"></td>
                                <td class="text-end col-amount fw-bold"><span class="text-muted">Total</span> <span class="text-danger"><?= number_format($r['total']) ?></span></td>
                            </tr>
                        <?php else: ?>
                        <tr>
                            <td class="border-end col-rep">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0" style="width: 36px; height: 36px; border: 1px solid #eee;">
                                        <?php if (!empty($profilePhoto)): ?>
                                            <img src="/<?= htmlspecialchars($profilePhoto) ?>" alt="<?= $repName ?>" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<i class=\'fas fa-user text-muted\'></i>'">
                                        <?php else: ?>
                                            <i class="fas fa-user text-muted"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <?php if ($index === 0): ?>
                                            <span class="text-warning me-1" style="font-size: 0.85rem;"><i class="fas fa-star"></i></span>
                                        <?php endif; ?>
                                        <strong><?= $repName ?></strong>
                                        <div class="small text-muted mt-1">0 inv Â· 0 TZS</div>
                                    </div>
                                </div>
                            </td>
                            <td colspan="3" class="text-muted small">No invoices</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($index < count($sales_by_rep) - 1): ?>
                        <tr class="rep-separator"><td colspan="4" style="height: 24px; padding: 0; border: none;"></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: AI Suggestions (popup) -->
<div class="modal fade" id="aiSuggestionsModal" tabindex="-1" aria-labelledby="aiSuggestionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiSuggestionsModalLabel"><i class="fas fa-robot me-2 text-primary"></i>AI Suggestions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Click a topic to see the suggestion.</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($suggestions as $i => $s): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm suggestion-btn" data-index="<?= $i ?>" title="<?= htmlspecialchars($s['category']) ?>">
                        <i class="fas <?= htmlspecialchars($s['icon']) ?> me-1 <?= $s['class'] ?>"></i><?= htmlspecialchars($s['category']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div id="suggestion-display" class="border rounded p-3 bg-light small" style="display: none;">
                    <p id="suggestion-display-text" class="mb-0"></p>
                </div>
                <div id="suggestion-placeholder" class="text-muted small py-2">Click a button above to view the suggestion.</div>
                <script type="application/json" id="suggestion-data"><?= json_encode(array_map(function($s) { return ['category' => $s['category'], 'text' => $s['text']]; }, $suggestions)) ?></script>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Suggestion on which part? -->
<div class="modal fade" id="suggestionModal" tabindex="-1" aria-labelledby="suggestionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="suggestionModalLabel"><i class="fas fa-lightbulb me-2 text-primary"></i>Submit a suggestion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                <input type="hidden" name="top_n" value="<?= (int)$topProductsLimit ?>">
                <input type="hidden" name="target_month" value="<?= htmlspecialchars($targetMonth) ?>">
                <div class="modal-body">
                    <p class="fw-semibold mb-3">Suggestion on which part? <span class="text-muted fw-normal">(Chagua sehemu)</span></p>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="suggestion_part" id="part_invoice" value="invoice" required>
                            <label class="form-check-label" for="part_invoice">Invoice</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="suggestion_part" id="part_quotations" value="quotations">
                            <label class="form-check-label" for="part_quotations">Quotations</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="suggestion_part" id="part_customers" value="customers">
                            <label class="form-check-label" for="part_customers">Customers</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="suggestion_part" id="part_sales_orders" value="sales_orders">
                            <label class="form-check-label" for="part_sales_orders">Sales Orders</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="suggestion_part" id="part_targets" value="targets">
                            <label class="form-check-label" for="part_targets">Targets</label>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label for="suggestion_message" class="form-label small">Your suggestion (optional)</label>
                        <textarea class="form-control form-control-sm" name="suggestion_message" id="suggestion_message" rows="3" placeholder="Describe your suggestion..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_suggestion" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Export Report -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportModalLabel"><i class="fas fa-download me-2 text-primary"></i>Export Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="exportForm" method="get" action="">
                    <input type="hidden" name="export" value="1">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    <input type="hidden" name="top_n" value="<?= (int)$topProductsLimit ?>">
                    <input type="hidden" name="target_month" value="<?= htmlspecialchars($targetMonth) ?>">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold mb-2">Select Format:</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" id="format_csv" value="csv" checked>
                                <label class="form-check-label" for="format_csv">
                                    <i class="fas fa-file-csv text-success"></i> CSV
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" id="format_excel" value="excel">
                                <label class="form-check-label" for="format_excel">
                                    <i class="fas fa-file-excel text-success"></i> Excel
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" id="format_word" value="word">
                                <label class="form-check-label" for="format_word">
                                    <i class="fas fa-file-word text-primary"></i> Word
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" id="format_pdf" value="pdf">
                                <label class="form-check-label" for="format_pdf">
                                    <i class="fas fa-file-pdf text-danger"></i> PDF
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold mb-2">Select Section:</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_all" value="all" checked>
                            <label class="form-check-label" for="section_all">
                                <strong>All Sections</strong> <span class="text-muted small">(Complete report)</span>
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_sales_by_rep" value="sales_by_rep">
                            <label class="form-check-label" for="section_sales_by_rep">
                                <strong>Sales by Rep</strong> <span class="text-muted small">(Invoices per rep)</span>
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_top_customers" value="top_customers">
                            <label class="form-check-label" for="section_top_customers">
                                <strong>Top Customers</strong> <span class="text-muted small">(By revenue)</span>
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_top_products" value="top_products">
                            <label class="form-check-label" for="section_top_products">
                                <strong>Top Selling Products</strong> <span class="text-muted small">(By revenue)</span>
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_quotations" value="quotations">
                            <label class="form-check-label" for="section_quotations">
                                <strong>Quotations by Status</strong>
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="section" id="section_invoices" value="invoices">
                            <label class="form-check-label" for="section_invoices">
                                <strong>Invoices by Status</strong>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="section" id="section_sales_trend" value="sales_trend">
                            <label class="form-check-label" for="section_sales_trend">
                                <strong>Sales Trend</strong> <span class="text-muted small">(Monthly breakdown)</span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal" aria-label="Cancel export">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('exportForm').submit();" aria-label="Export report">
                    <span class="export-btn-text"><i class="fas fa-download me-1" aria-hidden="true"></i>Export</span>
                    <span class="export-btn-loading d-none"><i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>Generating...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading overlay -->
<div class="export-loading" id="exportLoading" role="status" aria-live="polite" aria-label="Generating export">
    <div class="spinner"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i></div>
    <div class="spinner-text">Generating export file, please wait...</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Show loading state on export
    document.addEventListener('DOMContentLoaded', function() {
        const exportForm = document.getElementById('exportForm');
        const exportBtn = exportForm ? exportForm.closest('.modal').querySelector('button[onclick*="exportForm"]') : null;
        const loadingOverlay = document.getElementById('exportLoading');
        
        if (exportForm && exportBtn) {
            exportForm.addEventListener('submit', function(e) {
                // Validate form before submission
                const format = exportForm.querySelector('input[name="format"]:checked');
                const section = exportForm.querySelector('input[name="section"]:checked');
                
                if (!format || !section) {
                    e.preventDefault();
                    alert('Please select both format and section.');
                    return false;
                }
                
                if (loadingOverlay) {
                    loadingOverlay.classList.add('show');
                }
                if (exportBtn) {
                    const btnText = exportBtn.querySelector('.export-btn-text');
                    const btnLoading = exportBtn.querySelector('.export-btn-loading');
                    if (btnText) btnText.classList.add('d-none');
                    if (btnLoading) btnLoading.classList.remove('d-none');
                    exportBtn.disabled = true;
                }
            });
        }
        
        // Hide loading if page loads (in case of error or return)
        if (loadingOverlay) {
            setTimeout(function() {
                loadingOverlay.classList.remove('show');
            }, 3000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + E for export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                const exportModal = document.getElementById('exportModal');
                if (exportModal) {
                    const bsModal = new bootstrap.Modal(exportModal);
                    bsModal.show();
                }
            }
            // Ctrl/Cmd + P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                // Allow default print behavior
            }
        });
    });
</script>
<script>
    function updateMonthlyTarget(form) {
        var targetMonth = document.getElementById('target_year_select').value + '-' + document.getElementById('target_month_select').value;
        form.querySelector('input[name="target_month"]').value = targetMonth;
        var params = new URLSearchParams({
            start_date: form.querySelector('input[name="start_date"]').value,
            end_date: form.querySelector('input[name="end_date"]').value,
            top_n: form.querySelector('input[name="top_n"]').value,
            target_month: targetMonth
        });
        var url = window.location.pathname + '?' + params.toString();
        fetch(url).then(function(r) { return r.text(); }).then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var card = doc.getElementById('monthly-target-card');
            var el = document.getElementById('monthly-target-card');
            if (card && el) { el.innerHTML = card.innerHTML; }
            history.replaceState(null, '', url);
        });
    }
    (function() {
        var el = document.getElementById('suggestion-data');
        var display = document.getElementById('suggestion-display');
        var displayText = document.getElementById('suggestion-display-text');
        var placeholder = document.getElementById('suggestion-placeholder');
        if (!el || !display || !displayText || !placeholder) return;
        var data = [];
        try { data = JSON.parse(el.textContent); } catch (e) {}
        document.querySelectorAll('.suggestion-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var i = parseInt(this.getAttribute('data-index'), 10);
                if (data[i]) {
                    displayText.textContent = data[i].text;
                    display.style.display = 'block';
                    placeholder.style.display = 'none';
                }
            });
        });
    })();
</script>
<script>
    const ctxTrend = document.getElementById('mainTrendChart');
    if (ctxTrend) {
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?= $chartLabels ?>,
                datasets: [
                    {
                        label: 'Invoices (TZS)',
                        data: <?= $chartData ?>,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#4361ee',
                        pointBorderWidth: 2
                    },
                    {
                        label: 'Quotations (TZS)',
                        data: <?= $chartDataQuotations ?? '[]' ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.08)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#f59e0b',
                        pointBorderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString() + ' TZS';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { borderDash: [5, 5] },
                        ticks: { callback: function(v) { return Number(v).toLocaleString(); } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 45, minRotation: 0, autoSkip: false }
                    }
                }
            }
        });
    }
</script>
</body>
</html>
