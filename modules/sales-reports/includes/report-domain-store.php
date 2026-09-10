<?php

declare(strict_types=1);

/**
 * Resolve inventory data source. Prefer products/stock when present (matches movements & purchases).
 */
function reportDomainStoreInventoryContext(PDO $pdo): ?array
{
    if (tableExists('products', $pdo) && tableExists('stock', $pdo)) {
        $parts = [];
        foreach (['cost_price', 'buying_price', 'unit_price'] as $col) {
            if (columnExists('products', $col, $pdo)) {
                $parts[] = 'NULLIF(p.' . $col . ', 0)';
            }
        }
        $costCol = $parts === [] ? '0' : ('COALESCE(' . implode(', ', $parts) . ', 0)');
        $codeCol = columnExists('products', 'product_code', $pdo)
            ? 'p.product_code'
            : (columnExists('products', 'sku', $pdo) ? 'p.sku' : 'CAST(p.id AS CHAR)');

        return [
            'mode' => 'products_stock',
            'from' => 'products p LEFT JOIN stock s ON s.product_id = p.id',
            'join' => '',
            'category_join' => tableExists('categories', $pdo) ? 'LEFT JOIN categories c ON c.id = p.category_id' : '',
            'category_expr' => 'COALESCE(c.name, \'Uncategorized\')',
            'name_col' => 'p.name',
            'id_col' => 'p.id',
            'qty_col' => 'COALESCE(s.quantity, 0)',
            'qty_on_hand_col' => 'GREATEST(COALESCE(s.quantity, 0), 0)',
            'reorder_col' => columnExists('products', 'reorder_level', $pdo) ? 'COALESCE(p.reorder_level, 0)' : '0',
            'cost_expr' => $costCol,
            'code_col' => $codeCol,
        ];
    }

    if (tableExists('stocks_items', $pdo)) {
        $reorderCol = columnExists('stocks_items', 'reorder_point', $pdo)
            ? 'reorder_point'
            : (columnExists('stocks_items', 'reorder_level', $pdo) ? 'reorder_level' : 'reorder_point');
        $costExpr = '0';
        if (tableExists('products', $pdo)) {
            foreach (['cost_price', 'buying_price', 'unit_price'] as $col) {
                if (columnExists('products', $col, $pdo)) {
                    $costExpr = "COALESCE(p.{$col}, 0)";
                    break;
                }
            }
        }

        return [
            'mode' => 'stocks_items',
            'from' => 'stocks_items si',
            'join' => tableExists('products', $pdo)
                ? 'LEFT JOIN products p ON p.sku = si.sku OR p.product_code = si.sku'
                : '',
            'category_join' => tableExists('stocks_categories', $pdo)
                ? 'LEFT JOIN stocks_categories c ON c.id = si.category_id'
                : (tableExists('categories', $pdo) ? 'LEFT JOIN categories c ON c.id = si.category_id' : ''),
            'category_expr' => 'COALESCE(c.name, \'Uncategorized\')',
            'name_col' => 'si.name',
            'id_col' => 'si.id',
            'qty_col' => 'si.stock_quantity',
            'qty_on_hand_col' => 'GREATEST(COALESCE(si.stock_quantity, 0), 0)',
            'reorder_col' => 'si.' . $reorderCol,
            'cost_expr' => $costExpr,
            'code_col' => 'si.sku',
        ];
    }

    return null;
}

function reportDomainStorePurchaseDateExpr(PDO $pdo): string
{
    return columnExists('purchases', 'purchase_date', $pdo)
        ? 'COALESCE(p.purchase_date, p.created_at)'
        : 'p.created_at';
}

function reportDomainStoreShipmentDateExpr(PDO $pdo): string
{
    return columnExists('shipments', 'shipment_date', $pdo)
        ? 'COALESCE(sh.shipment_date, sh.created_at)'
        : 'sh.created_at';
}

