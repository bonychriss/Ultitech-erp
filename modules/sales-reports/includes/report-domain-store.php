<?php

declare(strict_types=1);

/**
 * Resolve inventory data source: stocks_items (stock module) or products/stock tables.
 */
function reportDomainStoreInventoryContext(PDO $pdo): ?array
{
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
            'reorder_col' => 'si.' . $reorderCol,
            'cost_expr' => $costExpr,
        ];
    }

    if (tableExists('products', $pdo) && tableExists('stock', $pdo)) {
        $costCol = 'p.unit_price';
        foreach (['cost_price', 'purchase_price', 'buying_price'] as $col) {
            if (columnExists('products', $col, $pdo)) {
                $costCol = 'p.' . $col;
                break;
            }
        }

        return [
            'mode' => 'products_stock',
            'from' => 'products p LEFT JOIN stock s ON s.product_id = p.id',
            'join' => '',
            'category_join' => tableExists('categories', $pdo) ? 'LEFT JOIN categories c ON c.id = p.category_id' : '',
            'category_expr' => 'COALESCE(c.name, \'Uncategorized\')',
            'name_col' => 'p.name',
            'id_col' => 'p.id',
            'qty_col' => 'COALESCE(s.quantity, 0)',
            'reorder_col' => 'COALESCE(p.reorder_level, 0)',
            'cost_expr' => $costCol,
        ];
    }

    return null;
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

    return [
        'kpis' => $kpis,
        'stock_by_category' => reportDomainStoreStockByCategory($pdo, $filters),
        'movement_summary' => reportDomainStoreMovementSummary($pdo, $filters),
        'fast_moving' => reportDomainStoreFastMoving($pdo, $filters),
        'slow_moving' => reportDomainStoreSlowMoving($pdo, $filters),
        'low_stock' => reportDomainStoreLowStock($pdo, $filters),
        'monthly_movements' => reportDomainStoreMonthlyMovements($pdo, $filters),
        'exceptions' => reportDomainStoreExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainStoreAvailableSections($kpis),
    ];
}

function reportDomainStoreAvailableSections(array $kpis): array
{
    $avail = ['executive_summary', 'kpi_overview', 'inventory_overview', 'key_findings', 'recommendations', 'action_plan', 'conclusion'];
    if (($kpis['total_products'] ?? 0) > 0) {
        $avail[] = 'inventory_valuation';
        $avail[] = 'fast_slow_moving';
    }
    if (($kpis['movement_count'] ?? 0) > 0) {
        $avail[] = 'stock_movement_analysis';
        $avail[] = 'trend_analysis';
    }
    if (($kpis['low_stock_count'] ?? 0) > 0) {
        $avail[] = 'low_stock_analysis';
    }
    if (($kpis['exceptions_count'] ?? 0) > 0) {
        $avail[] = 'exceptions_risks';
    }

    return $avail;
}

