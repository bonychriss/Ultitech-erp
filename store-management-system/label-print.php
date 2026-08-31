<?php
/**
 * Printable product labels ù opens in new window for Save as PDF.
 */
require_once __DIR__ . '/../stock/config/database.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: label.php');
    exit;
}

if (function_exists('app_url')) {
    $stockBasePath = app_url('stock/');
} else {
    $stockBasePath = '../stock/';
}

function sms_print_absolute_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

$productIds = array_map('intval', (array) ($_POST['product_ids'] ?? []));
$productIds = array_values(array_filter($productIds, static fn(int $id) => $id > 0));
$quantities = (array) ($_POST['quantities'] ?? []);

if (empty($productIds)) {
    die('No products selected.');
}

$allowedPerPage = [1, 2, 4, 6, 8];
$perPage = (int) ($_POST['per_page'] ?? 1);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 1;
}
$imageSize = $perPage === 1 ? 'large' : 'medium';

$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$imageSql = function_exists('stock_product_main_image_sql')
    ? stock_product_main_image_sql($pdo, 'p')
    : 'p.main_image';

$sql = "SELECT p.id, p.product_code, p.name, {$imageSql} AS image_file
        FROM products p
        WHERE p.id IN ({$placeholders})
        ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($productIds);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$labels = [];
foreach ($rows as $row) {
    $pid = (int) $row['id'];
    $qty = max(1, min(99, (int) ($quantities[$pid] ?? 1)));
    $imageUrl = function_exists('stock_product_list_image_url')
        ? stock_product_list_image_url($pid, (string) ($row['image_file'] ?? ''), $imageSize, $stockBasePath)
        : '';

    $label = [
        'code' => (string) ($row['product_code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'image' => sms_print_absolute_url($imageUrl),
    ];

    for ($i = 0; $i < $qty; $i++) {
        $labels[] = $label;
    }
}

if (empty($labels)) {
    die('No labels to print.');
}

$pages = array_chunk($labels, $perPage);
$layoutClass = 'layout-' . $perPage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Labels PDF</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        @page landscape-page {
            size: A4 landscape;
            margin: 10mm;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #fff;
            color: #000;
        }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0;
            background: #fff;
            padding: 8mm;
            page-break-after: always;
            break-after: page;
        }

        .sheet:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .labels-grid {
            display: grid;
            gap: 8mm;
            height: 100%;
        }

        .product-label {
            border: 2px solid #000;
            border-radius: 6px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .label-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .label-image-wrap {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #fafafa;
        }

        .label-image-wrap img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .label-image-placeholder {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            font-weight: 700;
        }

        .label-line {
            font-weight: 800;
            line-height: 1.35;
            text-transform: uppercase;
            word-break: break-word;
        }

        /* 1 per page ù landscape, full width */
        body.layout-1 .sheet {
            width: 297mm;
            min-height: 210mm;
            padding: 6mm;
            page: landscape-page;
        }

        body.layout-1 .labels-grid {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr;
            min-height: 198mm;
            gap: 0;
        }

        body.layout-1 .product-label {
            flex-direction: row;
            align-items: stretch;
            min-height: 198mm;
            width: 100%;
            padding: 10mm 12mm;
            gap: 12mm;
            border-width: 3px;
            border-radius: 8px;
        }

        body.layout-1 .label-image-wrap {
            flex: 1.2;
            min-width: 0;
            min-height: auto;
            height: auto;
            border-width: 2px;
        }

        body.layout-1 .label-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        body.layout-1 .label-details {
            flex: 1;
            min-width: 0;
            justify-content: center;
            gap: 10mm;
        }

        body.layout-1 .label-line {
            font-size: 30px;
            line-height: 1.3;
        }

        /* 2 per page ù portrait, 2 columns */
        body.layout-2 .labels-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        body.layout-2 .product-label {
            min-height: 68mm;
        }

        body.layout-2 .label-image-wrap {
            height: 34mm;
        }

        body.layout-2 .label-line {
            font-size: 13px;
        }

        /* 4 per page ù 2 x 2 */
        body.layout-4 .labels-grid {
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(2, 1fr);
        }

        body.layout-4 .product-label {
            min-height: 62mm;
        }

        body.layout-4 .label-image-wrap {
            height: 28mm;
        }

        body.layout-4 .label-line {
            font-size: 11px;
        }

        /* 6 per page ù 2 x 3 */
        body.layout-6 .labels-grid {
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(3, 1fr);
        }

        body.layout-6 .product-label {
            min-height: 40mm;
            padding: 8px 10px;
            gap: 6px;
        }

        body.layout-6 .label-image-wrap {
            height: 18mm;
        }

        body.layout-6 .label-line {
            font-size: 10px;
        }

        /* 8 per page ù 2 x 4 */
        body.layout-8 .labels-grid {
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
        }

        body.layout-8 .product-label {
            min-height: 30mm;
            padding: 6px 8px;
            gap: 4px;
        }

        body.layout-8 .label-image-wrap {
            height: 14mm;
        }

        body.layout-8 .label-line {
            font-size: 9px;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .sheet {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
            }

            body.layout-1 .sheet {
                width: 100%;
                min-height: 100%;
                padding: 5mm;
            }

            body.layout-1 .labels-grid {
                min-height: 190mm;
            }

            body.layout-1 .product-label {
                min-height: 190mm;
                width: 100%;
            }

            body.layout-1 .label-line {
                font-size: 28px;
            }
        }
    </style>
</head>
<body class="<?= htmlspecialchars($layoutClass) ?>">
    <?php foreach ($pages as $pageLabels): ?>
    <div class="sheet">
        <div class="labels-grid">
            <?php foreach ($pageLabels as $label): ?>
                <div class="product-label">
                    <div class="label-image-wrap">
                        <?php if ($label['image'] !== ''): ?>
                            <img src="<?= htmlspecialchars($label['image']) ?>" alt="">
                        <?php else: ?>
                            <span class="label-image-placeholder">No image</span>
                        <?php endif; ?>
                    </div>
                    <div class="label-details">
                        <div class="label-line">PRODUCT CODE: <?= htmlspecialchars($label['code']) ?></div>
                        <div class="label-line">PRODUCT NAME : <?= htmlspecialchars($label['name']) ?></div>
                        <div class="label-line">SIZE(s) :</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</body>
</html>