function reportDomainStorePreviousPeriod(array $filters): array
{
    $start = strtotime((string) ($filters['start_date'] ?? date('Y-m-01')));
    $end = strtotime((string) ($filters['end_date'] ?? date('Y-m-d')));
    if (!$start || !$end) {
        return [
            'start_date' => date('Y-m-01', strtotime('first day of last month')),
            'end_date' => date('Y-m-t', strtotime('last day of last month')),
        ];
    }
    $days = (int) floor(($end - $start) / 86400) + 1;
    $prevEnd = $start - 86400;
    $prevStart = $prevEnd - (($days - 1) * 86400);

    return [
        'start_date' => date('Y-m-d', $prevStart),
        'end_date' => date('Y-m-d', $prevEnd),
    ];
}

function reportDomainStorePctChange(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) {
        return $current == 0.0 ? 0.0 : null;
    }

    return (($current - $previous) / abs($previous)) * 100.0;
}

function reportDomainStoreChangeLabel(float $current, float $previous): string
{
    $diff = $current - $previous;
    $pct = reportDomainStorePctChange($current, $previous);
    $sign = $diff > 0 ? '+' : '';
    $pctPart = $pct === null ? 'n/a' : ($sign . number_format($pct, 1) . '%');

    return $sign . number_format($diff, 0) . ' (' . $pctPart . ')';
}