function reportDomainStoreKpis(PDO $pdo, array $filters): array
{
    $empty = [
        'total_products' => 0,
        'total_units' => 0,
        'inventory_value' => 0.0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
        'movement_count' => 0,
        'movement_in_qty' => 0.0,
        'movement_out_qty' => 0.0,
        'warehouse_count' => 0,
        'exceptions_count' => 0,
        'data_quality_notes' => [],
    ];

    $ctx = reportDomainStoreInventoryContext($pdo);
    if ($ctx === null) {
        $empty['data_quality_notes'][] = 'Inventory tables not found (stocks_items or products/stock).';

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

    $sql = "SELECT COUNT(DISTINCT {$ctx['id_col']}) AS products,
                   COALESCE(SUM({$ctx['qty_col']}), 0) AS units,
                   COALESCE(SUM({$ctx['qty_col']} * {$ctx['cost_expr']}), 0) AS value,
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

    $movementCount = 0;
    $inQty = 0.0;
    $outQty = 0.0;
    if (tableExists('stock_movements', $pdo)) {
        $sqlM = "SELECT COUNT(*) AS cnt,
                        SUM(CASE WHEN movement_type IN ('in','purchase','receipt','transfer_in','adjustment_in') OR (movement_type = 'adjustment' AND quantity > 0) THEN ABS(quantity) ELSE 0 END) AS in_qty,
                        SUM(CASE WHEN movement_type IN ('out','sale','issue','transfer_out','adjustment_out') OR (movement_type = 'adjustment' AND quantity < 0) THEN ABS(quantity) ELSE 0 END) AS out_qty
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
        } catch (Throwable $e) {
            // skip
        }
    }

    $whCount = 0;
    if (tableExists('warehouses', $pdo)) {
        try {
            $whCount = (int) ($pdo->query("SELECT COUNT(*) FROM warehouses WHERE is_active = 1")->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $whCount = 0;
        }
    }

    $kpisPartial = [
        'total_products' => (int) ($row['products'] ?? 0),
        'total_units' => (float) ($row['units'] ?? 0),
        'inventory_value' => (float) ($row['value'] ?? 0),
        'low_stock_count' => (int) ($row['low_stock'] ?? 0),
        'out_of_stock_count' => (int) ($row['out_stock'] ?? 0),
        'movement_count' => $movementCount,
        'movement_in_qty' => $inQty,
        'movement_out_qty' => $outQty,
        'warehouse_count' => $whCount,
        'inventory_source' => $ctx['mode'],
    ];
    $exceptions = reportDomainStoreExceptions($pdo, $filters, $kpisPartial);
    $notes = [];
    if ((int) ($row['products'] ?? 0) === 0) {
        $notes[] = 'No stock items found in inventory tables.';
    }

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

    $sql = "SELECT {$ctx['category_expr']} AS category,
                   COALESCE(SUM({$ctx['qty_col']}), 0) AS units,
                   COALESCE(SUM({$ctx['qty_col']} * {$ctx['cost_expr']}), 0) AS value
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

    $sql = "SELECT {$ctx['name_col']} AS product_name,
                   COALESCE({$ctx['qty_col']}, 0) AS qty,
                   {$ctx['reorder_col']} AS reorder_level
            FROM {$ctx['from']}
            {$ctx['join']}
            WHERE COALESCE({$ctx['qty_col']}, 0) <= {$ctx['reorder_col']}
            ORDER BY qty ASC LIMIT 20";
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
    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS total
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

    return $exceptions;
}

function reportDomainStoreErpMenu(): array
{
    return [
        'Summary' => [
            'inventory_summary' => 'Inventory Summary KPIs',
            'monthly_movements' => 'Monthly Movement Trend',
        ],
        'Analysis' => [
            'stock_by_category' => 'Stock by Category',
            'movement_summary' => 'Movement Summary',
            'fast_moving' => 'Fast-Moving Items',
            'slow_moving' => 'Slow-Moving Items',
            'low_stock' => 'Low Stock Items',
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
                ['label' => 'Products in Stock', 'value' => number_format((int) ($kpis['total_products'] ?? 0))],
                ['label' => 'Total Units on Hand', 'value' => number_format((float) ($kpis['total_units'] ?? 0), 0)],
                ['label' => 'Inventory Value', 'value' => salesReportsFormatMoney((float) ($kpis['inventory_value'] ?? 0))],
                ['label' => 'Low Stock Items', 'value' => number_format((int) ($kpis['low_stock_count'] ?? 0))],
                ['label' => 'Out of Stock Items', 'value' => number_format((int) ($kpis['out_of_stock_count'] ?? 0))],
                ['label' => 'Stock Movements (Period)', 'value' => number_format((int) ($kpis['movement_count'] ?? 0))],
                ['label' => 'Stock In (Qty)', 'value' => number_format((float) ($kpis['movement_in_qty'] ?? 0), 0)],
                ['label' => 'Stock Out (Qty)', 'value' => number_format((float) ($kpis['movement_out_qty'] ?? 0), 0)],
            ], $period),
            'snapshot' => $kpis,
        ],
        'monthly_movements' => [
            'html' => reportEngineMonthlyTrendTable($snapshot['monthly_movements'] ?? [], 'Quantity'),
            'snapshot' => $snapshot['monthly_movements'] ?? [],
        ],
        'stock_by_category' => [
            'html' => reportEngineRenderDataTable(
                ['Category', 'Units', 'Value'],
                array_map(static fn($r) => [
                    (string) ($r['category'] ?? ''),
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
                ['Product', 'On Hand', 'Reorder Level'],
                array_map(static fn($r) => [
                    (string) ($r['product_name'] ?? ''),
                    number_format((float) ($r['qty'] ?? 0), 0),
                    number_format((float) ($r['reorder_level'] ?? 0), 0),
                ], $snapshot['low_stock'] ?? [])
            ),
            'snapshot' => $snapshot['low_stock'] ?? [],
        ],
        default => ['html' => '<p>Unknown store/warehouse data source.</p>', 'snapshot' => []],
    };
}
