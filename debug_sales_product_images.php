<?php
/**
 * Debug: quotations, invoices, and product list ù image data in DB vs files vs generated URLs.
 * DELETE from production after fixing image issues.
 *
 * Usage:
 *   /debug_sales_product_images.php
 *   /ultimate/debug_sales_product_images.php?company_slug=ultimate
 *   /debug_sales_product_images.php?order_id=252
 *   /debug_sales_product_images.php?product_id=8&search=EXECUTIVE
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/modules/sales/functions.php';
require_once __DIR__ . '/stock/config/functions.php';

@ini_set('display_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$filterOrderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$filterInvoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$filterProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$filterSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$limitDocs = max(5, min(50, (int) ($_GET['limit'] ?? 15)));

function dspi_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dspi_row($label, $value = null)
{
    if ($value === null) {
        echo '<tr><th colspan="2" class="section">' . dspi_h($label) . '</th></tr>';
        return;
    }
    echo '<tr><th>' . dspi_h($label) . '</th><td>' . $value . '</td></tr>';
}

/**
 * @return array<string,mixed>
 */
function dspi_analyze_product(PDO $pdo, $productId, $companySlug, $companyId, $stockBasePath)
{
    $productId = (int) $productId;
    $out = array(
        'product_id' => $productId,
        'name' => '',
        'code' => '',
        'main_image' => '',
        'image_col' => '',
        'gallery' => array(),
        'disk_medium' => '',
        'disk_thumbnail' => '',
        'resolve_medium' => '',
        'url_index' => '',
        'url_view_static' => '',
        'url_sales_line' => '',
        'url_product_image' => '',
        'status' => 'no_product_id',
    );
    if ($productId < 1) {
        return $out;
    }

    $row = null;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0);
        $cols = is_array($cols) ? $cols : array();
        $imgCol = in_array('main_image', $cols, true) ? 'main_image' : (in_array('image', $cols, true) ? 'image' : '');
        $st = $pdo->prepare('SELECT id, name, product_code' . ($imgCol !== '' ? ', `' . str_replace('`', '``', $imgCol) . '` AS main_image' : '') . ' FROM products WHERE id = ? LIMIT 1');
        $st->execute(array($productId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out['name'] = (string) ($row['name'] ?? '');
            $out['code'] = (string) ($row['product_code'] ?? '');
            $out['main_image'] = (string) ($row['main_image'] ?? '');
            $out['image_col'] = $imgCol;
        }
    } catch (Throwable $e) {
        $out['status'] = 'db_error:' . $e->getMessage();
        return $out;
    }

    try {
        $stG = $pdo->prepare('SELECT id, image_name, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC');
        $stG->execute(array($productId));
        $out['gallery'] = $stG->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        $out['gallery'] = array();
    }

    $slug = strtolower(trim((string) $companySlug));
    $cid = (int) $companyId;

    if (function_exists('stock_resolve_product_image_file')) {
        $dm = stock_resolve_product_image_file($productId, 'medium', (string) $out['main_image'], $slug, $cid);
        $dt = stock_resolve_product_image_file($productId, 'thumbnail', (string) $out['main_image'], $slug, $cid);
        $out['disk_medium'] = $dm ? $dm : '';
        $out['disk_thumbnail'] = $dt ? $dt : '';
        $out['resolve_medium'] = $dm ? 'yes' : 'no';
    }

    $item = array(
        'product_id' => $productId,
        'main_image' => $out['main_image'],
    );
    if (function_exists('sales_enrich_order_items_images')) {
        $enriched = sales_enrich_order_items_images(array($item), $pdo);
        $item = $enriched[0] ?? $item;
        $out['enriched_main_image'] = (string) ($item['main_image'] ?? '');
    }

    if (function_exists('stock_product_list_image_url')) {
        $out['url_index'] = stock_product_list_image_url($productId, (string) ($item['main_image'] ?? $out['main_image']), 'medium', (string) $stockBasePath);
    }
    if ($stockBasePath !== '') {
        $out['url_view_static'] = rtrim((string) $stockBasePath, '/') . '/uploads/products/' . $productId . '/medium/' . rawurlencode((string) ($item['main_image'] ?? $out['main_image']));
    }
    if (function_exists('sales_order_item_image_url')) {
        $out['url_sales_line'] = sales_order_item_image_url($item, 'medium');
    }
    if (function_exists('stock_product_list_image_url')) {
        $params = array('product_id' => $productId, 'size' => 'medium', 'company_slug' => $slug);
        if (!empty($item['main_image'])) {
            $params['file'] = basename((string) $item['main_image']);
        }
        $q = 'stock/product_image.php?' . http_build_query($params);
        $out['url_product_image'] = function_exists('app_url') ? app_url($q) : ('/' . ltrim($q, '/'));
    }

    $hasGallery = count($out['gallery']) > 0;
    $hasMain = trim((string) $out['main_image']) !== '';
    $hasDisk = $out['disk_medium'] !== '';

    if ($hasDisk && $out['url_index'] !== '') {
        $out['status'] = 'ok';
    } elseif ($hasDisk && $out['url_index'] === '') {
        $out['status'] = 'disk_ok_no_url';
    } elseif (($hasMain || $hasGallery) && !$hasDisk) {
        $out['status'] = 'db_only_missing_file';
    } elseif (!$hasMain && !$hasGallery) {
        $out['status'] = 'no_image_in_db';
    } else {
        $out['status'] = 'unknown';
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function dspi_disk_roots($companySlug, $companyId)
{
    $roots = array();
    $cid = (int) $companyId;
    $slug = strtolower(trim((string) $companySlug));
    if ($cid > 0) {
        $t = realpath(__DIR__ . '/storage/tenant_' . $cid . '/products');
        $roots[] = array('label' => 'tenant_' . $cid, 'path' => $t ?: '(missing)');
    }
    if (function_exists('stock_uploads_legacy_products_dir')) {
        $l = stock_uploads_legacy_products_dir($slug);
        $roots[] = array('label' => 'legacy_scoped', 'path' => $l ?: '(missing)');
    }
    $flat = realpath(__DIR__ . '/stock/uploads/products');
    $roots[] = array('label' => 'legacy_flat', 'path' => $flat ?: '(missing)');
    return $roots;
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$ctx = function_exists('stock_image_company_context') ? stock_image_company_context() : array('slug' => 'ultimate', 'company_id' => 1);
$companySlug = strtolower(trim((string) ($_GET['company_slug'] ?? $ctx['slug'] ?? '')));
if ($companySlug !== '') {
    $_GET['company_slug'] = $companySlug;
    $_SESSION['company_slug'] = $companySlug;
}
$companyId = (int) ($ctx['company_id'] ?? 1);

$stockBasePath = '/stock/';
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$parts = explode('/', trim($scriptName, '/'));
$idx = array_search('stock', $parts, true);
if ($idx !== false) {
    $stockBasePath = '/' . implode('/', array_slice($parts, 0, $idx + 1)) . '/';
} elseif (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') {
    $stockBasePath = rtrim((string) APP_BASE_PATH, '/') . '/stock/';
}

$salesDbName = '';
try {
    $salesDbName = (string) $salesDb->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sales &amp; product image debug</title>';
echo '<style>
body{font-family:Segoe UI,Arial,sans-serif;margin:20px;background:#f8fafc;color:#111}
h1{font-size:1.35rem} h2{font-size:1.05rem;margin-top:28px}
table{border-collapse:collapse;width:100%;margin:10px 0;background:#fff;font-size:13px}
th,td{border:1px solid #e2e8f0;padding:8px;text-align:left;vertical-align:top}
th{background:#f1f5f9;font-weight:600}
th.section{background:#ede9fe;color:#5b21b6}
.ok{color:#059669;font-weight:700}.bad{color:#dc2626;font-weight:700}.warn{color:#d97706;font-weight:700}
a{color:#6d28d9} code{font-size:12px;background:#f1f5f9;padding:2px 4px;border-radius:4px}
.badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.badge-ok{background:#dcfce7;color:#166534}.badge-bad{background:#fee2e2;color:#991b1b}.badge-warn{background:#ffedd5;color:#9a3412}
.img-test{width:48px;height:48px;object-fit:cover;border:1px solid #ddd;border-radius:6px;background:#f8fafc}
.filters{margin:12px 0;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}
.filters input,.filters select{margin-right:8px;padding:6px 8px}
</style></head><body>';

echo '<h1>Sales quotations, invoices &amp; product images ù debug</h1>';
echo '<p>Compares <strong>database</strong> (products.main_image, product_images), <strong>files on disk</strong>, and <strong>URLs</strong> used on index vs view vs sales documents.</p>';
echo '<p class="warn"><strong>Remove this file from production when done.</strong></p>';

echo '<div class="filters"><form method="get">';
echo 'Company slug <input type="text" name="company_slug" value="' . dspi_h($companySlug) . '" size="12"> ';
echo 'Order id <input type="number" name="order_id" value="' . ($filterOrderId ?: '') . '" size="6"> ';
echo 'Invoice id <input type="number" name="invoice_id" value="' . ($filterInvoiceId ?: '') . '" size="6"> ';
echo 'Product id <input type="number" name="product_id" value="' . ($filterProductId ?: '') . '" size="6"> ';
echo 'Search <input type="text" name="search" value="' . dspi_h($filterSearch) . '" size="14"> ';
echo 'Limit <input type="number" name="limit" value="' . (int) $limitDocs . '" size="3" min="5" max="50"> ';
echo '<button type="submit">Run</button></form></div>';

echo '<table>';
dspi_row('Session company_slug', dspi_h($_SESSION['company_slug'] ?? ''));
dspi_row('Session company_id', (string) ($_SESSION['company_id'] ?? ''));
dspi_row('Resolved slug / id', dspi_h($companySlug) . ' / ' . (int) $companyId);
dspi_row('APP_BASE_PATH', dspi_h(defined('APP_BASE_PATH') ? APP_BASE_PATH : ''));
dspi_row('stockBasePath (detected)', dspi_h($stockBasePath));
dspi_row('sales_pdo database', dspi_h($salesDbName));
dspi_row('Script', dspi_h($_SERVER['SCRIPT_NAME'] ?? ''));
dspi_row('Disk roots');
$rootsHtml = '<ul style="margin:0;padding-left:18px">';
foreach (dspi_disk_roots($companySlug, $companyId) as $r) {
    $rootsHtml .= '<li><code>' . dspi_h($r['label']) . '</code> ? <code>' . dspi_h($r['path']) . '</code></li>';
}
$rootsHtml .= '</ul>';
dspi_row(null, $rootsHtml);
echo '</table>';

// --- Single product deep-dive ---
if ($filterProductId > 0 || $filterSearch !== '') {
    echo '<h2>Product list probe (index.php behaviour)</h2>';
    $products = array();
    try {
        $imgSel = 'NULL AS main_image';
        try {
            $pc = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0);
            if (is_array($pc) && in_array('main_image', $pc, true)) {
                $imgSel = 'main_image';
            } elseif (is_array($pc) && in_array('image', $pc, true)) {
                $imgSel = 'image AS main_image';
            }
        } catch (Throwable $e) {
        }
        if ($filterProductId > 0) {
            $st = $salesDb->prepare("SELECT id, name, product_code, {$imgSel} FROM products WHERE id = ? LIMIT 1");
            $st->execute(array($filterProductId));
            $products = $st->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($filterSearch !== '') {
            $st = $salesDb->prepare("SELECT id, name, product_code, {$imgSel} FROM products WHERE name LIKE ? OR product_code LIKE ? ORDER BY id DESC LIMIT 20");
            $like = '%' . $filterSearch . '%';
            $st->execute(array($like, $like));
            $products = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        echo '<p class="bad">Products query failed: ' . dspi_h($e->getMessage()) . '</p>';
    }

    if ($products) {
        echo '<table><thead><tr>
            <th>ID</th><th>Product</th><th>DB main_image</th><th>Gallery</th><th>Disk medium</th>
            <th>Status</th><th>Index URL</th><th>Preview</th><th>product_image.php</th>
        </tr></thead><tbody>';
        foreach ($products as $p) {
            $a = dspi_analyze_product($salesDb, (int) $p['id'], $companySlug, $companyId, $stockBasePath);
            $badge = $a['status'] === 'ok' ? 'badge-ok' : ($a['status'] === 'db_only_missing_file' ? 'badge-warn' : 'badge-bad');
            $gal = count($a['gallery']);
            echo '<tr>';
            echo '<td>' . (int) $a['product_id'] . '</td>';
            echo '<td>' . dspi_h($a['name']) . '<br><code>' . dspi_h($a['code']) . '</code></td>';
            echo '<td><code>' . dspi_h($a['main_image']) . '</code>';
            if (!empty($a['enriched_main_image']) && $a['enriched_main_image'] !== $a['main_image']) {
                echo '<br>enriched: <code>' . dspi_h($a['enriched_main_image']) . '</code>';
            }
            echo '</td>';
            echo '<td>' . (int) $gal . ' row(s)</td>';
            echo '<td><code style="font-size:10px">' . dspi_h($a['disk_medium'] ?: 'ù') . '</code></td>';
            echo '<td><span class="badge ' . $badge . '">' . dspi_h($a['status']) . '</span></td>';
            echo '<td style="max-width:220px;word-break:break-all"><a href="' . dspi_h($a['url_index']) . '" target="_blank">' . dspi_h($a['url_index']) . '</a></td>';
            echo '<td>';
            if ($a['url_index'] !== '') {
                echo '<img class="img-test" src="' . dspi_h($a['url_index']) . '" alt="">';
            } else {
                echo 'ù';
            }
            echo '</td>';
            echo '<td style="max-width:200px;word-break:break-all"><a href="' . dspi_h($a['url_product_image']) . '" target="_blank">test</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No products matched.</p>';
    }
}

/**
 * @return list<array<string,mixed>>
 */
function dspi_fetch_documents(PDO $salesDb, $type, $filterId, $limit)
{
    $docs = array();
    try {
        if ($type === 'order') {
            if ($filterId > 0) {
                $st = $salesDb->prepare("SELECT id, order_number, status, created_at FROM sales_orders WHERE id = ? LIMIT 1");
                $st->execute(array($filterId));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return $row ? array($row) : array();
            }
            $st = $salesDb->query("SELECT id, order_number, status, created_at FROM sales_orders WHERE status IN ('quotation','draft','sent','confirmed') ORDER BY id DESC LIMIT " . (int) $limit);
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        }
        if ($type === 'invoice') {
            if ($filterId > 0) {
                $st = $salesDb->prepare("SELECT id, invoice_number, status, created_at, order_id FROM invoices WHERE id = ? LIMIT 1");
                $st->execute(array($filterId));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return $row ? array($row) : array();
            }
            $st = $salesDb->query("SELECT id, invoice_number, status, created_at, order_id FROM invoices ORDER BY id DESC LIMIT " . (int) $limit);
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        }
    } catch (Throwable $e) {
        return array();
    }
    return $docs;
}

/**
 * @return list<array<string,mixed>>
 */
function dspi_fetch_line_items(PDO $salesDb, $orderId)
{
    $orderId = (int) $orderId;
    if ($orderId < 1) {
        return array();
    }
    $productImageCol = 'main_image';
    try {
        $cols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($cols) && !in_array('main_image', $cols, true) && in_array('image', $cols, true)) {
            $productImageCol = 'image';
        }
    } catch (Throwable $e) {
    }
    $sql = "SELECT soi.id AS line_id, soi.product_id, soi.quantity, p.name AS product_name, p.product_code, p.`{$productImageCol}` AS main_image
            FROM sales_order_items soi
            LEFT JOIN products p ON p.id = soi.product_id
            WHERE soi.order_id = ?";
    try {
        $st = $salesDb->prepare($sql);
        $st->execute(array($orderId));
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return array();
    }
}

function dspi_render_document_section($title, $docs, PDO $salesDb, $companySlug, $companyId, $stockBasePath, $viewBase)
{
    echo '<h2>' . dspi_h($title) . ' (' . count($docs) . ')</h2>';
    if (!$docs) {
        echo '<p class="bad">No documents found.</p>';
        return;
    }

    $summary = array('ok' => 0, 'db_only_missing_file' => 0, 'no_image_in_db' => 0, 'other' => 0, 'lines' => 0);

    foreach ($docs as $doc) {
        $orderId = (int) ($doc['id'] ?? $doc['order_id'] ?? 0);
        if (isset($doc['order_id']) && (int) $doc['order_id'] > 0) {
            $orderId = (int) $doc['order_id'];
        }
        $docLabel = isset($doc['order_number']) ? $doc['order_number'] : ($doc['invoice_number'] ?? '#' . ($doc['id'] ?? ''));
        $docStatus = (string) ($doc['status'] ?? '');
        $viewLink = $viewBase . (int) ($doc['id'] ?? 0);

        echo '<h3 style="margin-top:18px">' . dspi_h($docLabel) . ' <span class="badge badge-warn">' . dspi_h($docStatus) . '</span>';
        echo ' ù <a href="' . dspi_h($viewLink) . '" target="_blank">open view</a>';
        echo ' ù order_id <code>' . (int) $orderId . '</code></h3>';

        $lines = dspi_fetch_line_items($salesDb, $orderId);
        if (!$lines) {
            echo '<p class="warn">No line items or query failed.</p>';
            continue;
        }

        echo '<table><thead><tr>
            <th>Line</th><th>Product</th><th>DB image</th><th>Gallery #</th><th>File on disk</th>
            <th>Status</th><th>Sales/quote URL</th><th>Index-style URL</th><th>Thumbs</th>
        </tr></thead><tbody>';

        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $a = dspi_analyze_product($salesDb, $pid, $companySlug, $companyId, $stockBasePath);
            $summary['lines']++;
            if (isset($summary[$a['status']])) {
                $summary[$a['status']]++;
            } else {
                $summary['other']++;
            }
            $badge = $a['status'] === 'ok' ? 'badge-ok' : ($a['status'] === 'db_only_missing_file' ? 'badge-warn' : 'badge-bad');

            echo '<tr>';
            echo '<td>' . (int) ($line['line_id'] ?? 0) . '</td>';
            echo '<td>' . dspi_h($a['name'] ?: ($line['product_name'] ?? '')) . '<br><code>' . dspi_h($a['code'] ?: ($line['product_code'] ?? '')) . '</code> #' . $pid . '</td>';
            echo '<td><code>' . dspi_h($a['main_image']) . '</code></td>';
            echo '<td>' . count($a['gallery']) . '</td>';
            echo '<td style="font-size:10px">' . ($a['disk_medium'] !== '' ? '<span class="ok">yes</span><br><code>' . dspi_h($a['disk_medium']) . '</code>' : '<span class="bad">no</span>') . '</td>';
            echo '<td><span class="badge ' . $badge . '">' . dspi_h($a['status']) . '</span></td>';
            echo '<td style="max-width:180px;word-break:break-all;font-size:11px">';
            if ($a['url_sales_line'] !== '') {
                echo '<a href="' . dspi_h($a['url_sales_line']) . '" target="_blank">sales</a>';
            } else {
                echo 'ù';
            }
            echo '</td>';
            echo '<td style="max-width:180px;word-break:break-all;font-size:11px">';
            if ($a['url_index'] !== '') {
                echo '<a href="' . dspi_h($a['url_index']) . '" target="_blank">index</a>';
            } else {
                echo 'ù';
            }
            echo '</td>';
            echo '<td>';
            if ($a['url_sales_line'] !== '') {
                echo '<img class="img-test" src="' . dspi_h($a['url_sales_line']) . '" alt="">';
            }
            if ($a['url_index'] !== '' && $a['url_index'] !== $a['url_sales_line']) {
                echo '<img class="img-test" src="' . dspi_h($a['url_index']) . '" alt="" style="margin-left:4px">';
            }
            if ($a['url_sales_line'] === '' && $a['url_index'] === '') {
                echo 'ù';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p><strong>Summary:</strong> ' . (int) $summary['lines'] . ' lines ù ';
    echo '<span class="ok">' . (int) $summary['ok'] . ' ok</span>, ';
    echo '<span class="warn">' . (int) $summary['db_only_missing_file'] . ' db but missing file</span>, ';
    echo '<span class="bad">' . (int) $summary['no_image_in_db'] . ' no image in db</span>, ';
    echo (int) $summary['other'] . ' other.</p>';
}

$viewOrderBase = function_exists('sales_module_url') ? sales_module_url('orders/view.php', array('id' => '')) : 'modules/sales/orders/view.php?id=';
$viewOrderBase = preg_replace('/id=$/', 'id=', $viewOrderBase);
if (strpos($viewOrderBase, 'id=') === false) {
    $viewOrderBase = (function_exists('app_url') ? app_url('modules/sales/orders/view.php') : 'modules/sales/orders/view.php') . '?id=';
}

$viewInvBase = function_exists('sales_module_url') ? sales_module_url('invoices/view.php', array('id' => '')) : 'modules/sales/invoices/view.php?id=';
$viewInvBase = preg_replace('/id=$/', 'id=', $viewInvBase);
if (strpos($viewInvBase, 'id=') === false) {
    $viewInvBase = (function_exists('app_url') ? app_url('modules/sales/invoices/view.php') : 'modules/sales/invoices/view.php') . '?id=';
}

$quotations = dspi_fetch_documents($salesDb, 'order', $filterOrderId, $limitDocs);
$invoices = dspi_fetch_documents($salesDb, 'invoice', $filterInvoiceId, $limitDocs);

dspi_render_document_section('Quotations / sales orders', $quotations, $salesDb, $companySlug, $companyId, $stockBasePath, $viewOrderBase);
dspi_render_document_section('Invoices (via linked order lines)', $invoices, $salesDb, $companySlug, $companyId, $stockBasePath, $viewInvBase);

echo '<h2>Status legend</h2><ul>';
echo '<li><span class="badge badge-ok">ok</span> ù file found on disk; index URL generated</li>';
echo '<li><span class="badge badge-warn">db_only_missing_file</span> ù main_image or gallery in DB but no file under tenant/legacy uploads</li>';
echo '<li><span class="badge badge-bad">no_image_in_db</span> ù no main_image and no product_images rows</li>';
echo '</ul>';

echo '<h2>Quick links</h2><ul>';
echo '<li><a href="?company_slug=ultimate&amp;search=EXECUTIVE">Products: EXECUTIVE</a></li>';
echo '<li><a href="?company_slug=ultimate&amp;product_id=8">Product #8</a></li>';
echo '<li><a href="?company_slug=ultimate&amp;order_id=252">Order #252</a></li>';
echo '<li><a href="' . dspi_h($stockBasePath) . 'product_image.php?product_id=8&amp;size=medium&amp;company_slug=ultimate" target="_blank">product_image.php test (id=8)</a></li>';
echo '</ul>';

echo '</body></html>';