function reportDomainStoreWarehouseOptions(PDO $pdo): array
{
    if (!tableExists('warehouses', $pdo)) {
        return [];
    }
    try {
        $st = $pdo->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC LIMIT 100");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static fn($r) => ['value' => (string) (int) $r['id'], 'label' => (string) ($r['name'] ?? 'Warehouse')], $rows);
}

function reportDomainStoreCategoryOptions(PDO $pdo): array
{
    if (tableExists('categories', $pdo)) {
        try {
            $st = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC LIMIT 200');
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn($r) => ['value' => (string) (int) $r['id'], 'label' => (string) ($r['name'] ?? '')], $rows);
        } catch (Throwable $e) {
            // fall through
        }
    }
    if (tableExists('stocks_categories', $pdo)) {
        try {
            $st = $pdo->query('SELECT id, name FROM stocks_categories ORDER BY name ASC LIMIT 200');
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn($r) => ['value' => (string) (int) $r['id'], 'label' => (string) ($r['name'] ?? '')], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}

function reportDomainStoreSnapshot(PDO $pdo, array $filters): array
{
    $kpis = reportDomainStoreKpis($pdo, $filters);
    $prevFilters = array_merge($filters, reportDomainStorePreviousPeriod($filters));
    $prevKpis = reportDomainStoreKpis($pdo, $prevFilters, true);

    return [
        'kpis' => $kpis,
        'previous_kpis' => $prevKpis,
        'period_comparison' => reportDomainStorePeriodComparisonRows($kpis, $prevKpis, $filters, $prevFilters),
        'stock_by_category' => reportDomainStoreStockByCategory($pdo, $filters),
        'stock_status' => reportDomainStoreStockStatusBreakdown($pdo, $filters),
        'movement_summary' => reportDomainStoreMovementSummary($pdo, $filters),
        'fast_moving' => reportDomainStoreFastMoving($pdo, $filters),
        'slow_moving' => reportDomainStoreSlowMoving($pdo, $filters),
        'low_stock' => reportDomainStoreLowStock($pdo, $filters),
        'out_of_stock' => reportDomainStoreOutOfStock($pdo, $filters),
        'monthly_movements' => reportDomainStoreMonthlyMovements($pdo, $filters),
        'purchases_by_supplier' => reportDomainStorePurchasesBySupplier($pdo, $filters),
        'top_purchased_products' => reportDomainStoreTopPurchasedProducts($pdo, $filters),
        'pending_purchases' => reportDomainStorePendingPurchases($pdo, $filters),
        'pending_deliveries' => reportDomainStorePendingDeliveries($pdo, $filters),
        'key_activities' => reportDomainStoreKeyActivities($pdo, $filters, $kpis),
        'exceptions' => reportDomainStoreExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainStoreAvailableSections($kpis),
    ];
}

function reportDomainStoreAvailableSections(array $kpis): array
{
    $avail = [
        'executive_summary', 'kpi_overview', 'inventory_overview', 'stock_status_analysis',
        'key_store_activities', 'challenges_issues', 'key_findings', 'recommendations',
        'action_plan', 'conclusion', 'period_comparison',
    ];
    if (($kpis['total_products'] ?? 0) > 0) {
        $avail[] = 'inventory_valuation';
        $avail[] = 'product_category_analysis';
        $avail[] = 'fast_slow_moving';
    }
    if (($kpis['movement_count'] ?? 0) > 0) {
        $avail[] = 'stock_movement_analysis';
        $avail[] = 'trend_analysis';
    }
    if (($kpis['purchase_count'] ?? 0) > 0 || ($kpis['pending_po_count'] ?? 0) > 0) {
        $avail[] = 'purchase_activity';
    }
    if (($kpis['supplier_count'] ?? 0) > 0) {
        $avail[] = 'supplier_analysis';
    }
    if (($kpis['low_stock_count'] ?? 0) > 0 || ($kpis['out_of_stock_count'] ?? 0) > 0) {
        $avail[] = 'low_stock_analysis';
    }
    if (($kpis['exceptions_count'] ?? 0) > 0) {
        $avail[] = 'exceptions_risks';
    }

    return $avail;
}

/**
 * @param bool $lite Skip nested exception queries (used for previous-period KPIs)
 */
function reportDomainStoreKpis(PDO $pdo, array $filters, bool $lite = false): array
{
    $empty = [
        'total_products' => 0,
        'total_units' => 0,
        'inventory_value' => 0.0,
        'normal_stock_count' => 0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
        'reorder_required_count' => 0,
        'movement_count' => 0,
        'movement_in_qty' => 0.0,
        'movement_out_qty' => 0.0,
        'movement_adjust_qty' => 0.0,
        'purchase_count' => 0,
        'purchase_value' => 0.0,
        'pending_po_count' => 0,
        'pending_po_value' => 0.0,
        'pending_delivery_count' => 0,
        'delayed_delivery_count' => 0,
        'supplier_count' => 0,
        'warehouse_count' => 0,
        'category_count' => 0,
        'exceptions_count' => 0,
        'data_quality_notes' => [],
        'inventory_source' => '',
    ];

    $ctx = reportDomainStoreInventoryContext($pdo);
    if ($ctx === null) {
        $empty['data_quality_notes'][] = 'Inventory tables not found (products/stock or stocks_items).';

        return $empty;
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

    $stockStatusWhere = '';
    if (($filters['stock_status'] ?? '') === 'low') {
        $stockStatusWhere = " AND {$ctx['qty_col']} <= {$ctx['reorder_col']} AND {$ctx['qty_col']} > 0";
    } elseif (($filters['stock_status'] ?? '') === 'out') {
        $stockStatusWhere = " AND COALESCE({$ctx['qty_col']}, 0) <= 0";
    } elseif (($filters['stock_status'] ?? '') === 'ok') {
        $stockStatusWhere = " AND {$ctx['qty_col']} > {$ctx['reorder_col']}";
    }

    $qtyOnHand = $ctx['qty_on_hand_col'] ?? "GREATEST({$ctx['qty_col']}, 0)";
    $sql = "SELECT COUNT(DISTINCT {$ctx['id_col']}) AS products,
                   COALESCE(SUM({$qtyOnHand}), 0) AS units,
                   COALESCE(SUM({$qtyOnHand} * GREATEST({$ctx['cost_expr']}, 0)), 0) AS value,
                   SUM(CASE WHEN COALESCE({$ctx['qty_col']},0) > {$ctx['reorder_col']} THEN 1 ELSE 0 END) AS normal_stock,
                   SUM(CASE WHEN COALESCE({$ctx['qty_col']},0) <= {$ctx['reorder_col']} AND COALESCE({$ctx['qty_col']},0) > 0 THEN 1 ELSE 0 END) AS low_stock,
                   SUM(CASE WHEN COALESCE({$ctx['qty_col']},0) <= 0 THEN 1 ELSE 0 END) AS out_stock
            FROM {$ctx['from']}
            {$ctx['join']}
            {$where}{$stockStatusWhere}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $empty['data_quality_notes'][] = 'Could not calculate inventory KPIs.';

        return $empty;
    }

    $categoryCount = 0;
    try {
        if ($ctx['category_join'] !== '') {
            $stC = $pdo->query("SELECT COUNT(DISTINCT {$ctx['category_expr']}) FROM {$ctx['from']} {$ctx['join']} {$ctx['category_join']}");
            $categoryCount = (int) ($stC->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $categoryCount = 0;
    }

    $movementCount = 0;
    $inQty = 0.0;
    $outQty = 0.0;
    $adjQty = 0.0;
    if (tableExists('stock_movements', $pdo)) {
        $sqlM = "SELECT COUNT(*) AS cnt,
                        SUM(CASE WHEN movement_type IN ('in','purchase','receipt','transfer_in','adjustment_in') OR (movement_type = 'adjustment' AND quantity > 0) THEN ABS(quantity) ELSE 0 END) AS in_qty,
                        SUM(CASE WHEN movement_type IN ('out','sale','issue','transfer_out','adjustment_out') OR (movement_type = 'adjustment' AND quantity < 0) THEN ABS(quantity) ELSE 0 END) AS out_qty,
                        SUM(CASE WHEN movement_type LIKE 'adjustment%' THEN ABS(quantity) ELSE 0 END) AS adj_qty
                 FROM stock_movements
                 WHERE DATE(created_at) BETWEEN ? AND ?";
        $paramsM = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['warehouse_id']) && columnExists('stock_movements', 'warehouse_id', $pdo)) {
            $sqlM .= ' AND warehouse_id = ?';
            $paramsM[] = (int) $filters['warehouse_id'];
        }
        try {
            $stM = $pdo->prepare($sqlM);
            $stM->execute($paramsM);
            $mRow = $stM->fetch(PDO::FETCH_ASSOC) ?: [];
            $movementCount = (int) ($mRow['cnt'] ?? 0);
            $inQty = (float) ($mRow['in_qty'] ?? 0);
            $outQty = (float) ($mRow['out_qty'] ?? 0);
            $adjQty = (float) ($mRow['adj_qty'] ?? 0);
        } catch (Throwable $e) {
            // skip
        }
    }

    $purchaseStats = reportDomainStorePurchaseStats($pdo, $filters);
    $deliveryStats = reportDomainStoreDeliveryStats($pdo, $filters);

    $whCount = 0;
    if (tableExists('warehouses', $pdo)) {
        try {
            $whCount = (int) ($pdo->query("SELECT COUNT(*) FROM warehouses WHERE is_active = 1")->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $whCount = 0;
        }
    }

    $low = (int) ($row['low_stock'] ?? 0);
    $out = (int) ($row['out_stock'] ?? 0);
    $kpisPartial = [
        'total_products' => (int) ($row['products'] ?? 0),
        'total_units' => (float) ($row['units'] ?? 0),
        'inventory_value' => (float) ($row['value'] ?? 0),
        'normal_stock_count' => (int) ($row['normal_stock'] ?? 0),
        'low_stock_count' => $low,
        'out_of_stock_count' => $out,
        'reorder_required_count' => $low + $out,
        'movement_count' => $movementCount,
        'movement_in_qty' => $inQty,
        'movement_out_qty' => $outQty,
        'movement_adjust_qty' => $adjQty,
        'purchase_count' => (int) ($purchaseStats['purchase_count'] ?? 0),
        'purchase_value' => (float) ($purchaseStats['purchase_value'] ?? 0),
        'pending_po_count' => (int) ($purchaseStats['pending_po_count'] ?? 0),
        'pending_po_value' => (float) ($purchaseStats['pending_po_value'] ?? 0),
        'pending_delivery_count' => (int) ($deliveryStats['pending_delivery_count'] ?? 0),
        'delayed_delivery_count' => (int) ($deliveryStats['delayed_delivery_count'] ?? 0),
        'supplier_count' => (int) ($purchaseStats['supplier_count'] ?? 0),
        'warehouse_count' => $whCount,
        'category_count' => $categoryCount,
        'inventory_source' => $ctx['mode'],
    ];

    $notes = [];
    if ((int) ($row['products'] ?? 0) === 0) {
        $notes[] = 'No stock items found in inventory tables.';
    }
    if ($movementCount === 0 && tableExists('stock_movements', $pdo)) {
        $notes[] = 'No stock movements recorded for the selected period.';
    }
    if ((int) ($purchaseStats['purchase_count'] ?? 0) === 0 && tableExists('purchases', $pdo)) {
        $notes[] = 'No purchases recorded for the selected period.';
    }

    if ($lite) {
        return array_merge($kpisPartial, [
            'exceptions_count' => 0,
            'data_quality_notes' => $notes,
        ]);
    }

    $exceptions = reportDomainStoreExceptions($pdo, $filters, $kpisPartial);

    return array_merge($kpisPartial, [
        'exceptions_count' => count($exceptions),
        'data_quality_notes' => $notes,
    ]);
}

function reportDomainStoreStockByCategory(PDO $pdo, array $filters): array
{
    $ctx = reportDomainStoreInventoryContext($pdo);
    if ($ctx === null) {
        return [];
    }

    $qtyOnHand = $ctx['qty_on_hand_col'] ?? "GREATEST({$ctx['qty_col']}, 0)";
    $sql = "SELECT {$ctx['category_expr']} AS category,
                   COUNT(DISTINCT {$ctx['id_col']}) AS products,
                   COALESCE(SUM({$qtyOnHand}), 0) AS units,
                   COALESCE(SUM({$qtyOnHand} * GREATEST({$ctx['cost_expr']}, 0)), 0) AS value
            FROM {$ctx['from']}
            {$ctx['join']}
            {$ctx['category_join']}
            GROUP BY category
            ORDER BY value DESC LIMIT 15";
    try {
        $st = $pdo->query($sql);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreLowStock(PDO $pdo, array $filters): array
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
            WHERE COALESCE({$ctx['qty_col']}, 0) <= {$ctx['reorder_col']}
              AND COALESCE({$ctx['qty_col']}, 0) > 0
            ORDER BY qty ASC LIMIT 25";
    try {
        $st = $pdo->query($sql);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreMovementSummary(PDO $pdo, array $filters): array
{
    if (!tableExists('stock_movements', $pdo)) {
        return [];
    }
    $sql = "SELECT movement_type, COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS qty
            FROM stock_movements
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY movement_type ORDER BY qty DESC";
    $params = [$filters['start_date'], $filters['end_date']];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreFastMoving(PDO $pdo, array $filters): array
{
    if (!tableExists('stock_movements', $pdo) || !tableExists('products', $pdo)) {
        return [];
    }
    $sql = "SELECT p.name AS product_name, COALESCE(SUM(sm.quantity), 0) AS qty
            FROM stock_movements sm
            INNER JOIN products p ON p.id = sm.product_id
            WHERE sm.movement_type IN ('out','sale','issue')
              AND DATE(sm.created_at) BETWEEN ? AND ?
            GROUP BY p.id, p.name
            ORDER BY qty DESC LIMIT 10";
    $params = [$filters['start_date'], $filters['end_date']];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreSlowMoving(PDO $pdo, array $filters): array
{
    if (!tableExists('stock_movements', $pdo) || !tableExists('products', $pdo)) {
        return [];
    }
    $sql = "SELECT p.name AS product_name, COALESCE(SUM(sm.quantity), 0) AS qty
            FROM stock_movements sm
            INNER JOIN products p ON p.id = sm.product_id
            WHERE DATE(sm.created_at) BETWEEN ? AND ?
            GROUP BY p.id, p.name
            HAVING qty > 0
            ORDER BY qty ASC LIMIT 10";
    $params = [$filters['start_date'], $filters['end_date']];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreMonthlyMovements(PDO $pdo, array $filters): array
{
    if (!tableExists('stock_movements', $pdo)) {
        return [];
    }
    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                   COUNT(*) AS count,
                   COALESCE(SUM(CASE WHEN movement_type IN ('in','purchase','receipt','transfer_in') OR (movement_type='adjustment' AND quantity>0) THEN ABS(quantity) ELSE 0 END),0) AS qty_in,
                   COALESCE(SUM(CASE WHEN movement_type IN ('out','sale','issue','transfer_out') OR (movement_type='adjustment' AND quantity<0) THEN ABS(quantity) ELSE 0 END),0) AS qty_out
            FROM stock_movements
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY ym ORDER BY ym ASC";
    $params = [$filters['start_date'], $filters['end_date']];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['label'] = date('M Y', strtotime(($r['ym'] ?? date('Y-m')) . '-01'));
            $r['total'] = (float) ($r['qty_in'] ?? 0) + (float) ($r['qty_out'] ?? 0);
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainStoreExceptions(PDO $pdo, array $filters, array $kpis): array
{
    $exceptions = [];
    if (($kpis['low_stock_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'low_stock',
            'message' => number_format((int) $kpis['low_stock_count']) . ' product(s) at or below reorder level.',
            'severity' => 'medium',
        ];
    }
    if (($kpis['out_of_stock_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'stockout',
            'message' => number_format((int) $kpis['out_of_stock_count']) . ' product(s) out of stock.',
            'severity' => 'high',
        ];
    }
    if (($kpis['delayed_delivery_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'delayed_delivery',
            'message' => number_format((int) $kpis['delayed_delivery_count']) . ' shipment(s) marked delayed.',
            'severity' => 'high',
        ];
    }
    if (($kpis['pending_po_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'pending_procurement',
            'message' => number_format((int) $kpis['pending_po_count']) . ' purchase/order(s) still pending completion.',
            'severity' => 'medium',
        ];
    }

    return $exceptions;
}

function reportDomainStoreErpMenu(): array
{
    return [
        'Summary' => [
            'inventory_summary' => 'Store KPI Summary',
            'stock_status' => 'Stock Status Breakdown',
            'period_comparison' => 'Period Comparison',
            'monthly_movements' => 'Monthly Movement Trend',
        ],
        'Inventory' => [
            'stock_by_category' => 'Stock by Category',
            'low_stock' => 'Low Stock Items',
            'out_of_stock' => 'Out of Stock Items',
            'fast_moving' => 'Fast-Moving Items',
            'slow_moving' => 'Slow-Moving Items',
        ],
        'Procurement' => [
            'movement_summary' => 'Stock Movement Summary',
            'purchases_by_supplier' => 'Purchases by Supplier',
            'top_purchased_products' => 'Top Purchased Products',
            'pending_purchases' => 'Pending Purchases / POs',
            'pending_deliveries' => 'Pending / Delayed Deliveries',
            'key_activities' => 'Key Store Activities',
        ],
    ];
}

function reportDomainStoreFetch(PDO $pdo, string $source, array $filters): array
{
    $snapshot = reportDomainStoreSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];
    $period = salesReportsFormatPeriod($filters['start_date'], $filters['end_date']);

    return match ($source) {
        'inventory_summary' => [
            'html' => reportEngineRenderKpiTable([
                ['label' => 'Total Products / SKUs', 'value' => number_format((int) ($kpis['total_products'] ?? 0))],
                ['label' => 'Total Units on Hand', 'value' => number_format((float) ($kpis['total_units'] ?? 0), 0)],
                ['label' => 'Inventory Value', 'value' => salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0))],
                ['label' => 'Product Categories', 'value' => number_format((int) ($kpis['category_count'] ?? 0))],
                ['label' => 'Active Warehouses', 'value' => number_format((int) ($kpis['warehouse_count'] ?? 0))],
                ['label' => 'Normal Stock Items', 'value' => number_format((int) ($kpis['normal_stock_count'] ?? 0))],
                ['label' => 'Low Stock Items', 'value' => number_format((int) ($kpis['low_stock_count'] ?? 0))],
                ['label' => 'Out of Stock Items', 'value' => number_format((int) ($kpis['out_of_stock_count'] ?? 0))],
                ['label' => 'Reorder Required', 'value' => number_format((int) ($kpis['reorder_required_count'] ?? 0))],
                ['label' => 'Stock Movements (Period)', 'value' => number_format((int) ($kpis['movement_count'] ?? 0))],
                ['label' => 'Stock Received (Qty)', 'value' => number_format((float) ($kpis['movement_in_qty'] ?? 0), 0)],
                ['label' => 'Stock Issued (Qty)', 'value' => number_format((float) ($kpis['movement_out_qty'] ?? 0), 0)],
                ['label' => 'Purchases (Period)', 'value' => number_format((int) ($kpis['purchase_count'] ?? 0))],
                ['label' => 'Purchase Value', 'value' => salesReportsFormatMoney((float) ($kpis['purchase_value'] ?? 0))],
                ['label' => 'Pending Purchases / POs', 'value' => number_format((int) ($kpis['pending_po_count'] ?? 0))],
                ['label' => 'Pending Deliveries', 'value' => number_format((int) ($kpis['pending_delivery_count'] ?? 0))],
                ['label' => 'Delayed Deliveries', 'value' => number_format((int) ($kpis['delayed_delivery_count'] ?? 0))],
            ], $period),
            'snapshot' => $kpis,
        ],
        'stock_status' => [
            'html' => reportEngineRenderDataTable(
                ['Stock Status', 'Items', '% of Catalogue'],
                array_map(static fn($r) => [
                    (string) ($r['status'] ?? ''),
                    number_format((int) ($r['count'] ?? 0)),
                    number_format((float) ($r['pct'] ?? 0), 1) . '%',
                ], $snapshot['stock_status'] ?? [])
            ),
            'snapshot' => $snapshot['stock_status'] ?? [],
        ],
        'period_comparison' => [
            'html' => reportEngineRenderDataTable(
                ['Metric', 'Current Period', 'Previous Period', 'Change'],
                array_map(static fn($r) => [
                    (string) ($r['metric'] ?? ''),
                    (string) ($r['current_display'] ?? $r['current'] ?? ''),
                    (string) ($r['previous_display'] ?? $r['previous'] ?? ''),
                    (string) ($r['change'] ?? ''),
                ], $snapshot['period_comparison'] ?? [])
            ),
            'snapshot' => $snapshot['period_comparison'] ?? [],
        ],
        'monthly_movements' => [
            'html' => reportEngineRenderDataTable(
                ['Month', 'Movements', 'Qty In', 'Qty Out'],
                array_map(static fn($r) => [
                    (string) ($r['label'] ?? ''),
                    number_format((int) ($r['count'] ?? 0)),
                    number_format((float) ($r['qty_in'] ?? 0), 0),
                    number_format((float) ($r['qty_out'] ?? 0), 0),
                ], $snapshot['monthly_movements'] ?? [])
            ),
            'snapshot' => $snapshot['monthly_movements'] ?? [],
        ],
        'stock_by_category' => [
            'html' => reportEngineRenderDataTable(
                ['Category', 'Products', 'Units', 'Value'],
                array_map(static fn($r) => [
                    (string) ($r['category'] ?? ''),
                    number_format((int) ($r['products'] ?? 0)),
                    number_format((float) ($r['units'] ?? 0), 0),
                    salesReportsFormatMoney((float) ($r['value'] ?? 0)),
                ], $snapshot['stock_by_category'] ?? [])
            ),
            'snapshot' => $snapshot['stock_by_category'] ?? [],
        ],
        'movement_summary' => [
            'html' => reportEngineRenderDataTable(
                ['Movement Type', 'Count', 'Quantity'],
                array_map(static fn($r) => [
                    (string) ($r['movement_type'] ?? ''),
                    number_format((int) ($r['count'] ?? 0)),
                    number_format((float) ($r['qty'] ?? 0), 0),
                ], $snapshot['movement_summary'] ?? [])
            ),
            'snapshot' => $snapshot['movement_summary'] ?? [],
        ],
        'fast_moving' => [
            'html' => reportEngineRenderDataTable(
                ['Product', 'Qty Out'],
                array_map(static fn($r) => [
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                ], $snapshot['fast_moving'] ?? [])
            ),
            'snapshot' => $snapshot['fast_moving'] ?? [],
        ],
        'slow_moving' => [
            'html' => reportEngineRenderDataTable(
                ['Product', 'Qty Moved'],
                array_map(static fn($r) => [
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                ], $snapshot['slow_moving'] ?? [])
            ),
            'snapshot' => $snapshot['slow_moving'] ?? [],
        ],
        'low_stock' => [
            'html' => reportEngineRenderDataTable(
                ['Code', 'Product', 'On Hand', 'Reorder Level'],
                array_map(static fn($r) => [
                    (string) ($r['product_code'] ?? ''),
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                    number_format((float) ($r['reorder_level'] ?? 0), 0),
                ], $snapshot['low_stock'] ?? [])
            ),
            'snapshot' => $snapshot['low_stock'] ?? [],
        ],
        'out_of_stock' => [
            'html' => reportEngineRenderDataTable(
                ['Code', 'Product', 'On Hand', 'Reorder Level'],
                array_map(static fn($r) => [
                    (string) ($r['product_code'] ?? ''),
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                    number_format((float) ($r['reorder_level'] ?? 0), 0),
                ], $snapshot['out_of_stock'] ?? [])
            ),
            'snapshot' => $snapshot['out_of_stock'] ?? [],
        ],
        'purchases_by_supplier' => [
            'html' => reportEngineRenderDataTable(
                ['Supplier', 'Orders', 'Purchase Value'],
                array_map(static fn($r) => [
                    (string) ($r['supplier_name'] ?? ''),
                    number_format((int) ($r['purchase_count'] ?? $r['order_count'] ?? 0)),
                    salesReportsFormatMoney((float) ($r['purchase_value'] ?? 0)),
                ], $snapshot['purchases_by_supplier'] ?? [])
            ),
            'snapshot' => $snapshot['purchases_by_supplier'] ?? [],
        ],
        'top_purchased_products' => [
            'html' => reportEngineRenderDataTable(
                ['Product', 'Qty Purchased', 'Value'],
                array_map(static fn($r) => [
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                    salesReportsFormatMoney((float) ($r['purchase_value'] ?? 0)),
                ], $snapshot['top_purchased_products'] ?? [])
            ),
            'snapshot' => $snapshot['top_purchased_products'] ?? [],
        ],
        'pending_purchases' => [
            'html' => reportEngineRenderDataTable(
                ['Reference', 'Supplier', 'Status', 'Amount', 'Date'],
                array_map(static fn($r) => [
                    (string) ($r['reference'] ?? $r['purchase_no'] ?? $r['po_number'] ?? ''),
                    (string) ($r['supplier_name'] ?? ''),
                    (string) ($r['status'] ?? ''),
                    salesReportsFormatMoney((float) ($r['amount'] ?? 0)),
                    (string) ($r['activity_date'] ?? $r['purchase_date'] ?? ''),
                ], $snapshot['pending_purchases'] ?? [])
            ),
            'snapshot' => $snapshot['pending_purchases'] ?? [],
        ],
        'pending_deliveries' => [
            'html' => reportEngineRenderDataTable(
                ['Shipment', 'Supplier', 'Status', 'Tracking', 'ETA', 'Value'],
                array_map(static fn($r) => [
                    (string) ($r['shipment_number'] ?? ''),
                    (string) ($r['supplier_name'] ?? ''),
                    (string) ($r['status'] ?? ''),
                    (string) ($r['tracking_number'] ?? ''),
                    (string) ($r['eta'] ?? ''),
                    salesReportsFormatMoney((float) ($r['total_value'] ?? 0)),
                ], $snapshot['pending_deliveries'] ?? [])
            ),
            'snapshot' => $snapshot['pending_deliveries'] ?? [],
        ],
        'key_activities' => [
            'html' => reportEngineRenderDataTable(
                ['Activity', 'Detail'],
                array_map(static fn($r) => [
                    (string) ($r['activity'] ?? $r['title'] ?? ''),
                    (string) ($r['detail'] ?? $r['description'] ?? ''),
                ], $snapshot['key_activities'] ?? [])
            ),
            'snapshot' => $snapshot['key_activities'] ?? [],
        ],
        default => ['html' => '<p>Unknown store data source.</p>', 'snapshot' => []],
    };
}

require_once __DIR__ . '/report-domain-store-extra.php';
