<?php

declare(strict_types=1);

/**
 * Store / warehouse report helpers used alongside report-domain-store.php.
 * Purchase/shipment queries, breakdowns, and deterministic prose sections.
 */

function reportDomainStorePurchaseStats(PDO $pdo, array $filters): array
{
    $empty = [
        'purchase_count' => 0,
        'purchase_value' => 0.0,
        'pending_po_count' => 0,
        'pending_po_value' => 0.0,
        'supplier_count' => 0,
    ];

    if (!tableExists('purchases', $pdo)) {
        return $empty;
    }

    $dateExpr = reportDomainStorePurchaseDateExpr($pdo);
    $amountExpr = columnExists('purchases', 'total_amount', $pdo)
        ? 'COALESCE(p.total_amount, 0)'
        : '0';
    $pendingStatus = "LOWER(TRIM(COALESCE(p.status, ''))) NOT IN ('received','cancelled','canceled','completed','closed')";

    try {
        $sql = "SELECT COUNT(*) AS purchase_count,
                       COALESCE(SUM({$amountExpr}), 0) AS purchase_value,
                       COUNT(DISTINCT p.supplier_id) AS supplier_count
                FROM purchases p
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
        $st = $pdo->prepare($sql);
        $st->execute([$filters['start_date'], $filters['end_date']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $pendingCount = 0;
    $pendingValue = 0.0;
    try {
        $sqlP = "SELECT COUNT(*) AS pending_po_count,
                        COALESCE(SUM({$amountExpr}), 0) AS pending_po_value
                 FROM purchases p
                 WHERE {$pendingStatus}";
        $stP = $pdo->query($sqlP);
        $pRow = $stP ? ($stP->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $pendingCount = (int) ($pRow['pending_po_count'] ?? 0);
        $pendingValue = (float) ($pRow['pending_po_value'] ?? 0);
    } catch (Throwable $e) {
        // keep zeros
    }

    return [
        'purchase_count' => (int) ($row['purchase_count'] ?? 0),
        'purchase_value' => (float) ($row['purchase_value'] ?? 0),
        'pending_po_count' => $pendingCount,
        'pending_po_value' => $pendingValue,
        'supplier_count' => (int) ($row['supplier_count'] ?? 0),
    ];
}

function reportDomainStoreDeliveryStats(PDO $pdo, array $filters): array
{
    $empty = [
        'pending_delivery_count' => 0,
        'delayed_delivery_count' => 0,
    ];

    if (!tableExists('shipments', $pdo)) {
        return $empty;
    }

    $dateExpr = reportDomainStoreShipmentDateExpr($pdo);
    $pendingIn = "'pending','confirmed','in_transit','arrived_at_port','in_customs'";
    $select = "SELECT
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(sh.status, ''))) IN ({$pendingIn}) THEN 1 ELSE 0 END) AS pending_delivery_count,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(sh.status, ''))) = 'delayed' THEN 1 ELSE 0 END) AS delayed_delivery_count
               FROM shipments sh";

    try {
        $st = $pdo->prepare($select . " WHERE DATE({$dateExpr}) BETWEEN ? AND ?");
        $st->execute([$filters['start_date'], $filters['end_date']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'pending_delivery_count' => (int) ($row['pending_delivery_count'] ?? 0),
            'delayed_delivery_count' => (int) ($row['delayed_delivery_count'] ?? 0),
        ];
    } catch (Throwable $e) {
        // Fall through: open shipments overall (no period filter).
    }

    try {
        $st = $pdo->query(
            $select . " WHERE LOWER(TRIM(COALESCE(sh.status, ''))) IN ({$pendingIn}, 'delayed')"
        );
        $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'pending_delivery_count' => (int) ($row['pending_delivery_count'] ?? 0),
            'delayed_delivery_count' => (int) ($row['delayed_delivery_count'] ?? 0),
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

function reportDomainStorePeriodComparisonRows(array $kpis, array $prevKpis, array $filters, array $prevFilters): array
{
    $curPeriod = salesReportsFormatPeriod(
        (string) ($filters['start_date'] ?? ''),
        (string) ($filters['end_date'] ?? '')
    );
    $prevPeriod = salesReportsFormatPeriod(
        (string) ($prevFilters['start_date'] ?? ''),
        (string) ($prevFilters['end_date'] ?? '')
    );

    $metrics = [
        ['label' => 'Products in stock', 'key' => 'total_products', 'money' => false],
        ['label' => 'Units on hand', 'key' => 'total_units', 'money' => false],
        ['label' => 'Inventory value', 'key' => 'inventory_value', 'money' => true],
        ['label' => 'Low stock items', 'key' => 'low_stock_count', 'money' => false],
        ['label' => 'Out of stock items', 'key' => 'out_of_stock_count', 'money' => false],
        ['label' => 'Stock movements', 'key' => 'movement_count', 'money' => false],
        ['label' => 'Purchase orders', 'key' => 'purchase_count', 'money' => false],
        ['label' => 'Purchase value', 'key' => 'purchase_value', 'money' => true],
        ['label' => 'Pending deliveries', 'key' => 'pending_delivery_count', 'money' => false],
    ];

    $rows = [];
    foreach ($metrics as $m) {
        $cur = (float) ($kpis[$m['key']] ?? 0);
        $prev = (float) ($prevKpis[$m['key']] ?? 0);
        $rows[] = [
            'metric' => $m['label'],
            'current_period' => $curPeriod,
            'previous_period' => $prevPeriod,
            'current' => $cur,
            'previous' => $prev,
            'current_display' => $m['money'] ? salesReportsFormatMoney($cur) : number_format($cur, 0),
            'previous_display' => $m['money'] ? salesReportsFormatMoney($prev) : number_format($prev, 0),
            'change' => reportDomainStoreChangeLabel($cur, $prev),
            'is_money' => $m['money'],
        ];
    }

    return $rows;
}

function reportDomainStoreStockStatusBreakdown(PDO $pdo, array $filters): array
{
    $ctx = reportDomainStoreInventoryContext($pdo);
    if ($ctx === null) {
        return [];
    }

    $where = ' WHERE 1=1';
    $params = [];
    if (!empty($filters['category_id'])) {
        if ($ctx['mode'] === 'stocks_items' && columnExists('stocks_items', 'category_id', $pdo)) {
            $where .= ' AND si.category_id = ?';
            $params[] = (int) $filters['category_id'];
        } elseif ($ctx['mode'] === 'products_stock') {
            $where .= ' AND p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
    }

    $sql = "SELECT
                SUM(CASE WHEN COALESCE({$ctx['qty_col']}, 0) > {$ctx['reorder_col']} THEN 1 ELSE 0 END) AS normal_stock,
                SUM(CASE WHEN COALESCE({$ctx['qty_col']}, 0) <= {$ctx['reorder_col']} AND COALESCE({$ctx['qty_col']}, 0) > 0 THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN COALESCE({$ctx['qty_col']}, 0) <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                COUNT(DISTINCT {$ctx['id_col']}) AS total_products
            FROM {$ctx['from']}
            {$ctx['join']}
            {$where}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $normal = (int) ($row['normal_stock'] ?? 0);
    $low = (int) ($row['low_stock'] ?? 0);
    $out = (int) ($row['out_of_stock'] ?? 0);
    $total = (int) ($row['total_products'] ?? 0);
    if ($total <= 0) {
        $total = $normal + $low + $out;
    }

    $pct = static function (int $n, int $t): float {
        return $t > 0 ? round(($n / $t) * 100, 1) : 0.0;
    };

    return [
        [
            'status' => 'Normal Stock',
            'count' => $normal,
            'pct' => $pct($normal, $total),
        ],
        [
            'status' => 'Low Stock',
            'count' => $low,
            'pct' => $pct($low, $total),
        ],
        [
            'status' => 'Out of Stock',
            'count' => $out,
            'pct' => $pct($out, $total),
        ],
        [
            'status' => 'Reorder Required',
            'count' => $low + $out,
            'pct' => $pct($low + $out, $total),
        ],
    ];
}

function reportDomainStoreOutOfStock(PDO $pdo, array $filters): array
{
    $ctx = reportDomainStoreInventoryContext($pdo);
    if ($ctx === null) {
        return [];
    }

    $code = $ctx['code_col'] ?? $ctx['name_col'];
    $sql = "SELECT {$code} AS product_code,
                   {$ctx['name_col']} AS product_name,
                   COALESCE({$ctx['qty_col']}, 0) AS qty,
                   {$ctx['reorder_col']} AS reorder_level
            FROM {$ctx['from']}
            {$ctx['join']}
            WHERE COALESCE({$ctx['qty_col']}, 0) <= 0
            ORDER BY {$ctx['name_col']} ASC
            LIMIT 25";

    try {
        $st = $pdo->query($sql);

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStorePurchasesBySupplier(PDO $pdo, array $filters): array
{
    if (!tableExists('purchases', $pdo)) {
        return [];
    }

    $dateExpr = reportDomainStorePurchaseDateExpr($pdo);
    $amountExpr = columnExists('purchases', 'total_amount', $pdo)
        ? 'COALESCE(p.total_amount, 0)'
        : '0';

    $supplierJoin = '';
    $supplierName = "CONCAT('Supplier #', p.supplier_id)";
    if (tableExists('suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN suppliers s ON s.id = p.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', p.supplier_id))";
    } elseif (tableExists('stocks_suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN stocks_suppliers s ON s.id = p.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', p.supplier_id))";
    }

    $sql = "SELECT {$supplierName} AS supplier_name,
                   COUNT(*) AS purchase_count,
                   COALESCE(SUM({$amountExpr}), 0) AS purchase_value
            FROM purchases p
            {$supplierJoin}
            WHERE DATE({$dateExpr}) BETWEEN ? AND ?
            GROUP BY p.supplier_id, supplier_name
            ORDER BY purchase_value DESC
            LIMIT 15";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([$filters['start_date'], $filters['end_date']]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreTopPurchasedProducts(PDO $pdo, array $filters): array
{
    if (!tableExists('purchases', $pdo) || !tableExists('purchase_items', $pdo)) {
        return [];
    }

    $dateExpr = reportDomainStorePurchaseDateExpr($pdo);
    $qtyCol = columnExists('purchase_items', 'quantity', $pdo) ? 'pi.quantity' : '0';
    $priceCol = columnExists('purchase_items', 'unit_price', $pdo) ? 'pi.unit_price' : '0';
    $nameExpr = tableExists('products', $pdo)
        ? "COALESCE(pr.name, CONCAT('Product #', pi.product_id))"
        : "CONCAT('Product #', pi.product_id)";
    $productJoin = tableExists('products', $pdo)
        ? 'LEFT JOIN products pr ON pr.id = pi.product_id'
        : '';

    $sql = "SELECT {$nameExpr} AS product_name,
                   COALESCE(SUM({$qtyCol}), 0) AS qty,
                   COALESCE(SUM({$qtyCol} * {$priceCol}), 0) AS purchase_value
            FROM purchase_items pi
            INNER JOIN purchases p ON p.id = pi.purchase_id
            {$productJoin}
            WHERE DATE({$dateExpr}) BETWEEN ? AND ?
            GROUP BY pi.product_id, product_name
            ORDER BY qty DESC
            LIMIT 10";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([$filters['start_date'], $filters['end_date']]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStorePendingPurchases(PDO $pdo, array $filters): array
{
    if (!tableExists('purchases', $pdo)) {
        return [];
    }

    $dateExpr = reportDomainStorePurchaseDateExpr($pdo);
    $amountExpr = columnExists('purchases', 'total_amount', $pdo)
        ? 'COALESCE(p.total_amount, 0)'
        : '0';
    $poNo = columnExists('purchases', 'purchase_no', $pdo)
        ? 'p.purchase_no'
        : 'CAST(p.id AS CHAR)';
    $pendingStatus = "LOWER(TRIM(COALESCE(p.status, ''))) NOT IN ('received','cancelled','canceled','completed','closed')";

    $supplierJoin = '';
    $supplierName = "CONCAT('Supplier #', p.supplier_id)";
    if (tableExists('suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN suppliers s ON s.id = p.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', p.supplier_id))";
    } elseif (tableExists('stocks_suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN stocks_suppliers s ON s.id = p.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', p.supplier_id))";
    }

    $sql = "SELECT {$poNo} AS purchase_no,
                   {$supplierName} AS supplier_name,
                   p.status AS status,
                   {$amountExpr} AS amount,
                   DATE({$dateExpr}) AS purchase_date
            FROM purchases p
            {$supplierJoin}
            WHERE {$pendingStatus}
            ORDER BY {$dateExpr} DESC
            LIMIT 25";

    try {
        $st = $pdo->query($sql);

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStorePendingDeliveries(PDO $pdo, array $filters): array
{
    if (!tableExists('shipments', $pdo)) {
        return [];
    }

    $dateExpr = reportDomainStoreShipmentDateExpr($pdo);
    $pendingIn = "'pending','confirmed','in_transit','arrived_at_port','in_customs','delayed'";
    $shipNo = columnExists('shipments', 'shipment_number', $pdo)
        ? 'sh.shipment_number'
        : 'CAST(sh.id AS CHAR)';
    $tracking = columnExists('shipments', 'tracking_number', $pdo)
        ? 'sh.tracking_number'
        : "''";
    $valueExpr = columnExists('shipments', 'total_value', $pdo)
        ? 'COALESCE(sh.total_value, 0)'
        : '0';

    $supplierJoin = '';
    $supplierName = "CONCAT('Supplier #', sh.supplier_id)";
    if (tableExists('stocks_suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN stocks_suppliers s ON s.id = sh.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', sh.supplier_id))";
    } elseif (tableExists('suppliers', $pdo)) {
        $supplierJoin = 'LEFT JOIN suppliers s ON s.id = sh.supplier_id';
        $supplierName = "COALESCE(s.name, CONCAT('Supplier #', sh.supplier_id))";
    }

    $sql = "SELECT {$shipNo} AS shipment_number,
                   {$supplierName} AS supplier_name,
                   sh.status AS status,
                   {$tracking} AS tracking_number,
                   {$valueExpr} AS total_value,
                   DATE({$dateExpr}) AS shipment_date
            FROM shipments sh
            {$supplierJoin}
            WHERE LOWER(TRIM(COALESCE(sh.status, ''))) IN ({$pendingIn})
            ORDER BY
                CASE WHEN LOWER(TRIM(COALESCE(sh.status, ''))) = 'delayed' THEN 0 ELSE 1 END,
                {$dateExpr} DESC
            LIMIT 25";

    try {
        $st = $pdo->query($sql);

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreKeyActivities(PDO $pdo, array $filters, array $kpis): array
{
    $activities = [];
    $period = salesReportsFormatPeriod(
        (string) ($filters['start_date'] ?? ''),
        (string) ($filters['end_date'] ?? '')
    );

    $purchaseCount = (int) ($kpis['purchase_count'] ?? 0);
    if ($purchaseCount > 0) {
        $activities[] = [
            'activity' => 'Purchase orders recorded',
            'detail' => number_format($purchaseCount) . ' purchase(s) totaling '
                . salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0))
                . ' during ' . $period . '.',
            'metric' => $purchaseCount,
        ];
    }

    $movementCount = (int) ($kpis['movement_count'] ?? 0);
    if ($movementCount > 0) {
        $activities[] = [
            'activity' => 'Stock movements processed',
            'detail' => number_format($movementCount) . ' movement(s): in '
                . number_format((float) ($kpis['movement_in_qty'] ?? 0), 0)
                . ' / out ' . number_format((float) ($kpis['movement_out_qty'] ?? 0), 0) . ' units.',
            'metric' => $movementCount,
        ];
    }

    $pendingPo = (int) ($kpis['pending_po_count'] ?? 0);
    if ($pendingPo > 0) {
        $activities[] = [
            'activity' => 'Open purchase orders',
            'detail' => number_format($pendingPo) . ' pending purchase(s) valued at '
                . salesReportsFormatMoney((float) ($kpis['pending_po_value'] ?? 0)) . '.',
            'metric' => $pendingPo,
        ];
    }

    $pendingDel = (int) ($kpis['pending_delivery_count'] ?? 0);
    $delayedDel = (int) ($kpis['delayed_delivery_count'] ?? 0);
    if ($pendingDel > 0 || $delayedDel > 0) {
        $activities[] = [
            'activity' => 'Inbound deliveries tracked',
            'detail' => number_format($pendingDel) . ' open shipment(s)'
                . ($delayedDel > 0 ? ' and ' . number_format($delayedDel) . ' delayed' : '') . '.',
            'metric' => $pendingDel + $delayedDel,
        ];
    }

    $reorder = (int) ($kpis['reorder_required_count'] ?? 0);
    if ($reorder > 0) {
        $activities[] = [
            'activity' => 'Replenishment attention required',
            'detail' => number_format((int) ($kpis['low_stock_count'] ?? 0)) . ' low-stock and '
                . number_format((int) ($kpis['out_of_stock_count'] ?? 0)) . ' out-of-stock item(s).',
            'metric' => $reorder,
        ];
    }

    if ($activities === []) {
        $activities[] = [
            'activity' => 'Inventory position reviewed',
            'detail' => number_format((int) ($kpis['total_products'] ?? 0)) . ' product(s) on hand valued at '
                . salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)) . '.',
            'metric' => (int) ($kpis['total_products'] ?? 0),
        ];
    }

    return $activities;
}

function reportDomainStoreProseSection(PDO $pdo, array $report, string $sectionKey): string
{
    $filters = reportEngineFiltersFromReport($report);
    $snapshot = reportDomainStoreSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];
    $prevKpis = $snapshot['previous_kpis'] ?? [];
    $period = reportDomainStorePeriodPhrase($filters);

    return match ($sectionKey) {
        'executive_summary' => reportDomainStoreExecutiveSummaryHtml($period, $kpis),
        'inventory_overview' => reportDomainStoreInventoryOverviewHtml($period, $kpis, $snapshot),
        'stock_movement_analysis' => reportDomainStoreMovementProseHtml($period, $kpis, $snapshot),
        'purchase_activity' => reportDomainStorePurchaseProseHtml($period, $kpis, $snapshot),
        'stock_status_analysis', 'low_stock_analysis' => reportDomainStoreStatusProseHtml($period, $kpis, $snapshot),
        'supplier_analysis' => reportDomainStoreSupplierProseHtml($period, $kpis, $snapshot),
        'key_store_activities' => reportDomainStoreActivitiesProseHtml($period, $kpis, $snapshot),
        'challenges_issues', 'exceptions_risks' => reportDomainStoreChallengesProseHtml($period, $kpis, $snapshot),
        'period_comparison' => reportDomainStoreComparisonProseHtml($period, $kpis, $prevKpis, $filters, $snapshot),
        'key_findings' => reportDomainStoreKeyFindingsHtml($period, $kpis, $snapshot),
        'recommendations' => reportDomainStoreRecommendationsHtml($kpis, $snapshot),
        'action_plan' => reportDomainStoreActionPlanHtml($kpis, $snapshot),
        'conclusion' => reportDomainStoreConclusionHtml($period, $kpis),
        'product_category_analysis' => reportDomainStoreCategoryProseHtml($period, $kpis, $snapshot),
        'fast_slow_moving' => reportDomainStoreFastSlowProseHtml($period, $kpis, $snapshot),
        default => '<p></p>',
    };
}

function reportDomainStorePeriodPhrase(array $filters): string
{
    $start = (string) ($filters['start_date'] ?? '');
    $end = (string) ($filters['end_date'] ?? '');
    $formatted = salesReportsFormatPeriod($start, $end);
    if ($formatted === '') {
        return 'the selected period';
    }

    return $formatted;
}

function reportDomainStoreExecutiveSummaryHtml(string $period, array $kpis): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $products = number_format((int) ($kpis['total_products'] ?? 0));
    $units = number_format((float) ($kpis['total_units'] ?? 0), 0);
    $value = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $movements = number_format((int) ($kpis['movement_count'] ?? 0));
    $purchases = number_format((int) ($kpis['purchase_count'] ?? 0));
    $purchaseValue = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $low = number_format((int) ($kpis['low_stock_count'] ?? 0));
    $out = number_format((int) ($kpis['out_of_stock_count'] ?? 0));

    return '<p>During ' . $p . ', the store held ' . $products . ' products totaling '
        . $units . ' units on hand, valued at ' . $value . '. '
        . 'The period recorded ' . $movements . ' stock movement(s) and '
        . $purchases . ' purchase order(s) amounting to ' . $purchaseValue . '. '
        . 'Replenishment attention is required for ' . $low . ' low-stock and '
        . $out . ' out-of-stock item(s).</p>';
}

function reportDomainStoreInventoryOverviewHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $products = number_format((int) ($kpis['total_products'] ?? 0));
    $units = number_format((float) ($kpis['total_units'] ?? 0), 0);
    $value = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $normal = number_format((int) ($kpis['normal_stock_count'] ?? 0));
    $categories = number_format((int) ($kpis['category_count'] ?? 0));
    $warehouses = number_format((int) ($kpis['warehouse_count'] ?? 0));

    $html = '<p>Inventory for ' . $p . ' covers ' . $products . ' SKUs across '
        . $categories . ' categor' . (((int) ($kpis['category_count'] ?? 0) === 1) ? 'y' : 'ies')
        . ' and ' . $warehouses . ' active warehouse(s). '
        . 'On-hand quantity is ' . $units . ' units with an estimated inventory value of ' . $value
        . '. ' . $normal . ' item(s) currently sit above reorder level.</p>';

    $byCat = $snapshot['stock_by_category'] ?? [];
    if (is_array($byCat) && $byCat !== []) {
        $top = $byCat[0];
        $catName = htmlspecialchars((string) ($top['category'] ?? 'Uncategorized'), ENT_QUOTES, 'UTF-8');
        $catValue = htmlspecialchars(salesReportsFormatMoney((float) ($top['value'] ?? 0)), ENT_QUOTES, 'UTF-8');
        $html .= '<p>The largest category by value is ' . $catName . ' at ' . $catValue . '.</p>';
    }

    return $html;
}

function reportDomainStoreMovementProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $count = (int) ($kpis['movement_count'] ?? 0);
    $inQty = number_format((float) ($kpis['movement_in_qty'] ?? 0), 0);
    $outQty = number_format((float) ($kpis['movement_out_qty'] ?? 0), 0);
    $adjQty = number_format((float) ($kpis['movement_adjust_qty'] ?? 0), 0);

    if ($count === 0) {
        return '<p>No stock movements were recorded during ' . $p
            . '. Confirm receiving, issues, and adjustments are being posted in the ERP.</p>';
    }

    $html = '<p>Stock movement activity for ' . $p . ' totaled ' . number_format($count)
        . ' transaction(s), with inbound quantity of ' . $inQty
        . ' units, outbound quantity of ' . $outQty
        . ' units, and adjustment quantity of ' . $adjQty . ' units.</p>';

    $summary = $snapshot['movement_summary'] ?? [];
    if (is_array($summary) && $summary !== []) {
        $top = $summary[0];
        $type = htmlspecialchars((string) ($top['movement_type'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
        $html .= '<p>The most frequent movement type was ' . $type
            . ' (' . number_format((int) ($top['count'] ?? 0)) . ' entries).</p>';
    }

    return $html;
}

function reportDomainStorePurchaseProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $count = (int) ($kpis['purchase_count'] ?? 0);
    $value = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $pending = number_format((int) ($kpis['pending_po_count'] ?? 0));
    $pendingValue = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['pending_po_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $suppliers = number_format((int) ($kpis['supplier_count'] ?? 0));

    if ($count === 0 && (int) ($kpis['pending_po_count'] ?? 0) === 0) {
        return '<p>No purchase orders were recorded for ' . $p
            . ', and there are no open purchase orders awaiting receipt.</p>';
    }

    $html = '<p>Purchase activity for ' . $p . ' includes ' . number_format($count)
        . ' order(s) totaling ' . $value . ' across ' . $suppliers . ' supplier(s). '
        . 'There are currently ' . $pending . ' open purchase order(s) valued at ' . $pendingValue . '.</p>';

    $topProducts = $snapshot['top_purchased_products'] ?? [];
    if (is_array($topProducts) && $topProducts !== []) {
        $name = htmlspecialchars((string) ($topProducts[0]['product_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
        $qty = number_format((float) ($topProducts[0]['qty'] ?? 0), 0);
        $html .= '<p>The highest purchased product by quantity was ' . $name . ' (' . $qty . ' units).</p>';
    }

    return $html;
}

function reportDomainStoreStatusProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $normal = number_format((int) ($kpis['normal_stock_count'] ?? 0));
    $low = (int) ($kpis['low_stock_count'] ?? 0);
    $out = (int) ($kpis['out_of_stock_count'] ?? 0);
    $reorder = number_format((int) ($kpis['reorder_required_count'] ?? 0));

    $html = '<p>As of the ' . $p . ' store review, ' . $normal
        . ' item(s) are above reorder level, while ' . number_format($low)
        . ' are low stock and ' . number_format($out)
        . ' are out of stock. Combined replenishment attention covers ' . $reorder . ' SKU(s).</p>';

    $lowRows = $snapshot['low_stock'] ?? [];
    if (is_array($lowRows) && $lowRows !== [] && $low > 0) {
        $names = [];
        foreach (array_slice($lowRows, 0, 3) as $row) {
            $names[] = htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        $names = array_filter($names);
        if ($names !== []) {
            $html .= '<p>Examples of low-stock items include ' . implode(', ', $names) . '.</p>';
        }
    }

    $outRows = $snapshot['out_of_stock'] ?? [];
    if (is_array($outRows) && $outRows !== [] && $out > 0) {
        $names = [];
        foreach (array_slice($outRows, 0, 3) as $row) {
            $names[] = htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        $names = array_filter($names);
        if ($names !== []) {
            $html .= '<p>Out-of-stock examples include ' . implode(', ', $names) . '.</p>';
        }
    }

    return $html;
}

function reportDomainStoreSupplierProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $supplierCount = (int) ($kpis['supplier_count'] ?? 0);
    $purchaseValue = htmlspecialchars(salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0)), ENT_QUOTES, 'UTF-8');

    if ($supplierCount === 0) {
        return '<p>No supplier purchase activity was recorded for ' . $p . '.</p>';
    }

    $html = '<p>Store purchasing for ' . $p . ' involved ' . number_format($supplierCount)
        . ' supplier(s) with total purchase value of ' . $purchaseValue . '.</p>';

    $bySupplier = $snapshot['purchases_by_supplier'] ?? [];
    if (is_array($bySupplier) && $bySupplier !== []) {
        $top = $bySupplier[0];
        $name = htmlspecialchars((string) ($top['supplier_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
        $amt = htmlspecialchars(salesReportsFormatMoney((float) ($top['purchase_value'] ?? 0)), ENT_QUOTES, 'UTF-8');
        $cnt = number_format((int) ($top['purchase_count'] ?? 0));
        $html .= '<p>The leading supplier by value was ' . $name . ' with ' . $cnt
            . ' purchase(s) totaling ' . $amt . '.</p>';
    }

    return $html;
}

function reportDomainStoreActivitiesProseHtml(string $period, array $kpis, array $snapshot): string
{
    $activities = $snapshot['key_activities'] ?? [];
    if (!is_array($activities) || $activities === []) {
        $activities = [];
        $purchaseCount = (int) ($kpis['purchase_count'] ?? 0);
        if ($purchaseCount > 0) {
            $activities[] = [
                'activity' => 'Purchase orders recorded',
                'detail' => number_format($purchaseCount) . ' purchase(s) totaling '
                    . salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0)) . '.',
            ];
        }
        $movementCount = (int) ($kpis['movement_count'] ?? 0);
        if ($movementCount > 0) {
            $activities[] = [
                'activity' => 'Stock movements processed',
                'detail' => number_format($movementCount) . ' movement(s) recorded.',
            ];
        }
        $pendingPo = (int) ($kpis['pending_po_count'] ?? 0);
        if ($pendingPo > 0) {
            $activities[] = [
                'activity' => 'Open purchase orders',
                'detail' => number_format($pendingPo) . ' pending purchase(s) valued at '
                    . salesReportsFormatMoney((float) ($kpis['pending_po_value'] ?? 0)) . '.',
            ];
        }
        $pendingDel = (int) ($kpis['pending_delivery_count'] ?? 0);
        $delayedDel = (int) ($kpis['delayed_delivery_count'] ?? 0);
        if ($pendingDel > 0 || $delayedDel > 0) {
            $activities[] = [
                'activity' => 'Inbound deliveries tracked',
                'detail' => number_format($pendingDel) . ' open shipment(s)'
                    . ($delayedDel > 0 ? ' and ' . number_format($delayedDel) . ' delayed' : '') . '.',
            ];
        }
        if ($activities === []) {
            $activities[] = [
                'activity' => 'Inventory position reviewed',
                'detail' => number_format((int) ($kpis['total_products'] ?? 0))
                    . ' product(s) on hand valued at '
                    . salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)) . '.',
            ];
        }
    }

    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $items = '';
    foreach ($activities as $row) {
        $label = htmlspecialchars((string) ($row['activity'] ?? 'Activity'), ENT_QUOTES, 'UTF-8');
        $detail = htmlspecialchars((string) ($row['detail'] ?? ''), ENT_QUOTES, 'UTF-8');
        $items .= '<li><strong>' . $label . ':</strong> ' . $detail . '</li>';
    }

    return '<p>Key store activities for ' . $p . ':</p><ul>' . $items . '</ul>';
}

function reportDomainStoreChallengesProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $items = [];

    $low = (int) ($kpis['low_stock_count'] ?? 0);
    $out = (int) ($kpis['out_of_stock_count'] ?? 0);
    if ($low > 0 || $out > 0) {
        $items[] = '<li><strong>Stock coverage:</strong> '
            . number_format($low) . ' low-stock and ' . number_format($out)
            . ' out-of-stock item(s) require replenishment during ' . $p . '.</li>';
    }

    $delayed = (int) ($kpis['delayed_delivery_count'] ?? 0);
    $pendingDel = (int) ($kpis['pending_delivery_count'] ?? 0);
    if ($delayed > 0) {
        $items[] = '<li><strong>Shipment delays:</strong> '
            . number_format($delayed) . ' delayed inbound shipment(s) may affect store availability.</li>';
    } elseif ($pendingDel > 0) {
        $items[] = '<li><strong>Open deliveries:</strong> '
            . number_format($pendingDel) . ' shipment(s) remain in transit or clearance and need follow-up.</li>';
    }

    $pendingPo = (int) ($kpis['pending_po_count'] ?? 0);
    if ($pendingPo > 0) {
        $items[] = '<li><strong>Open purchase orders:</strong> '
            . number_format($pendingPo) . ' pending purchase(s) valued at '
            . htmlspecialchars(salesReportsFormatMoney((float) ($kpis['pending_po_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
            . ' are awaiting receipt.</li>';
    }

    $exceptions = $snapshot['exceptions'] ?? [];
    if (is_array($exceptions)) {
        foreach ($exceptions as $ex) {
            $msg = htmlspecialchars((string) ($ex['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($msg !== '') {
                $items[] = '<li><strong>Exception:</strong> ' . $msg . '</li>';
            }
        }
    }

    if ($items === []) {
        return '<p>No material stock, purchase, or delivery exceptions were flagged for ' . $p . '.</p>';
    }

    // Deduplicate identical exception bullets that mirror low/out counts.
    $items = array_values(array_unique($items));

    return '<p>Store challenges and risks identified for ' . $p . ':</p><ul>'
        . implode('', $items) . '</ul>';
}

function reportDomainStoreComparisonProseHtml(
    string $period,
    array $kpis,
    array $prevKpis,
    array $filters,
    array $snapshot
): string {
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $prevFilters = array_merge($filters, reportDomainStorePreviousPeriod($filters));
    $prevPeriod = htmlspecialchars(reportDomainStorePeriodPhrase($prevFilters), ENT_QUOTES, 'UTF-8');

    $invChange = reportDomainStoreChangeLabel(
        (float) ($kpis['inventory_value'] ?? 0),
        (float) ($prevKpis['inventory_value'] ?? 0)
    );
    $purchaseChange = reportDomainStoreChangeLabel(
        (float) ($kpis['purchase_value'] ?? 0),
        (float) ($prevKpis['purchase_value'] ?? 0)
    );
    $moveChange = reportDomainStoreChangeLabel(
        (float) ($kpis['movement_count'] ?? 0),
        (float) ($prevKpis['movement_count'] ?? 0)
    );

    return '<p>Comparing ' . $p . ' with the prior period (' . $prevPeriod . '): inventory value changed by '
        . htmlspecialchars($invChange, ENT_QUOTES, 'UTF-8')
        . ', purchase value by ' . htmlspecialchars($purchaseChange, ENT_QUOTES, 'UTF-8')
        . ', and stock movements by ' . htmlspecialchars($moveChange, ENT_QUOTES, 'UTF-8')
        . '. Detailed metric comparisons are available in the period comparison table.</p>';
}

function reportDomainStoreKeyFindingsHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $items = [];

    $items[] = '<li><strong>Inventory position:</strong> '
        . number_format((int) ($kpis['total_products'] ?? 0)) . ' products / '
        . number_format((float) ($kpis['total_units'] ?? 0), 0) . ' units valued at '
        . htmlspecialchars(salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . ' for ' . $p . '.</li>';

    $items[] = '<li><strong>Stock health:</strong> '
        . number_format((int) ($kpis['low_stock_count'] ?? 0)) . ' low-stock and '
        . number_format((int) ($kpis['out_of_stock_count'] ?? 0)) . ' out-of-stock SKUs.</li>';

    $items[] = '<li><strong>Movement volume:</strong> '
        . number_format((int) ($kpis['movement_count'] ?? 0)) . ' movements'
        . ' (in ' . number_format((float) ($kpis['movement_in_qty'] ?? 0), 0)
        . ' / out ' . number_format((float) ($kpis['movement_out_qty'] ?? 0), 0) . ').</li>';

    if ((int) ($kpis['purchase_count'] ?? 0) > 0 || (int) ($kpis['pending_po_count'] ?? 0) > 0) {
        $items[] = '<li><strong>Purchasing:</strong> '
            . number_format((int) ($kpis['purchase_count'] ?? 0)) . ' period purchase(s) totaling '
            . htmlspecialchars(salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
            . '; ' . number_format((int) ($kpis['pending_po_count'] ?? 0)) . ' open PO(s).</li>';
    }

    if ((int) ($kpis['pending_delivery_count'] ?? 0) > 0 || (int) ($kpis['delayed_delivery_count'] ?? 0) > 0) {
        $items[] = '<li><strong>Inbound logistics:</strong> '
            . number_format((int) ($kpis['pending_delivery_count'] ?? 0)) . ' open and '
            . number_format((int) ($kpis['delayed_delivery_count'] ?? 0)) . ' delayed shipment(s).</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>';
}

function reportDomainStoreRecommendationsHtml(array $kpis, array $snapshot): string
{
    $items = [];

    if ((int) ($kpis['out_of_stock_count'] ?? 0) > 0 || (int) ($kpis['low_stock_count'] ?? 0) > 0) {
        $items[] = '<li><strong>Prioritize replenishment:</strong> Raise purchase orders for out-of-stock and low-stock SKUs first.</li>';
    }

    if ((int) ($kpis['delayed_delivery_count'] ?? 0) > 0 || (int) ($kpis['pending_delivery_count'] ?? 0) > 0) {
        $items[] = '<li><strong>Chase inbound shipments:</strong> Follow up delayed and open deliveries with suppliers and logistics.</li>';
    }

    if ((int) ($kpis['pending_po_count'] ?? 0) > 0) {
        $items[] = '<li><strong>Clear open POs:</strong> Confirm receipt status on pending purchases and close completed orders.</li>';
    }

    if ((int) ($kpis['movement_count'] ?? 0) === 0) {
        $items[] = '<li><strong>Validate movement posting:</strong> Ensure receipts, issues, and adjustments are captured daily.</li>';
    }

    $items[] = '<li><strong>Review reorder levels:</strong> Align reorder points with recent movement patterns for fast- and slow-moving items.</li>';
    $items[] = '<li><strong>Strengthen supplier coordination:</strong> Confirm lead times for critical replenishment items.</li>';

    return '<ul>' . implode('', array_slice($items, 0, 5)) . '</ul>';
}

function reportDomainStoreActionPlanHtml(array $kpis, array $snapshot): string
{
    $html = '<p><strong>Phase 1: Stabilize (Days 1&ndash;3)</strong></p><ul>'
        . '<li>Confirm counts for the '
        . number_format((int) ($kpis['out_of_stock_count'] ?? 0))
        . ' out-of-stock and '
        . number_format((int) ($kpis['low_stock_count'] ?? 0))
        . ' low-stock items.</li>'
        . '<li>Assign owners to '
        . number_format((int) ($kpis['pending_delivery_count'] ?? 0))
        . ' open and '
        . number_format((int) ($kpis['delayed_delivery_count'] ?? 0))
        . ' delayed shipments.</li>'
        . '</ul>';

    $html .= '<p><strong>Phase 2: Replenish (Days 4&ndash;7)</strong></p><ul>'
        . '<li>Process or escalate the '
        . number_format((int) ($kpis['pending_po_count'] ?? 0))
        . ' open purchase order(s).</li>'
        . '<li>Raise replacement orders for critical stockouts identified in this review.</li>'
        . '</ul>';

    $html .= '<p><strong>Phase 3: Control (Days 8+)</strong></p><ul>'
        . '<li>Track weekly movement volume against this period baseline of '
        . number_format((int) ($kpis['movement_count'] ?? 0))
        . ' movements.</li>'
        . '<li>Re-check inventory value and reorder exposure before the next store report.</li>'
        . '</ul>';

    return $html;
}

function reportDomainStoreConclusionHtml(string $period, array $kpis): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');

    return '<p>The store review for ' . $p . ' shows '
        . number_format((int) ($kpis['total_products'] ?? 0)) . ' products on hand valued at '
        . htmlspecialchars(salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . ', with ' . number_format((int) ($kpis['movement_count'] ?? 0)) . ' stock movement(s) and '
        . number_format((int) ($kpis['purchase_count'] ?? 0)) . ' purchase(s) in the period. '
        . 'Management should focus on clearing '
        . number_format((int) ($kpis['reorder_required_count'] ?? 0))
        . ' replenishment item(s) and completing open inbound deliveries before the next cycle.</p>';
}

function reportDomainStoreCategoryProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $rows = $snapshot['stock_by_category'] ?? [];
    $categoryCount = (int) ($kpis['category_count'] ?? 0);

    if (!is_array($rows) || $rows === []) {
        return '<p>No category-level stock valuation data was available for ' . $p . '.</p>';
    }

    $html = '<p>Inventory value for ' . $p . ' is distributed across '
        . number_format($categoryCount > 0 ? $categoryCount : count($rows))
        . ' categor' . (($categoryCount === 1) ? 'y' : 'ies') . '. '
        . 'Total inventory value is '
        . htmlspecialchars(salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . '.</p><ul>';

    foreach (array_slice($rows, 0, 5) as $row) {
        $name = htmlspecialchars((string) ($row['category'] ?? 'Uncategorized'), ENT_QUOTES, 'UTF-8');
        $units = number_format((float) ($row['units'] ?? 0), 0);
        $value = htmlspecialchars(salesReportsFormatMoney((float) ($row['value'] ?? 0)), ENT_QUOTES, 'UTF-8');
        $products = number_format((int) ($row['products'] ?? 0));
        $html .= '<li><strong>' . $name . ':</strong> ' . $products . ' product(s), '
            . $units . ' units, ' . $value . '.</li>';
    }

    $html .= '</ul>';

    return $html;
}

function reportDomainStoreFastSlowProseHtml(string $period, array $kpis, array $snapshot): string
{
    $p = htmlspecialchars($period, ENT_QUOTES, 'UTF-8');
    $fast = $snapshot['fast_moving'] ?? [];
    $slow = $snapshot['slow_moving'] ?? [];
    $movements = (int) ($kpis['movement_count'] ?? 0);

    if ($movements === 0) {
        return '<p>No stock movement data was available for ' . $p
            . ', so fast- and slow-moving rankings could not be assessed.</p>';
    }

    $html = '<p>Based on outbound and overall movement activity during ' . $p . ':</p>';

    if (is_array($fast) && $fast !== []) {
        $html .= '<p><strong>Fast-moving:</strong></p><ul>';
        foreach (array_slice($fast, 0, 5) as $row) {
            $name = htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $qty = number_format((float) ($row['qty'] ?? 0), 0);
            $html .= '<li>' . $name . ' &mdash; ' . $qty . ' units.</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p>No fast-moving products were identified for this period.</p>';
    }

    if (is_array($slow) && $slow !== []) {
        $html .= '<p><strong>Slow-moving:</strong></p><ul>';
        foreach (array_slice($slow, 0, 5) as $row) {
            $name = htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $qty = number_format((float) ($row['qty'] ?? 0), 0);
            $html .= '<li>' . $name . ' &mdash; ' . $qty . ' units moved.</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p>No slow-moving products were identified for this period.</p>';
    }

    return $html;
}
