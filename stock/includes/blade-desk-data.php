<?php
/**
 * Page-data builders for Stock Laravel Blade React shells (pilot desks).
 * Returns the `data` arrays embedded in window.__STOCK_PAGE__ (no HTML).
 */

declare(strict_types=1);

if (!function_exists('stock_blade_bootstrap')) {
    /**
     * Ensure stock $pdo / paths / helpers are loaded.
     *
     * @return PDO
     */
    function stock_blade_bootstrap(): PDO
    {
        $stockRoot = dirname(__DIR__);
        if (!defined('STOCK_BLADE_BOOTSTRAPPED')) {
            require_once $stockRoot . '/config/database.php';
            require_once $stockRoot . '/config/functions.php';
            require_once $stockRoot . '/config/paths.php';
            define('STOCK_BLADE_BOOTSTRAPPED', true);
        }

        global $pdo;
        if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        }
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Stock database connection unavailable.');
        }

        if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
            $_GET['module'] = 'stocks';
        }
        $_SESSION['active_module'] = 'stocks';

        return $pdo;
    }
}

if (!function_exists('stock_blade_asset_base')) {
    function stock_blade_asset_base(): string
    {
        if (isset($GLOBALS['stockBasePath']) && (string) $GLOBALS['stockBasePath'] !== '') {
            $base = rtrim((string) $GLOBALS['stockBasePath'], '/') . '/';
        } elseif (function_exists('app_url')) {
            $base = rtrim((string) app_url('/stock'), '/') . '/';
        } else {
            $base = '/stock/';
        }
        if (strpos($base, '/ultimate/stock') !== false) {
            $base = (string) preg_replace('#/ultimate/stock#', '/stock', $base, 1);
        } elseif (strpos($base, '/roadmaster/stock') !== false) {
            $base = (string) preg_replace('#/roadmaster/stock#', '/stock', $base, 1);
        }

        return $base;
    }
}

