<?php
require_once __DIR__ . '/functions.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'xls';

if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid voucher id';
    exit;
}

// Fetch voucher
$stmt = $pdo->prepare("SELECT pv.*, ua.full_name AS approver_name, ua.email AS approver_email FROM payment_vouchers pv LEFT JOIN users ua ON pv.approved_by = ua.id WHERE pv.id = ?");
$stmt->execute([$id]);
$voucher = $stmt->fetch();
if (!$voucher) {
    http_response_code(404);
    echo 'Voucher not found';
    exit;
}

// Fetch items
$stmt = $pdo->prepare("SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$fnameSafe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $voucher['voucher_no']);

// Build a simple HTML document that Excel/Word can consume
// Build absolute URL for assets (logo) so Excel can load the image when opened)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$projectRoot = rtrim(dirname($scriptDir), '/');
$logoUrl = $scheme . '://' . $host . $projectRoot . '/assets/images/Untitled.jpg';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title><?= htmlspecialchars($voucher['voucher_no']) ?> - Export</title>
    <style>
        body { font-family: "Courier New", Courier, monospace; font-size: 12px; color:#000; }
        h1 { font-size: 28px; margin: 0; letter-spacing: 1px; color:#333; }
        .pv-header { display: table; width: 100%; margin-bottom: 12px; }
        .pv-header .logo { display: table-cell; vertical-align: middle; width: 60px; }
        .pv-header .title { display: table-cell; vertical-align: middle; padding-left: 15px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; font-weight: normal; }
        th.amount, td.amount { text-align: right; }
        .noborder { border: none !important; }
        .tight { margin-bottom: 10px; }
        .center { text-align:center; }
        .right { text-align:right; }
        .bold { font-weight: bold; }
    </style>
</head>
<body>
    <!-- Header with logo and title -->
    <div class="pv-header">
        <div class="logo"><img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" style="width:60px;height:auto;" /></div>
        <div class="title"><h1>PAYMENT VOUCHER</h1></div>
    </div>

    <!-- Details table (matching on-screen grid) -->
    <table class="tight">
        <colgroup>
            <col style="width:20%" />
            <col style="width:20%" />
            <col style="width:20%" />
            <col style="width:10%" />
            <col style="width:30%" />
        </colgroup>
        <tr>
            <td class="bold">Voucher NO. :</td>
            <td colspan="2"><?= htmlspecialchars($voucher['voucher_no']) ?></td>
            <td class="bold">Date:</td>
            <td><?= htmlspecialchars(date('Y-m-d', strtotime($voucher['date_created']))) ?></td>
        </tr>
        <tr>
            <td class="bold">Payee Name:</td>
            <td colspan="2"><?= htmlspecialchars($voucher['payee_name']) ?></td>
            <td class="bold">Prepared By:</td>
            <td><?= htmlspecialchars($voucher['prepared_by'] ?: '') ?></td>
        </tr>
        <tr>
            <td class="bold">Description:</td>
            <td colspan="2"><?= htmlspecialchars($voucher['description'] ?? '') ?></td>
            <td class="bold">Supporting<br/>Documents (Qty.)</td>
            <td><?= (int)($voucher['supporting_documents'] ?? 0) ?></td>
        </tr>
        <tr>
            <td class="bold">Currency:</td>
            <td><?= htmlspecialchars($voucher['currency']) ?></td>
            <td class="noborder"></td>
            <td class="bold">Amount:</td>
            <td class="bold right"><?= number_format((float)($voucher['total_amount'] ?? 0), 2) ?></td>
        </tr>
    </table>

    <!-- Items table -->
    <table class="tight">
        <thead>
            <tr>
                <th style="width:20%">Payment Type</th>
                <th style="width:20%">Budget Type</th>
                <th style="width:20%" class="center">Name</th>
                <th style="width:10%" class="center">Amount</th>
                <th style="width:30%">Description</th>
            </tr>
        </thead>
        <tbody>
            <?php $total=0; foreach($items as $it): $total += (float)$it['amount']; ?>
            <tr>
                <td><?= htmlspecialchars($it['payment_type']) ?></td>
                <td><?= htmlspecialchars($it['budget_type']) ?></td>
                <td class="center"><?= htmlspecialchars($it['name']) ?></td>
                <td class="center"><?= number_format((float)$it['amount'], 2) ?></td>
                <td><?= htmlspecialchars($it['description'] ?: '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Approvals table -->
    <table>
        <colgroup>
            <col style="width:12%" />
            <col style="width:28%" />
            <col style="width:20%" />
            <col style="width:40%" />
        </colgroup>
        <tr>
            <td class="bold center">Applicant</td>
            <td><?= htmlspecialchars($voucher['applicant'] ?? '') ?></td>
            <td class="bold center">Check</td>
            <td><?= htmlspecialchars($voucher['checked_by'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="bold center">Department Manager</td>
            <td><?= htmlspecialchars($voucher['department_manager'] ?? '') ?></td>
            <td class="bold center">General Manager</td>
            <td>
                <?php 
                    $gm = trim((string)($voucher['general_manager'] ?? ''));
                    if (strtolower(trim((string)($voucher['approver_email'] ?? ''))) === 'rajabmwanyika@gmail.com') {
                        if ($gm === '' || strtoupper($gm) === 'RAJAB') { $gm = 'RAJABU MWANYIKA'; }
                    } elseif (strtolower(trim((string)($voucher['approver_email'] ?? ''))) === 'rajabmsomali@gmail.com') {
                        if ($gm === '') { $gm = trim((string)($voucher['approver_name'] ?? '')); }
                    }
                    echo htmlspecialchars($gm);
                ?>
            </td>
        </tr>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

switch ($format) {
    case 'xls':
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="'.$fnameSafe.'.xls"');
        echo $html; // Excel can open HTML tables
        break;
    case 'doc':
        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="'.$fnameSafe.'.doc"');
        echo $html; // Word can open HTML docs
        break;
    case 'pdf':
        // For now, redirect to print-friendly view; we can integrate dompdf later.
        header('Location: ../employee/view-voucher.php?id='.$id.'&print=1');
        break;
    default:
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
}
