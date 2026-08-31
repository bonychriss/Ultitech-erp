<?php
// modules/finance/api/export.php
require_once '../../../includes/functions.php';
requireLogin();

$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';
$startDate = $_GET['startDate'] ?? '';
$endDate = $_GET['endDate'] ?? '';

$params = [];
$where = ["is_active = 1"];

if (!empty($search)) {
    $where[] = "(description LIKE ? OR category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type)) {
    $db_type = ($type == 'income') ? 'credit' : (($type == 'expense') ? 'debit' : '');
    if ($db_type) {
        $where[] = "type = ?";
        $params[] = $db_type;
    }
}

if (!empty($startDate)) {
    $where[] = "transaction_date >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $where[] = "transaction_date <= ?";
    $params[] = $endDate;
}

$whereSql = implode(" AND ", $where);

try {
    $sql = "SELECT transaction_date, type, category, amount, description, created_at 
            FROM finance_transactions 
            WHERE $whereSql 
            ORDER BY transaction_date DESC, created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for Excel XML download
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=finance_transactions_' . date('Ymd_His') . '.xls');

    // Output XML Header
    echo '<?xml version="1.0"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
             xmlns:o="urn:schemas-microsoft-com:office:office"
             xmlns:x="urn:schemas-microsoft-com:office:excel"
             xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
             xmlns:html="http://www.w3.org/TR/REC-html40">';
             
    // Styles
    echo '<Styles>
            <Style ss:ID="Default" ss:Name="Normal">
                <Alignment ss:Vertical="Bottom"/>
                <Borders/>
                <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>
                <Interior/>
                <NumberFormat/>
                <Protection/>
            </Style>
            <Style ss:ID="sHeader">
                <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000" ss:Bold="1"/>
                <Interior ss:Color="#E1E1E1" ss:Pattern="Solid"/>
            </Style>
            <Style ss:ID="sNumber">
                 <NumberFormat ss:Format="#,##0.00"/>
            </Style>
            <Style ss:ID="sDate">
                 <NumberFormat ss:Format="Short Date"/>
            </Style>
          </Styles>';

    echo '<Worksheet ss:Name="Transactions">';
    echo '<Table>';
    
    // Column Widths (points)
    echo '<Column ss:Width="80"/>';  // Date
    echo '<Column ss:Width="150"/>'; // Category
    echo '<Column ss:Width="300"/>'; // Description
    echo '<Column ss:Width="80"/>';  // Debit
    echo '<Column ss:Width="80"/>';  // Credit
    
    // Header Row
    echo '<Row>';
    echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">Date</Data></Cell>';
    echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">Category</Data></Cell>';
    echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">Description</Data></Cell>';
    echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">Debit</Data></Cell>';
    echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">Credit</Data></Cell>';
    echo '</Row>';

    foreach ($transactions as $row) {
        $amount = (float) $row['amount'];
        $debit = ($row['type'] === 'debit') ? number_format($amount, 2, '.', '') : '';
        $credit = ($row['type'] === 'credit') ? number_format($amount, 2, '.', '') : '';

        echo '<Row>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['transaction_date']) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['category']) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($row['description']) . '</Data></Cell>';
        
        // Debit
        if ($debit !== '') {
             echo '<Cell ss:StyleID="sNumber"><Data ss:Type="Number">' . $debit . '</Data></Cell>';
        } else {
             echo '<Cell><Data ss:Type="String"></Data></Cell>';
        }

        // Credit
        if ($credit !== '') {
             echo '<Cell ss:StyleID="sNumber"><Data ss:Type="Number">' . $credit . '</Data></Cell>';
        } else {
             echo '<Cell><Data ss:Type="String"></Data></Cell>';
        }

        echo '</Row>';
    }
    
    echo '</Table>';
    echo '</Worksheet>';
    echo '</Workbook>';
    
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo "Error generating export: " . $e->getMessage();
}