if (!function_exists('stock_blade_is_ultimate')) {
    function stock_blade_is_ultimate(): bool
    {
        return (
            (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
            || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
        );
    }
}

if (!function_exists('stock_blade_desk_url')) {
    /**
     * @param array<string,scalar|null> $query
     */
    function stock_blade_desk_url(string $desk, array $query = []): string
    {
        if (function_exists('stock_desk_url')) {
            return stock_desk_url($desk, $query);
        }
        $base = stock_blade_asset_base();
        $map = [
            'dashboard' => '',
            'products' => 'modules/products/index.php',
            'product-create' => 'modules/products/add.php',
            'product-view' => 'modules/products/view.php',
            'product-edit' => 'modules/products/edit.php',
            'brands' => 'modules/brands/index.php',
            'purchases' => 'modules/purchases/index.php',
            'purchase-create' => 'modules/purchases/domestic_create.php',
            'purchase-view' => 'modules/purchases/view_po.php',
            'suppliers' => 'modules/suppliers/index.php',
            'shipments' => 'modules/shipments/index.php',
            'movements' => 'modules/stock/movements.php',
            'uploads' => 'modules/uploads/index.php',
        ];
        $path = $map[$desk] ?? ('modules/' . $desk . '/index.php');
        $url = $base . ltrim($path, '/');
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }
}

if (!function_exists('stock_blade_dashboard_data')) {
    /**
     * @return array<string,mixed>
     */
    function stock_blade_dashboard_data(): array
    {
        $pdo = stock_blade_bootstrap();
        $base = stock_blade_asset_base();
        $stockRoot = dirname(__DIR__);
        require_once $stockRoot . '/classes/StockStatistics.php';

        $total_products = 0;
        $low_stock_count = 0;
        $out_of_stock = 0;
        $in_stock = 0;
        $low_stock_items = [];
        $pending_purchases = 0;
        $total_suppliers = 0;
        $today_purchases_total = 0.0;
        $in_transit_count = 0;
        $top_sellers = [];
        $total_outgoing_units = 0;
        $pow = false;
        $recent_purchases = [];
        $products_growth_pct = null;
        $company_display = (string) ($_SESSION['company_name'] ?? 'Stock');

        try {
            $stats = new StockStatistics($pdo);
            $mainImageSql = function_exists('stock_product_main_image_sql')
                ? stock_product_main_image_sql($pdo, 'p')
                : 'p.main_image';

            $total_products = (int) ($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);
            $low_stock_count = (int) ($pdo->query(
                "SELECT COUNT(*) FROM products p
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE COALESCE(s.quantity, 0) <= p.reorder_level AND COALESCE(s.quantity, 0) > 0"
            )->fetchColumn() ?: 0);
            $out_of_stock = (int) ($pdo->query(
                "SELECT COUNT(*) FROM products p
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE COALESCE(s.quantity, 0) <= 0"
            )->fetchColumn() ?: 0);
            $in_stock = (int) ($pdo->query(
                "SELECT COUNT(*) FROM products p
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE COALESCE(s.quantity, 0) > p.reorder_level"
            )->fetchColumn() ?: 0);

            $stmtLow = $pdo->query(
                "SELECT p.id, p.name, p.product_code, COALESCE(s.quantity, 0) AS quantity, p.reorder_level,
                        ({$mainImageSql}) AS resolved_main_image
                 FROM products p
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE COALESCE(s.quantity, 0) <= p.reorder_level
                 ORDER BY quantity ASC
                 LIMIT 8"
            );
            $low_stock_items = $stmtLow ? ($stmtLow->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            $pending_purchases = (int) ($pdo->query("SELECT COUNT(*) FROM purchases WHERE status = 'Pending'")->fetchColumn() ?: 0);
            $total_suppliers = (int) ($pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() ?: 0);

            $today = date('Y-m-d');
            $stmtToday = $pdo->prepare(
                "SELECT SUM(total_amount) FROM purchases
                 WHERE DATE(created_at) = ? AND status NOT IN ('Cancelled', 'Draft')"
            );
            $stmtToday->execute([$today]);
            $today_purchases_total = (float) ($stmtToday->fetchColumn() ?: 0);

            try {
                $in_transit_count = (int) ($pdo->query(
                    "SELECT COUNT(*) FROM shipments WHERE status IN ('shipped', 'in_transit', 'on_way')"
                )->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $in_transit_count = 0;
            }

            try {
                $top_sellers = $pdo->query(
                    "SELECT p.id, p.name, ({$mainImageSql}) AS resolved_main_image,
                            SUM(soi.quantity) AS total_qty
                     FROM sales_order_items soi
                     JOIN products p ON soi.product_id = p.id
                     JOIN sales_orders so ON soi.order_id = so.id
                     WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY p.id ORDER BY total_qty DESC LIMIT 3"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $total_outgoing_units = (int) array_sum(array_column($top_sellers, 'total_qty'));
            } catch (Throwable $e) {
                $top_sellers = [];
                $total_outgoing_units = 0;
            }

            try {
                $pow = $pdo->query(
                    "SELECT p.id, p.name, ({$mainImageSql}) AS resolved_main_image,
                            SUM(soi.quantity) AS total_qty
                     FROM sales_order_items soi
                     JOIN products p ON soi.product_id = p.id
                     JOIN sales_orders so ON soi.order_id = so.id
                     WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY p.id ORDER BY total_qty DESC LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC) ?: false;
            } catch (Throwable $e) {
                $pow = false;
            }

            try {
                $mainImageSqlPr = function_exists('stock_product_main_image_sql')
                    ? stock_product_main_image_sql($pdo, 'pr')
                    : 'pr.main_image';
                $recent_purchases = $pdo->query(
                    "SELECT p.id, p.created_at, p.total_amount, p.status,
                            s.name AS supplier_name, pr.id AS product_id, pr.name AS product_name,
                            pr.product_code, ({$mainImageSqlPr}) AS resolved_main_image
                     FROM purchases p
                     LEFT JOIN suppliers s ON p.supplier_id = s.id
                     LEFT JOIN products pr ON p.product_id = pr.id
                     ORDER BY p.created_at DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $recent_purchases = [];
            }

            try {
                $endLastMonth = date('Y-m-t', strtotime('last month'));
                $prevTotal = (int) $stats->getCumulativeProductCountAsOf($endLastMonth);
                if ($prevTotal > 0) {
                    $products_growth_pct = (int) round((($total_products - $prevTotal) / $prevTotal) * 100);
                }
            } catch (Throwable $e) {
                $products_growth_pct = null;
            }

            if (function_exists('getCompanySettings')) {
                $settings = getCompanySettings($pdo);
                if (!empty($settings['company_name'])) {
                    $company_display = (string) $settings['company_name'];
                }
            }
        } catch (Throwable $e) {
            error_log('stock_blade_dashboard_data: ' . $e->getMessage());
        }

        $imgUrl = static function ($productId, $filename) use ($base) {
            $productId = (int) $productId;
            $filename = trim((string) $filename);
            if ($productId <= 0 || !function_exists('stock_product_list_image_url')) {
                return '';
            }

            return (string) stock_product_list_image_url($productId, $filename, 'medium', $base);
        };

        $lowStockPayload = [];
        foreach ($low_stock_items as $item) {
            $pid = (int) ($item['id'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $lowStockPayload[] = [
                'id' => $pid,
                'name' => (string) ($item['name'] ?? ''),
                'product_code' => (string) ($item['product_code'] ?? ''),
                'quantity' => $qty,
                'reorder_level' => (float) ($item['reorder_level'] ?? 0),
                'status' => $qty <= 0 ? 'out' : 'low',
                'image_url' => $imgUrl($pid, $item['resolved_main_image'] ?? ''),
            ];
        }

        $recentPayload = [];
        foreach ($recent_purchases as $rp) {
            $productId = (int) ($rp['product_id'] ?? 0);
            $recentPayload[] = [
                'id' => (int) ($rp['id'] ?? 0),
                'supplier_name' => (string) ($rp['supplier_name'] ?? ''),
                'product_name' => (string) ($rp['product_name'] ?? ''),
                'product_code' => (string) ($rp['product_code'] ?? ''),
                'total_amount' => (float) ($rp['total_amount'] ?? 0),
                'status' => (string) ($rp['status'] ?? ''),
                'created_at' => (string) ($rp['created_at'] ?? ''),
                'image_url' => $productId > 0 ? $imgUrl($productId, $rp['resolved_main_image'] ?? '') : '',
                'product_id' => $productId > 0 ? $productId : null,
            ];
        }

        $recentPurchaseProducts = [];
        $seenProductIds = [];
        try {
            $purchaseIds = [];
            foreach ($recent_purchases as $rp) {
                $pid = (int) ($rp['id'] ?? 0);
                if ($pid > 0) {
                    $purchaseIds[] = $pid;
                }
            }
            $purchaseIds = array_values(array_unique($purchaseIds));
            if ($purchaseIds !== []) {
                $mainImageSqlFan = function_exists('stock_product_main_image_sql')
                    ? stock_product_main_image_sql($pdo, 'pr')
                    : 'pr.main_image';
                $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
                $fanSql = "SELECT pr.id, pr.name, pr.product_code, ({$mainImageSqlFan}) AS resolved_main_image,
                                  MAX(p.created_at) AS last_bought, MAX(p.id) AS purchase_id
                           FROM purchase_items pi
                           INNER JOIN products pr ON pr.id = pi.product_id
                           INNER JOIN purchases p ON p.id = pi.purchase_id
                           WHERE pi.purchase_id IN ({$placeholders})
                           GROUP BY pr.id, pr.name, pr.product_code
                           ORDER BY last_bought DESC
                           LIMIT 7";
                $fanStmt = $pdo->prepare($fanSql);
                $fanStmt->execute($purchaseIds);
                foreach ($fanStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $prId = (int) ($row['id'] ?? 0);
                    if ($prId <= 0 || isset($seenProductIds[$prId])) {
                        continue;
                    }
                    $seenProductIds[$prId] = true;
                    $recentPurchaseProducts[] = [
                        'id' => $prId,
                        'name' => (string) ($row['name'] ?? ''),
                        'product_code' => (string) ($row['product_code'] ?? ''),
                        'image_url' => $imgUrl($prId, $row['resolved_main_image'] ?? ''),
                        'purchase_id' => (int) ($row['purchase_id'] ?? 0) ?: null,
                    ];
                }
            }
        } catch (Throwable $e) {
            $recentPurchaseProducts = [];
        }
        if ($recentPurchaseProducts === []) {
            foreach ($recentPayload as $rp) {
                $prId = (int) ($rp['product_id'] ?? 0);
                if ($prId <= 0 || isset($seenProductIds[$prId])) {
                    continue;
                }
                $seenProductIds[$prId] = true;
                $recentPurchaseProducts[] = [
                    'id' => $prId,
                    'name' => (string) ($rp['product_name'] ?? ''),
                    'product_code' => (string) ($rp['product_code'] ?? ''),
                    'image_url' => (string) ($rp['image_url'] ?? ''),
                    'purchase_id' => (int) ($rp['id'] ?? 0) ?: null,
                ];
                if (count($recentPurchaseProducts) >= 7) {
                    break;
                }
            }
        }

        $sellersPayload = [];
        foreach ($top_sellers as $seller) {
            $sid = (int) ($seller['id'] ?? 0);
            $sellersPayload[] = [
                'id' => $sid,
                'name' => (string) ($seller['name'] ?? ''),
                'quantity' => (float) ($seller['total_qty'] ?? 0),
                'image_url' => $imgUrl($sid, $seller['resolved_main_image'] ?? ''),
            ];
        }

        $powPayload = null;
        if (is_array($pow) && !empty($pow['id'])) {
            $powId = (int) $pow['id'];
            $powPayload = [
                'id' => $powId,
                'name' => (string) ($pow['name'] ?? ''),
                'quantity' => (float) ($pow['total_qty'] ?? 0),
                'image_url' => $imgUrl($powId, $pow['resolved_main_image'] ?? ''),
            ];
        }

        return [
            'company_name' => (string) $company_display,
            'date_label' => date('l, j M Y'),
            'total_products' => $total_products,
            'in_stock' => $in_stock,
            'low_stock' => $low_stock_count,
            'out_of_stock' => $out_of_stock,
            'attention_count' => $low_stock_count + $out_of_stock + $pending_purchases + $in_transit_count,
            'pending_purchases' => $pending_purchases,
            'total_suppliers' => $total_suppliers,
            'today_purchases_total' => $today_purchases_total,
            'in_transit_count' => $in_transit_count,
            'products_growth_pct' => $products_growth_pct,
            'outgoing_units' => $total_outgoing_units,
            'low_stock_items' => $lowStockPayload,
            'recent_purchases' => $recentPayload,
            'recent_purchase_products' => $recentPurchaseProducts,
            'top_sellers' => $sellersPayload,
            'product_of_week' => $powPayload,
            'links' => [
                'products' => stock_blade_desk_url('products'),
                'products_low' => stock_blade_desk_url('products', ['filter' => 'low_stock']),
                'add_product' => stock_blade_desk_url('product-create'),
                'purchases' => stock_blade_desk_url('purchases'),
                'purchase_create' => stock_blade_desk_url('purchase-create'),
                'suppliers' => stock_blade_desk_url('suppliers'),
                'shipments' => stock_blade_desk_url('shipments'),
                'movements' => stock_blade_desk_url('movements'),
                'uploads' => stock_blade_desk_url('uploads'),
                'product_view' => rtrim(stock_blade_desk_url('product-view'), '/') . '?id=',
                'purchase_view' => rtrim(stock_blade_desk_url('purchase-view'), '/') . '?id=',
            ],
        ];
    }
}

if (!function_exists('stock_blade_products_list_data')) {
    /**
     * @return array<string,mixed>
     */
    function stock_blade_products_list_data(): array
    {
        $pdo = stock_blade_bootstrap();
        $base = stock_blade_asset_base();
        $searchInc = dirname(__DIR__) . '/modules/products/includes/product_search.inc.php';
        if (is_file($searchInc)) {
            require_once $searchInc;
        }

        $showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

        $catList = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $supList = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $brandList = [];
        try {
            $brandList = $pdo->query('SELECT id, name FROM brands ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $brandList = [];
        }

        $dupSql = "SELECT product_code, name, COUNT(*) AS cnt
                   FROM products
                   WHERE product_code != ''
                   GROUP BY product_code, name
                   HAVING cnt > 1";
        $duplicatesFound = $pdo->query($dupSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalDuplicateRows = count($duplicatesFound);

        $isFilteringDuplicates = isset($_GET['show_duplicates']) && (string) $_GET['show_duplicates'] === '1';
        $createdId = isset($_GET['created_id']) ? (int) $_GET['created_id'] : 0;

        $whereClause = 'WHERE 1=1';
        $params = [];

        $hasItemType = false;
        $hasProductImages = false;
        try {
            $pdo->query('SELECT item_type FROM products LIMIT 1');
            $hasItemType = true;
        } catch (PDOException $e) {
        }
        try {
            $pdo->query('SELECT image_name FROM product_images LIMIT 1');
            $hasProductImages = true;
        } catch (PDOException $e) {
        }

        $filterSearch = isset($_GET['search']) && function_exists('stock_products_normalize_query')
            ? stock_products_normalize_query((string) $_GET['search'])
            : trim((string) ($_GET['search'] ?? ''));
        $filterCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
        $filterItemType = isset($_GET['item_type']) ? trim((string) $_GET['item_type']) : '';
        $filterSupplier = isset($_GET['supplier']) ? trim((string) $_GET['supplier']) : '';
        $filterBrand = isset($_GET['brand']) ? trim((string) $_GET['brand']) : '';

        if ($filterSearch !== '' && function_exists('stock_products_build_search_clause')) {
            $hasBrandCol = false;
            try {
                $pdo->query('SELECT brand FROM products LIMIT 1');
                $hasBrandCol = true;
            } catch (PDOException $e) {
            }
            [$searchSql, $searchParams] = stock_products_build_search_clause($filterSearch, 'p', $hasBrandCol);
            if ($searchSql !== '') {
                $whereClause .= ' AND ' . $searchSql;
                foreach ($searchParams as $sp) {
                    $params[] = $sp;
                }
            }
        }
        if ($filterCategory !== '') {
            $whereClause .= ' AND p.category_id = ?';
            $params[] = $filterCategory;
        }
        if ($hasItemType && $filterItemType !== '') {
            $whereClause .= ' AND p.item_type = ?';
            $params[] = $filterItemType;
        }
        if ($filterSupplier !== '') {
            $whereClause .= ' AND p.supplier_id = ?';
            $params[] = $filterSupplier;
        }
        if ($filterBrand !== '') {
            $whereClause .= ' AND p.brand = ?';
            $params[] = $filterBrand;
        }
        if ($isFilteringDuplicates) {
            $whereClause .= " AND (p.product_code, p.name) IN (
                SELECT product_code, name FROM products
                WHERE product_code != ''
                GROUP BY product_code, name
                HAVING COUNT(*) > 1
            )";
        }

        $mainImageExpr = $hasProductImages
            ? "COALESCE(NULLIF(TRIM(p.main_image), ''), (
                    SELECT pi.image_name
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
               ))"
            : 'p.main_image';
        $itemTypeSelect = $hasItemType ? 'p.item_type' : "'general' AS item_type";

        $orderBySql = 'p.id DESC';
        $orderParams = [];
        if ($filterSearch !== '' && function_exists('stock_products_search_order_sql')) {
            [$ordSql, $ordParams] = stock_products_search_order_sql($filterSearch, 'p');
            $orderBySql = $ordSql;
            $orderParams = $ordParams;
        }
        if ($createdId > 0) {
            $orderBySql = "(p.id = {$createdId}) DESC, " . $orderBySql;
        }

        $filterParams = $params;
        $queryParams = array_merge($filterParams, $orderParams);

        $perPage = 48;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $totalFiltered = 0;
        try {
            $countSql = "SELECT COUNT(*) FROM (
                SELECT p.id
                FROM products p
                {$whereClause}
                GROUP BY p.id
            ) stock_prod_count";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($filterParams);
            $totalFiltered = (int) ($countStmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            $totalFiltered = 0;
        }
        $totalPages = max(1, (int) ceil(max(0, $totalFiltered) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $lowStockCount = 0;
        $outOfStockCount = 0;
        try {
            $kpiSql = "SELECT
                    SUM(CASE WHEN q <= 0 THEN 1 ELSE 0 END) AS out_cnt,
                    SUM(CASE WHEN q > 0 AND q <= r THEN 1 ELSE 0 END) AS low_cnt
                FROM (
                    SELECT COALESCE(MAX(st.quantity), 0) AS q, COALESCE(p.reorder_level, 0) AS r
                    FROM products p
                    LEFT JOIN stock st ON p.id = st.product_id
                    {$whereClause}
                    GROUP BY p.id
                ) stock_kpi";
            $kpiStmt = $pdo->prepare($kpiSql);
            $kpiStmt->execute($filterParams);
            $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $outOfStockCount = (int) ($kpi['out_cnt'] ?? 0);
            $lowStockCount = (int) ($kpi['low_cnt'] ?? 0);
        } catch (PDOException $e) {
            $lowStockCount = 0;
            $outOfStockCount = 0;
        }

        $sql = "SELECT p.id, p.name, p.product_code, p.brand, p.currency, p.unit_price, p.buying_price,
                       p.reorder_level, p.main_image, {$itemTypeSelect},
                       {$mainImageExpr} AS resolved_main_image,
                       c.name AS category_name, s.name AS supplier_name,
                       st.quantity, st.location
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                LEFT JOIN stock st ON p.id = st.product_id
                {$whereClause}
                GROUP BY p.id
                ORDER BY {$orderBySql}
                LIMIT {$perPage} OFFSET {$offset}";

        $products = [];
        $dbError = '';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $rows = [];
            $dbError = $e->getMessage();
        }

        $imported = (int) ($_GET['imported'] ?? 0);
        $updated = (int) ($_GET['updated'] ?? 0);
        $totalGlow = $imported + $updated;
        $glowCount = 0;

        foreach ($rows as $row) {
            $filename = !empty($row['resolved_main_image'])
                ? (string) $row['resolved_main_image']
                : (string) ($row['main_image'] ?? '');
            $filename = trim($filename);
            $imageUrl = '';
            if ($filename !== '' && function_exists('stock_product_list_image_url')) {
                $imageUrl = stock_product_list_image_url((int) $row['id'], $filename, 'medium', $base);
            }
            $qty = (int) ($row['quantity'] ?? 0);
            $reorder = (int) ($row['reorder_level'] ?? 0);
            $isRecent = (($_GET['bulk_import'] ?? '') === 'success' && $glowCount < $totalGlow);
            if ($isRecent) {
                $glowCount++;
            }
            $isCreated = $createdId > 0 && (int) $row['id'] === $createdId;
            $products[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'USD'),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'buying_price' => (float) ($row['buying_price'] ?? 0),
                'reorder_level' => $reorder,
                'item_type' => (string) ($row['item_type'] ?? 'general'),
                'main_image' => $filename,
                'image_url' => $imageUrl,
                'category_name' => $row['category_name'] ?? null,
                'supplier_name' => $row['supplier_name'] ?? null,
                'quantity' => $qty,
                'location' => $row['location'] ?? null,
                'is_recent' => $isRecent || $isCreated,
            ];
        }

        $totalProductsAll = 0;
        try {
            $totalProductsAll = (int) ($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            $totalProductsAll = $totalFiltered;
        }

        $missingImagesCount = 0;
        $missingImageSamples = [];
        try {
            $missWhere = $hasProductImages
                ? "WHERE TRIM(IFNULL(p.main_image, '')) = ''
                     AND NOT EXISTS (
                        SELECT 1 FROM product_images pi
                        WHERE pi.product_id = p.id
                          AND TRIM(IFNULL(pi.image_name, '')) <> ''
                     )"
                : "WHERE TRIM(IFNULL(p.main_image, '')) = ''";
            $missingImagesCount = (int) ($pdo->query(
                "SELECT COUNT(*) FROM products p {$missWhere}"
            )->fetchColumn() ?: 0);
            $missSampleStmt = $pdo->query(
                "SELECT p.id, p.name, p.product_code FROM products p {$missWhere} ORDER BY p.id DESC LIMIT 8"
            );
            $missingImageSamples = $missSampleStmt
                ? array_map(static function ($mrow) {
                    return [
                        'id' => (int) ($mrow['id'] ?? 0),
                        'name' => (string) ($mrow['name'] ?? ''),
                        'product_code' => (string) ($mrow['product_code'] ?? ''),
                    ];
                }, $missSampleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [])
                : [];
        } catch (Throwable $e) {
            $missingImagesCount = 0;
            $missingImageSamples = [];
        }

        return [
            'products' => $products,
            'categories' => array_map(static function ($c) {
                return ['id' => (int) $c['id'], 'name' => (string) $c['name']];
            }, $catList),
            'suppliers' => array_map(static function ($s) {
                return ['id' => (int) $s['id'], 'name' => (string) $s['name']];
            }, $supList),
            'brands' => array_map(static function ($b) {
                return [
                    'id' => isset($b['id']) ? (int) $b['id'] : 0,
                    'name' => (string) ($b['name'] ?? ''),
                ];
            }, $brandList),
            'hasItemType' => $hasItemType,
            'showCost' => $showCost,
            'baseUrl' => $base,
            'searchApiUrl' => $base . 'modules/products/api/search.php',
            'uploadsUrl' => stock_blade_desk_url('uploads', ['folder' => 'uploads']),
            'urls' => [
                'list' => stock_blade_desk_url('products'),
                'add' => stock_blade_desk_url('product-create'),
                'view' => rtrim(stock_blade_desk_url('product-view'), '/') . '?id=',
                'edit' => rtrim(stock_blade_desk_url('product-edit'), '/') . '?id=',
            ],
            'filterSearch' => $filterSearch,
            'filterCategory' => $filterCategory,
            'filterItemType' => $filterItemType,
            'filterSupplier' => $filterSupplier,
            'filterBrand' => $filterBrand,
            'totalDuplicateRows' => $totalDuplicateRows,
            'isFilteringDuplicates' => $isFilteringDuplicates,
            'createdId' => $createdId,
            'bulkImportSuccess' => (($_GET['bulk_import'] ?? '') === 'success'),
            'imported' => $imported,
            'updated' => $updated,
            'created' => (($_GET['created'] ?? '') === '1'),
            'dbError' => $dbError,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalFiltered,
                'total_pages' => $totalPages,
            ],
            'stats' => [
                'total_count' => $totalProductsAll,
                'listed_count' => $totalFiltered,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'missing_images_count' => $missingImagesCount,
            ],
            'missingImages' => [
                'count' => $missingImagesCount,
                'samples' => $missingImageSamples,
            ],
        ];
    }
}

if (!function_exists('stock_blade_brands_list_data')) {
    /**
     * @return array<string,mixed>
     */
    function stock_blade_brands_list_data(): array
    {
        $pdo = stock_blade_bootstrap();
        $base = stock_blade_asset_base();
        $isUltimate = stock_blade_is_ultimate();

        $brandCols = [];
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM `brands`') as $row) {
                if (!empty($row['Field'])) {
                    $brandCols[$row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            $brandCols = [];
        }

        $brands = [];
        try {
            $brands = $pdo->query('SELECT * FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $brands = [];
        }

        $brandPayload = [];
        foreach ($brands as $row) {
            $logo = (string) ($row['logo'] ?? '');
            $logoUrl = $logo !== '' && function_exists('stock_brand_image_url')
                ? stock_brand_image_url($logo)
                : '';
            $brandPayload[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'brand_type' => (string) ($row['brand_type'] ?? ($isUltimate ? 'general' : 'spare_part')),
                'logo' => $logo,
                'logo_url' => $logoUrl,
                'meta_title' => (string) ($row['meta_title'] ?? ''),
                'meta_description' => (string) ($row['meta_description'] ?? ''),
            ];
        }

        $toast = '';
        if (isset($_GET['delete']) && $_GET['delete'] === 'success') {
            $toast = 'deleted';
        } elseif (isset($_GET['update']) && $_GET['update'] === 'success') {
            $toast = 'updated';
        }

        return [
            'isUltimate' => $isUltimate,
            'brands' => $brandPayload,
            'hasBrandType' => !empty($brandCols['brand_type']),
            'hasLogo' => !empty($brandCols['logo']),
            'hasMeta' => !empty($brandCols['meta_title']) || !empty($brandCols['meta_description']),
            'baseUrl' => $base,
            'formAction' => stock_blade_desk_url('brands'),
            'deleteUrl' => $base . 'modules/brands/delete.php',
            'toast' => $toast,
            'emptyLottie' => function_exists('app_url')
                ? app_url('/assets/animations/nothing.lottie')
                : '/assets/animations/nothing.lottie',
        ];
    }
}

if (!function_exists('stock_blade_product_create_data')) {
    /**
     * @return array<string,mixed>
     */
    function stock_blade_product_create_data(): array
    {
        $pdo = stock_blade_bootstrap();
        $base = stock_blade_asset_base();
        $isUltimate = stock_blade_is_ultimate();
        $requireProductImage = $isUltimate
            || (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/roadmaster/') !== false)
            || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'roadmaster');
        $showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);

        $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $brands = [];
        try {
            $brands = $pdo->query('SELECT id, name, brand_type FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            try {
                $brands = $pdo->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e2) {
                $brands = [];
            }
        }

        $year = date('Y');
        $previewCode = "PRD-{$year}-001";
        $previewTruckCode = "TRK-{$year}-001";
        try {
            $stmtMax = $pdo->prepare(
                "SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?"
            );
            $stmtMax->execute(["PRD-{$year}-%"]);
            $maxNum = $stmtMax->fetchColumn();
            $nextNum = $maxNum ? ((int) $maxNum + 1) : 1;
            $previewCode = "PRD-{$year}-" . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

            $stmtMax->execute(["TRK-{$year}-%"]);
            $maxNum = $stmtMax->fetchColumn();
            $nextNum = $maxNum ? ((int) $maxNum + 1) : 1;
            $previewTruckCode = "TRK-{$year}-" . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            // keep defaults
        }

        return [
            'isUltimate' => $isUltimate,
            'requireProductImage' => $requireProductImage,
            'showCost' => $showCost,
            'categories' => array_map(static function ($c) {
                return ['id' => (int) $c['id'], 'name' => (string) $c['name']];
            }, $categories),
            'suppliers' => array_map(static function ($s) {
                return ['id' => (int) $s['id'], 'name' => (string) $s['name']];
            }, $suppliers),
            'brands' => array_map(static function ($b) {
                return [
                    'id' => (int) ($b['id'] ?? 0),
                    'name' => (string) ($b['name'] ?? ''),
                    'brand_type' => (string) ($b['brand_type'] ?? ''),
                ];
            }, $brands),
            'useBrandFreeText' => count($brands) === 0,
            'previewCode' => $previewCode,
            'previewTruckCode' => $previewTruckCode,
            'currencies' => ['TZS', 'USD', 'EUR'],
            'defaultCurrency' => 'TZS',
            'listUrl' => stock_blade_desk_url('products'),
            'createApiUrl' => $base . 'modules/products/api/create-product.php',
            'baseUrl' => $base,
        ];
    }
}
