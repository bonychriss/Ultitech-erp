<?php

declare(strict_types=1);

/**
 * Procurement report helpers (period comparison, activities, suppliers, prose).
 */

function reportDomainProcurementSpoDateExpr(PDO $pdo, string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    if (columnExists('stocks_purchase_orders', 'created_at', $pdo)) {
        return "{$p}created_at";
    }

    return "{$p}updated_at";
}

function reportDomainProcurementLegacyDateExpr(PDO $pdo, string $alias = 'p'): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $hasPurchaseDate = columnExists('purchases', 'purchase_date', $pdo);
    $hasCreated = columnExists('purchases', 'created_at', $pdo);
    if ($hasPurchaseDate && $hasCreated) {
        return "COALESCE({$p}purchase_date, {$p}created_at)";
    }
    if ($hasPurchaseDate) {
        return "{$p}purchase_date";
    }

    return "{$p}created_at";
}

function reportDomainProcurementShipmentDateExpr(PDO $pdo, string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $hasShip = columnExists('shipments', 'shipment_date', $pdo);
    $hasCreated = columnExists('shipments', 'created_at', $pdo);
    if ($hasShip && $hasCreated) {
        return "COALESCE({$p}shipment_date, {$p}created_at)";
    }
    if ($hasShip) {
        return "{$p}shipment_date";
    }

    return "{$p}created_at";
}

function reportDomainProcurementClearanceExpr(PDO $pdo, string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $parts = [];
    foreach (['estimated_clearance_cost', 'customs_duty', 'customs_brokerage', 'port_charges'] as $col) {
        if (columnExists('shipments', $col, $pdo)) {
            $parts[] = "COALESCE({$p}{$col}, 0)";
        }
    }
    if ($parts === []) {
        return '0';
    }

    return implode(' + ', $parts);
}

function reportDomainProcurementPreviousPeriod(array $filters): array
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

function reportDomainProcurementPctChange(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) {
        return $current == 0.0 ? 0.0 : null;
    }

    return (($current - $previous) / abs($previous)) * 100.0;
}

function reportDomainProcurementH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reportDomainProcurementChangeLabel(float $current, float $previous, bool $money = true): string
{
    $diff = $current - $previous;
    $pct = reportDomainProcurementPctChange($current, $previous);
    $pctSign = $diff > 0 ? '+' : '';
    $pctPart = $pct === null ? 'n/a' : ($pctSign . number_format($pct, 1) . '%');
    if ($money) {
        $amount = salesReportsFormatMoney(abs($diff));
        if ($diff > 0) {
            return '+' . $amount . ' (' . $pctPart . ')';
        }
        if ($diff < 0) {
            return '-' . $amount . ' (' . $pctPart . ')';
        }

        return $amount . ' (' . $pctPart . ')';
    }

    return ($diff > 0 ? '+' : '') . number_format($diff, 0) . ' (' . $pctPart . ')';
}

function reportDomainProcurementPeriodMonthLabel(array $filters): string
{
    $start = $filters['start_date'] ?? date('Y-m-01');
    $end = $filters['end_date'] ?? date('Y-m-d');
    if (date('Y-m', strtotime((string) $start)) === date('Y-m', strtotime((string) $end))) {
        return date('F Y', strtotime((string) $start));
    }

    return salesReportsFormatPeriod((string) $start, (string) $end);
}

function reportDomainProcurementActivityRows(array $kpis): array
{
    return [
        [
            'category' => 'Internal Purchases',
            'amount' => (float) ($kpis['domestic_value'] ?? 0),
            'pct' => $kpis['domestic_pct'] ?? null,
            'count' => (int) ($kpis['domestic_count'] ?? 0),
        ],
        [
            'category' => 'International Purchases',
            'amount' => (float) ($kpis['import_value'] ?? 0),
            'pct' => $kpis['import_pct'] ?? null,
            'count' => (int) ($kpis['import_count'] ?? 0),
        ],
        [
            'category' => 'Customs & Clearance',
            'amount' => (float) ($kpis['customs_value'] ?? 0),
            'pct' => $kpis['customs_pct'] ?? null,
            'count' => (int) ($kpis['shipment_count'] ?? 0),
        ],
        [
            'category' => 'Total Purchases',
            'amount' => (float) ($kpis['total_procurement'] ?? 0),
            'pct' => ($kpis['total_procurement'] ?? 0) > 0 ? 100.0 : null,
            'count' => (int) ($kpis['purchase_count'] ?? 0),
        ],
    ];
}

function reportDomainProcurementOverviewTableHtml(array $kpis, array $filters): string
{
    $html = '<table class="sr-data-table sr-proc-kpis"><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
    foreach ([
        ['Total procurement', salesReportsFormatMoney((float) ($kpis['total_procurement'] ?? 0))],
        ['Internal', salesReportsFormatMoney((float) ($kpis['domestic_value'] ?? 0))],
        ['International', salesReportsFormatMoney((float) ($kpis['import_value'] ?? 0))],
        ['Customs & clearance', salesReportsFormatMoney((float) ($kpis['customs_value'] ?? 0))],
        ['Purchase orders', number_format((int) ($kpis['purchase_count'] ?? 0))],
        ['Suppliers', number_format((int) ($kpis['supplier_count'] ?? 0))],
        ['Completed', number_format((int) ($kpis['completed_count'] ?? 0))],
        ['Open orders', number_format((int) ($kpis['pending_count'] ?? 0))],
        ['Shipments in period', number_format((int) ($kpis['shipment_count'] ?? 0))],
        ['Pending deliveries', number_format((int) ($kpis['pending_delivery_count'] ?? 0))],
        ['Delayed deliveries', number_format((int) ($kpis['delayed_delivery_count'] ?? 0))],
    ] as [$label, $value]) {
        $html .= '<tr><td><strong>' . reportDomainProcurementH($label) . '</strong></td>'
            . '<td class="sr-num">' . reportDomainProcurementH($value) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $notes = array_values(array_filter($kpis['data_quality_notes'] ?? []));
    if ($notes !== []) {
        $html .= '<div class="sr-notes"><strong>Data notes</strong><ul>';
        foreach ($notes as $note) {
            $html .= '<li>' . reportDomainProcurementH((string) $note) . '</li>';
        }
        $html .= '</ul></div>';
    }

    return $html;
}

function reportDomainProcurementSupplierNameExpr(string $alias = 'p'): string
{
    return "COALESCE(s.name, ss.name, CONCAT('Supplier #', {$alias}.supplier_id))";
}

function reportDomainProcurementSuppliers(PDO $pdo, array $filters): array
{
    $rows = [];
    $totalsBySupplier = [];

    $add = static function (array $row) use (&$totalsBySupplier): void {
        $id = (int) ($row['supplier_id'] ?? 0);
        $name = (string) ($row['supplier_name'] ?? ('Supplier #' . $id));
        $key = $id > 0 ? (string) $id : 'n:' . $name;
        if (!isset($totalsBySupplier[$key])) {
            $totalsBySupplier[$key] = [
                'supplier_id' => $id,
                'supplier_name' => $name,
                'order_count' => 0,
                'purchase_value' => 0.0,
            ];
        }
        $totalsBySupplier[$key]['order_count'] += (int) ($row['order_count'] ?? 0);
        $totalsBySupplier[$key]['purchase_value'] += (float) ($row['purchase_value'] ?? 0);
    };

    if (tableExists('stocks_purchase_orders', $pdo)) {
        $dateExpr = reportDomainProcurementSpoDateExpr($pdo, 'p');
        $nameExpr = reportDomainProcurementSupplierNameExpr('p');
        $sql = "SELECT p.supplier_id, {$nameExpr} AS supplier_name,
                       COUNT(*) AS order_count,
                       COALESCE(SUM(COALESCE(p.total_amount, 0)), 0) AS purchase_value
                FROM stocks_purchase_orders p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                LEFT JOIN stocks_suppliers ss ON ss.id = p.supplier_id
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(p.status,''))) NOT IN ('cancelled','canceled')
                GROUP BY p.supplier_id, supplier_name";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $add($row);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (tableExists('purchases', $pdo)) {
        $dateExpr = reportDomainProcurementLegacyDateExpr($pdo, 'p');
        $nameExpr = reportDomainProcurementSupplierNameExpr('p');
        $sql = "SELECT p.supplier_id, {$nameExpr} AS supplier_name,
                       COUNT(*) AS order_count,
                       COALESCE(SUM(COALESCE(p.total_amount, 0)), 0) AS purchase_value
                FROM purchases p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                LEFT JOIN stocks_suppliers ss ON ss.id = p.supplier_id
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(p.status,''))) NOT IN ('cancelled','canceled')
                GROUP BY p.supplier_id, supplier_name";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $add($row);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $rows = array_values($totalsBySupplier);
    usort($rows, static fn($a, $b) => ((float) $b['purchase_value'] <=> (float) $a['purchase_value']));
    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float) $r['purchase_value'];
    }
    foreach ($rows as &$r) {
        $r['pct'] = $total > 0 ? round(((float) $r['purchase_value'] / $total) * 100, 1) : 0.0;
    }
    unset($r);

    return array_slice($rows, 0, 20);
}

function reportDomainProcurementSuppliersTableHtml(array $rows, array $kpis = []): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No suppliers with procurement transactions were found for the selected period.</p>';
    }

    $html = '<table class="sr-data-table sr-proc-suppliers"><thead><tr>'
        . '<th>Supplier</th><th>Orders</th><th>Procurement Value</th><th>% of Period</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $pct = (float) ($r['pct'] ?? 0);
        $html .= '<tr><td>' . reportDomainProcurementH((string) ($r['supplier_name'] ?? '')) . '</td>'
            . '<td class="sr-num">' . number_format((int) ($r['order_count'] ?? 0)) . '</td>'
            . '<td class="sr-num">' . reportDomainProcurementH(salesReportsFormatMoney((float) ($r['purchase_value'] ?? 0))) . '</td>'
            . '<td class="sr-num"><div class="sr-pct-cell"><span class="sr-pct-bar" style="width:'
            . max(0, min(100, $pct)) . '%"></span><span class="sr-pct-label">'
            . number_format($pct, 1) . '%</span></div></td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportDomainProcurementOrderStatus(PDO $pdo, array $filters): array
{
    $bucket = [];
    $bump = static function (string $status, float $amount) use (&$bucket): void {
        $status = $status !== '' ? $status : 'unknown';
        if (!isset($bucket[$status])) {
            $bucket[$status] = ['status' => $status, 'count' => 0, 'value' => 0.0];
        }
        $bucket[$status]['count']++;
        $bucket[$status]['value'] += $amount;
    };

    if (tableExists('stocks_purchase_orders', $pdo)) {
        $dateExpr = reportDomainProcurementSpoDateExpr($pdo);
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(status), ''), 'unknown') AS status,
                        COALESCE(total_amount, 0) AS amount
                 FROM stocks_purchase_orders
                 WHERE DATE({$dateExpr}) BETWEEN ? AND ?"
            );
            $st->execute([$filters['start_date'], $filters['end_date']]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $bump((string) $row['status'], (float) $row['amount']);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (tableExists('purchases', $pdo)) {
        $dateExpr = reportDomainProcurementLegacyDateExpr($pdo, '');
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(status), ''), 'unknown') AS status,
                        COALESCE(total_amount, 0) AS amount
                 FROM purchases
                 WHERE DATE({$dateExpr}) BETWEEN ? AND ?"
            );
            $st->execute([$filters['start_date'], $filters['end_date']]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $bump((string) $row['status'], (float) $row['amount']);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $rows = array_values($bucket);
    usort($rows, static fn($a, $b) => ((int) $b['count'] <=> (int) $a['count']));
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['count'];
    }
    foreach ($rows as &$r) {
        $r['pct'] = $total > 0 ? round(((int) $r['count'] / $total) * 100, 1) : 0.0;
        $r['status_label'] = reportDomainProcurementStatusLabel((string) $r['status']);
    }
    unset($r);

    return $rows;
}

function reportDomainProcurementStatusLabel(string $status): string
{
    $map = [
        'received' => 'Completed / Received',
        'completed' => 'Completed',
        'closed' => 'Closed',
        'approved' => 'Approved',
        'pending' => 'Pending',
        'pending approval' => 'Pending Approval',
        'pending supplier' => 'Pending Supplier',
        'supplier responded' => 'Pending Payment / Supplier Responded',
        'draft' => 'Draft',
        'cancelled' => 'Cancelled',
        'canceled' => 'Cancelled',
        'negotiation requested' => 'Negotiation Requested',
        'ordered' => 'Ordered',
    ];
    $key = strtolower(trim($status));

    return $map[$key] ?? ucwords(str_replace('_', ' ', $status));
}

function reportDomainProcurementStatusBadgeClass(string $status): string
{
    $key = strtolower(trim($status));
    if (in_array($key, ['received', 'completed', 'closed', 'delivered'], true)) {
        return 'sr-badge sr-badge--ok';
    }
    if (in_array($key, ['cancelled', 'canceled', 'delayed'], true)) {
        return 'sr-badge sr-badge--danger';
    }
    if (in_array($key, ['approved', 'ordered'], true)) {
        return 'sr-badge sr-badge--info';
    }

    return 'sr-badge sr-badge--warn';
}

function reportDomainProcurementOrderStatusTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No procurement order status records were found for the selected period.</p>';
    }

    $html = '<table class="sr-data-table sr-proc-status"><thead><tr>'
        . '<th>Status</th><th>Orders</th><th>% of Orders</th><th>Value</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $raw = (string) ($r['status'] ?? '');
        $label = (string) ($r['status_label'] ?? $raw);
        $html .= '<tr><td><span class="' . reportDomainProcurementStatusBadgeClass($raw) . '">'
            . reportDomainProcurementH($label) . '</span></td>'
            . '<td class="sr-num">' . number_format((int) ($r['count'] ?? 0)) . '</td>'
            . '<td class="sr-num">' . number_format((float) ($r['pct'] ?? 0), 1) . '%</td>'
            . '<td class="sr-num">' . reportDomainProcurementH(salesReportsFormatMoney((float) ($r['value'] ?? 0))) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportDomainProcurementKeyActivities(PDO $pdo, array $filters, array $kpis): array
{
    $activities = [];

    // Top purchase orders in period
    $orders = [];
    if (tableExists('purchases', $pdo)) {
        $dateExpr = reportDomainProcurementLegacyDateExpr($pdo, 'p');
        $nameExpr = reportDomainProcurementSupplierNameExpr('p');
        $sql = "SELECT p.id, p.purchase_no AS ref, {$nameExpr} AS supplier_name,
                       p.status, COALESCE(p.total_amount, 0) AS amount,
                       DATE({$dateExpr}) AS order_date, 'legacy' AS source
                FROM purchases p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                LEFT JOIN stocks_suppliers ss ON ss.id = p.supplier_id
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(p.status,''))) NOT IN ('cancelled','canceled')
                ORDER BY amount DESC LIMIT 12";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            $orders = array_merge($orders, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (tableExists('stocks_purchase_orders', $pdo)) {
        $dateExpr = reportDomainProcurementSpoDateExpr($pdo, 'p');
        $nameExpr = reportDomainProcurementSupplierNameExpr('p');
        $sql = "SELECT p.id, p.po_number AS ref, {$nameExpr} AS supplier_name,
                       p.status, COALESCE(p.total_amount, 0) AS amount,
                       DATE({$dateExpr}) AS order_date, p.purchase_type AS source
                FROM stocks_purchase_orders p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                LEFT JOIN stocks_suppliers ss ON ss.id = p.supplier_id
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(p.status,''))) NOT IN ('cancelled','canceled')
                ORDER BY amount DESC LIMIT 12";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            $orders = array_merge($orders, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            // ignore
        }
    }

    usort($orders, static fn($a, $b) => ((float) ($b['amount'] ?? 0) <=> (float) ($a['amount'] ?? 0)));
    $orders = array_slice($orders, 0, 10);

    foreach ($orders as $o) {
        $itemSummary = reportDomainProcurementOrderItemSummary($pdo, $o);
        $supplier = (string) ($o['supplier_name'] ?? 'Unknown');
        $statusRaw = (string) ($o['status'] ?? '');
        $statusLabel = reportDomainProcurementStatusLabel($statusRaw);
        $amount = (float) ($o['amount'] ?? 0);
        $date = (string) ($o['order_date'] ?? '');
        $ref = (string) ($o['ref'] ?? 'Purchase');
        $detailParts = array_values(array_filter([
            $itemSummary !== '' ? $itemSummary : null,
            'Supplier: ' . $supplier,
            'Value: ' . salesReportsFormatMoney($amount),
            'Status: ' . $statusLabel,
            $date !== '' ? 'Date: ' . $date : null,
        ]));
        $activities[] = [
            'activity' => $ref,
            'items' => $itemSummary,
            'supplier' => $supplier,
            'status' => $statusRaw,
            'status_label' => $statusLabel,
            'amount' => $amount,
            'order_date' => $date,
            'kind' => 'purchase',
            'detail' => implode(' | ', $detailParts),
            'metric' => $amount,
        ];
    }

    // Notable shipments
    if (tableExists('shipments', $pdo)) {
        $shipDate = reportDomainProcurementShipmentDateExpr($pdo);
        try {
            $st = $pdo->prepare(
                "SELECT shipment_number, status, tracking_number, eta, total_value,
                        COALESCE(estimated_clearance_cost, 0) AS clearance
                 FROM shipments
                 WHERE DATE({$shipDate}) BETWEEN ? AND ?
                 ORDER BY COALESCE(total_value, 0) DESC LIMIT 5"
            );
            $st->execute([$filters['start_date'], $filters['end_date']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sh) {
                $bits = [
                    'Shipment ' . (string) ($sh['shipment_number'] ?? ''),
                    'status ' . (string) ($sh['status'] ?? ''),
                ];
                if (!empty($sh['tracking_number'])) {
                    $bits[] = 'tracking ' . (string) $sh['tracking_number'];
                }
                if (!empty($sh['eta'])) {
                    $bits[] = 'ETA ' . substr((string) $sh['eta'], 0, 10);
                }
                if ((float) ($sh['clearance'] ?? 0) > 0) {
                    $bits[] = 'clearance ' . salesReportsFormatMoney((float) $sh['clearance']);
                }
                $activities[] = [
                    'activity' => 'Shipment follow-up',
                    'items' => implode('; ', $bits),
                    'supplier' => '',
                    'status' => (string) ($sh['status'] ?? ''),
                    'status_label' => reportDomainProcurementStatusLabel((string) ($sh['status'] ?? '')),
                    'amount' => (float) ($sh['total_value'] ?? 0),
                    'order_date' => substr((string) ($sh['eta'] ?? ''), 0, 10),
                    'kind' => 'shipment',
                    'detail' => implode('; ', $bits),
                    'metric' => (float) ($sh['total_value'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $activities;
}

function reportDomainProcurementOrderItemSummary(PDO $pdo, array $order): string
{
    $source = (string) ($order['source'] ?? '');
    $id = (int) ($order['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    try {
        if ($source === 'legacy' && tableExists('purchase_items', $pdo) && tableExists('products', $pdo)) {
            $st = $pdo->prepare(
                "SELECT pr.name, pi.quantity
                 FROM purchase_items pi
                 LEFT JOIN products pr ON pr.id = pi.product_id
                 WHERE pi.purchase_id = ?
                 ORDER BY COALESCE(pi.total_amount, pi.quantity * pi.unit_price) DESC LIMIT 2"
            );
            $st->execute([$id]);
            $parts = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $name = trim((string) ($row['name'] ?? 'Item'));
                if (!mb_check_encoding($name, 'UTF-8')) {
                    $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                }
                $qty = (float) ($row['quantity'] ?? 0);
                $parts[] = $name . ($qty > 0 ? ' (' . number_format($qty, 0) . ')' : '');
            }

            return implode('; ', $parts);
        }
        if (tableExists('stocks_po_items', $pdo) && tableExists('products', $pdo)) {
            $st = $pdo->prepare(
                "SELECT pr.name, i.qty_ordered AS quantity
                 FROM stocks_po_items i
                 LEFT JOIN products pr ON pr.id = i.item_id
                 WHERE i.po_id = ?
                 ORDER BY (COALESCE(i.qty_ordered,0) * COALESCE(i.unit_cost,0)) DESC LIMIT 2"
            );
            $st->execute([$id]);
            $parts = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $name = trim((string) ($row['name'] ?? 'Item'));
                if (!mb_check_encoding($name, 'UTF-8')) {
                    $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                }
                $qty = (float) ($row['quantity'] ?? 0);
                $parts[] = $name . ($qty > 0 ? ' (' . number_format($qty, 0) . ')' : '');
            }

            return implode('; ', $parts);
        }
    } catch (Throwable $e) {
        return '';
    }

    return '';
}

function reportDomainProcurementKeyActivitiesTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No key procurement activities were recorded for the selected period.</p>';
    }

    $html = '<table class="sr-data-table sr-proc-activities"><thead><tr>'
        . '<th>Reference</th><th>Items / Detail</th><th>Supplier</th><th>Value</th><th>Status</th><th>Date</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $kind = (string) ($r['kind'] ?? 'purchase');
        $items = (string) ($r['items'] ?? $r['detail'] ?? '');
        $supplier = (string) ($r['supplier'] ?? '');
        $statusRaw = (string) ($r['status'] ?? '');
        $statusLabel = (string) ($r['status_label'] ?? reportDomainProcurementStatusLabel($statusRaw));
        $html .= '<tr>'
            . '<td><strong>' . reportDomainProcurementH((string) ($r['activity'] ?? '')) . '</strong></td>'
            . '<td>' . reportDomainProcurementH($items) . '</td>'
            . '<td>' . reportDomainProcurementH($supplier !== '' ? $supplier : ($kind === 'shipment' ? '-' : '')) . '</td>'
            . '<td class="sr-num">' . reportDomainProcurementH(salesReportsFormatMoney((float) ($r['amount'] ?? $r['metric'] ?? 0))) . '</td>'
            . '<td><span class="' . reportDomainProcurementStatusBadgeClass($statusRaw) . '">'
            . reportDomainProcurementH($statusLabel !== '' ? $statusLabel : '-') . '</span></td>'
            . '<td class="sr-num">' . reportDomainProcurementH((string) ($r['order_date'] ?? '-') ?: '-') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportDomainProcurementPendingDeliveries(PDO $pdo, array $filters): array
{
    if (!tableExists('shipments', $pdo)) {
        return [];
    }
    $shipDate = reportDomainProcurementShipmentDateExpr($pdo);
    try {
        $st = $pdo->prepare(
            "SELECT shipment_number, status, tracking_number, eta, total_value,
                    COALESCE(estimated_clearance_cost, 0) AS clearance
             FROM shipments
             WHERE (
                    DATE({$shipDate}) BETWEEN ? AND ?
                    OR (eta IS NOT NULL AND eta < CURDATE() AND actual_arrival_date IS NULL
                        AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('delivered','cancelled','canceled'))
                   )
               AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('delivered','cancelled','canceled')
             ORDER BY
                CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'delayed' THEN 0 ELSE 1 END,
                eta ASC
             LIMIT 20"
        );
        $st->execute([$filters['start_date'], $filters['end_date']]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainProcurementPendingDeliveriesTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No pending or delayed shipments were found for the selected period.</p>';
    }

    return reportEngineRenderDataTable(
        ['Shipment', 'Status', 'Tracking', 'ETA', 'Value', 'Clearance Est.'],
        array_map(static fn($r) => [
            (string) ($r['shipment_number'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['tracking_number'] ?? '-'),
            substr((string) ($r['eta'] ?? ''), 0, 10) ?: '-',
            salesReportsFormatMoney((float) ($r['total_value'] ?? 0)),
            salesReportsFormatMoney((float) ($r['clearance'] ?? 0)),
        ], $rows)
    );
}

function reportDomainProcurementPeriodComparisonRows(
    array $kpis,
    array $prevKpis,
    array $filters,
    array $prevFilters
): array {
    $curPeriod = salesReportsFormatPeriod((string) ($filters['start_date'] ?? ''), (string) ($filters['end_date'] ?? ''));
    $prevPeriod = salesReportsFormatPeriod((string) ($prevFilters['start_date'] ?? ''), (string) ($prevFilters['end_date'] ?? ''));
    $metrics = [
        ['label' => 'Total procurement', 'key' => 'total_procurement'],
        ['label' => 'Internal purchases', 'key' => 'domestic_value'],
        ['label' => 'International purchases', 'key' => 'import_value'],
        ['label' => 'Customs & clearance', 'key' => 'customs_value'],
        ['label' => 'Purchase orders', 'key' => 'purchase_count'],
        ['label' => 'Suppliers used', 'key' => 'supplier_count'],
        ['label' => 'Delayed deliveries', 'key' => 'delayed_delivery_count'],
    ];
    $rows = [];
    foreach ($metrics as $m) {
        $cur = (float) ($kpis[$m['key']] ?? 0);
        $prev = (float) ($prevKpis[$m['key']] ?? 0);
        $money = str_contains($m['key'], 'value') || $m['key'] === 'total_procurement';
        $rows[] = [
            'metric' => $m['label'],
            'current_display' => $money ? salesReportsFormatMoney($cur) : number_format($cur, 0),
            'previous_display' => $money ? salesReportsFormatMoney($prev) : number_format($prev, 0),
            'change' => reportDomainProcurementChangeLabel($cur, $prev, $money),
            'current_period' => $curPeriod,
            'previous_period' => $prevPeriod,
            'current' => $cur,
            'previous' => $prev,
        ];
    }

    return $rows;
}

function reportDomainProcurementPeriodComparisonTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No period comparison metrics available.</p>';
    }
    $cur = (string) ($rows[0]['current_period'] ?? 'Current');
    $prev = (string) ($rows[0]['previous_period'] ?? 'Previous');

    $html = '<table class="sr-data-table sr-proc-compare"><thead><tr>'
        . '<th>Metric</th><th>' . reportDomainProcurementH($cur) . '</th>'
        . '<th>' . reportDomainProcurementH($prev) . '</th><th>Change</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $change = (string) ($r['change'] ?? '');
        $cls = str_starts_with($change, '+') ? 'sr-change-up' : (str_starts_with($change, '-') ? 'sr-change-down' : '');
        $html .= '<tr><td>' . reportDomainProcurementH((string) ($r['metric'] ?? '')) . '</td>'
            . '<td class="sr-num">' . reportDomainProcurementH((string) ($r['current_display'] ?? '')) . '</td>'
            . '<td class="sr-num">' . reportDomainProcurementH((string) ($r['previous_display'] ?? '')) . '</td>'
            . '<td class="sr-num ' . $cls . '">' . reportDomainProcurementH($change) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportDomainProcurementMonthlyTrend(PDO $pdo, array $filters, int $months = 6): array
{
    $end = strtotime((string) ($filters['end_date'] ?? date('Y-m-d'))) ?: time();
    $rows = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01', $end) . " -{$i} months");
        $startDate = date('Y-m-01', $ts);
        $endDate = date('Y-m-t', $ts);
        $monthFilters = ['start_date' => $startDate, 'end_date' => $endDate];
        $kpis = reportDomainProcurementKpis($pdo, $monthFilters, true);
        $rows[] = [
            'ym' => date('Y-m', $ts),
            'label' => date('M Y', $ts),
            'total' => (float) ($kpis['total_procurement'] ?? 0),
            'domestic' => (float) ($kpis['domestic_value'] ?? 0),
            'import' => (float) ($kpis['import_value'] ?? 0),
            'customs' => (float) ($kpis['customs_value'] ?? 0),
            'count' => (int) ($kpis['purchase_count'] ?? 0),
        ];
    }

    return $rows;
}

function reportDomainProcurementMonthlyTrendTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No multi-month procurement trend data is available.</p>';
    }

    return reportEngineRenderDataTable(
        ['Month', 'Internal', 'International', 'Customs', 'Total', 'Orders'],
        array_map(static fn($r) => [
            (string) ($r['label'] ?? ''),
            salesReportsFormatMoney((float) ($r['domestic'] ?? 0)),
            salesReportsFormatMoney((float) ($r['import'] ?? 0)),
            salesReportsFormatMoney((float) ($r['customs'] ?? 0)),
            salesReportsFormatMoney((float) ($r['total'] ?? 0)),
            number_format((int) ($r['count'] ?? 0)),
        ], $rows)
    );
}

function reportDomainProcurementAchievements(PDO $pdo, array $filters, array $kpis): array
{
    $items = [];
    if ((int) ($kpis['completed_count'] ?? 0) > 0) {
        $items[] = [
            'title' => 'Orders completed',
            'detail' => number_format((int) $kpis['completed_count']) . ' purchase order(s) were received/completed in the period.',
        ];
    }
    if ((float) ($kpis['import_value'] ?? 0) > 0) {
        $items[] = [
            'title' => 'International procurement',
            'detail' => 'International purchase value of ' . salesReportsFormatMoney((float) $kpis['import_value']) . ' was recorded.',
        ];
    }
    if ((float) ($kpis['customs_value'] ?? 0) > 0) {
        $items[] = [
            'title' => 'Customs & clearance activity',
            'detail' => 'Clearance-related costs of ' . salesReportsFormatMoney((float) $kpis['customs_value'])
                . ' across ' . number_format((int) ($kpis['shipment_count'] ?? 0)) . ' shipment(s).',
        ];
    }

    $top = reportDomainProcurementKeyActivities($pdo, $filters, $kpis);
    foreach (array_slice($top, 0, 3) as $act) {
        if ((string) ($act['kind'] ?? '') === 'shipment') {
            continue;
        }
        if ((float) ($act['metric'] ?? 0) <= 0) {
            continue;
        }
        $bits = array_values(array_filter([
            (string) ($act['items'] ?? '') !== '' ? (string) $act['items'] : null,
            !empty($act['supplier']) ? 'Supplier ' . (string) $act['supplier'] : null,
            'Value ' . salesReportsFormatMoney((float) ($act['amount'] ?? $act['metric'] ?? 0)),
        ]));
        $items[] = [
            'title' => (string) ($act['activity'] ?? 'Major purchase'),
            'detail' => implode(' - ', $bits),
        ];
    }

    return $items;
}

function reportDomainProcurementExceptions(PDO $pdo, array $filters, array $kpis): array
{
    $exceptions = [];
    if ((int) ($kpis['delayed_delivery_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'delayed_shipments',
            'message' => (int) $kpis['delayed_delivery_count'] . ' shipment(s) are delayed or past ETA without arrival.',
            'severity' => 'high',
        ];
    }
    if ((int) ($kpis['pending_delivery_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'pending_deliveries',
            'message' => (int) $kpis['pending_delivery_count'] . ' shipment(s) remain in transit / pending delivery.',
            'severity' => 'medium',
        ];
    }
    if ((int) ($kpis['pending_count'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'open_orders',
            'message' => (int) $kpis['pending_count'] . ' purchase order(s) are still open (not received/completed).',
            'severity' => 'medium',
        ];
    }

    return $exceptions;
}

function reportDomainProcurementIsIdle(array $kpis): bool
{
    return ((float) ($kpis['total_procurement'] ?? 0)) <= 0
        && ((int) ($kpis['purchase_count'] ?? 0)) === 0
        && ((int) ($kpis['shipment_count'] ?? 0)) === 0;
}

function reportDomainProcurementProseSection(PDO $pdo, array $report, string $sectionKey): string
{
    $filters = reportEngineFiltersFromReport($report);
    $kpis = reportDomainProcurementKpis($pdo, $filters);
    $idle = reportDomainProcurementIsIdle($kpis);
    $monthLabel = reportDomainProcurementPeriodMonthLabel($filters);
    $periodPhrase = date('d F Y', strtotime($filters['start_date'])) . ' to ' . date('d F Y', strtotime($filters['end_date']));
    $prevFilters = reportDomainProcurementPreviousPeriod($filters);
    $prevKpis = reportDomainProcurementKpis($pdo, $prevFilters, true);
    $exceptions = reportDomainProcurementExceptions($pdo, $filters, $kpis);
    $achievements = reportDomainProcurementAchievements($pdo, $filters, $kpis);
    $activities = reportDomainProcurementKeyActivities($pdo, $filters, $kpis);

    return match ($sectionKey) {
        'executive_summary' => reportDomainProcurementExecutiveSummary($periodPhrase, $idle, $kpis),
        'procurement_activities' => reportDomainProcurementActivitiesProse($monthLabel, $kpis),
        'key_procurement_activities' => reportDomainProcurementActivitiesListProse($activities),
        'suppliers_analysis' => reportDomainProcurementSuppliersProse($pdo, $filters, $kpis),
        'order_status_analysis' => reportDomainProcurementOrderStatusProse($pdo, $filters),
        'key_achievements' => reportDomainProcurementAchievementsProse($achievements, $idle, $monthLabel),
        'challenges_issues', 'operational_challenges' => reportDomainProcurementChallengesProse($kpis, $exceptions),
        'recommendations' => reportDomainProcurementRecommendationsProse($idle, $kpis, $exceptions),
        'period_comparison' => reportDomainProcurementComparisonProse($kpis, $prevKpis, $filters, $prevFilters),
        'procurement_trend' => reportDomainProcurementTrendProse($pdo, $filters),
        'conclusion' => reportDomainProcurementConclusion($idle, $monthLabel, $kpis, $prevKpis),
        default => '<p></p>',
    };
}

function reportDomainProcurementExecutiveSummary(string $periodPhrase, bool $idle, array $kpis): string
{
    if ($idle) {
        return '<p>During ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
            . ', no procurement purchases or shipment clearance activity was recorded in the ERP. '
            . 'Confirm whether purchasing occurred outside the system or whether the selected period has no posted orders.</p>';
    }

    return '<p>During ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
        . ', total procurement amounted to ' . salesReportsFormatMoney((float) ($kpis['total_procurement'] ?? 0))
        . ', comprising internal purchases of ' . salesReportsFormatMoney((float) ($kpis['domestic_value'] ?? 0))
        . ', international purchases of ' . salesReportsFormatMoney((float) ($kpis['import_value'] ?? 0))
        . ', and customs &amp; clearance of ' . salesReportsFormatMoney((float) ($kpis['customs_value'] ?? 0))
        . '. The period covered ' . number_format((int) ($kpis['purchase_count'] ?? 0)) . ' purchase order(s) across '
        . number_format((int) ($kpis['supplier_count'] ?? 0)) . ' supplier(s), with '
        . number_format((int) ($kpis['completed_count'] ?? 0)) . ' completed/received and '
        . number_format((int) ($kpis['pending_count'] ?? 0)) . ' still open.</p>';
}

function reportDomainProcurementActivitiesProse(string $monthLabel, array $kpis): string
{
    $month = htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8');

    return '<p>Procurement activity values for ' . $month . ' are summarized below. Percentages are shares of total procurement '
        . '(internal + international + customs &amp; clearance).</p>';
}

function reportDomainProcurementActivitiesListProse(array $activities): string
{
    if ($activities === []) {
        return '<p>No key procurement activities were available from ERP purchase or shipment records for this period.</p>';
    }

    return '<p>Important procurement activities identified from purchase orders and shipments in the selected period:</p>';
}

function reportDomainProcurementSuppliersProse(PDO $pdo, array $filters, array $kpis): string
{
    $suppliers = reportDomainProcurementSuppliers($pdo, $filters);
    if ($suppliers === []) {
        return '<p>No suppliers recorded procurement transactions in the selected period.</p>';
    }
    $top = array_slice($suppliers, 0, 3);
    $bits = [];
    foreach ($top as $s) {
        $bits[] = htmlspecialchars((string) $s['supplier_name'], ENT_QUOTES, 'UTF-8')
            . ' (' . salesReportsFormatMoney((float) $s['purchase_value']) . ', '
            . number_format((float) $s['pct'], 1) . '%)';
    }

    return '<p>' . number_format(count($suppliers)) . ' supplier(s) had procurement activity in the period. '
        . 'Top by value: ' . implode('; ', $bits) . '.</p>';
}

function reportDomainProcurementOrderStatusProse(PDO $pdo, array $filters): string
{
    $rows = reportDomainProcurementOrderStatus($pdo, $filters);
    if ($rows === []) {
        return '<p>No order status breakdown is available for the selected period.</p>';
    }
    $bits = [];
    foreach (array_slice($rows, 0, 4) as $r) {
        $bits[] = htmlspecialchars((string) ($r['status_label'] ?? $r['status']), ENT_QUOTES, 'UTF-8')
            . ': ' . number_format((int) $r['count'])
            . ' (' . number_format((float) $r['pct'], 1) . '%)';
    }

    return '<p>Order status mix for the period: ' . implode('; ', $bits) . '.</p>';
}

function reportDomainProcurementAchievementsProse(array $achievements, bool $idle, string $monthLabel): string
{
    if ($idle || $achievements === []) {
        return '<p>No data-backed procurement achievements were identified for '
            . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
            . '. Officers may add qualitative achievements in the editor before finalizing the report.</p>';
    }
    $html = '<p>Key achievements supported by ERP procurement records:</p><ul class="sr-achievements">';
    foreach (array_slice($achievements, 0, 6) as $a) {
        $html .= '<li><strong>' . reportDomainProcurementH((string) ($a['title'] ?? 'Achievement'))
            . ':</strong> ' . reportDomainProcurementH((string) ($a['detail'] ?? '')) . '</li>';
    }

    return $html . '</ul>';
}

function reportDomainProcurementChallengesProse(array $kpis, array $exceptions): string
{
    $items = [];
    foreach ($exceptions as $ex) {
        $msg = htmlspecialchars((string) ($ex['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($msg !== '') {
            $items[] = '<li>' . $msg . '</li>';
        }
    }
    if ((int) ($kpis['pending_count'] ?? 0) > 0 && !str_contains(implode('', $items), 'open')) {
        $items[] = '<li>' . number_format((int) $kpis['pending_count']) . ' purchase order(s) remain open.</li>';
    }

    if ($items === []) {
        return '<p>No delayed shipments or open-order exceptions were flagged from ERP data for this period. '
            . 'The Procurement Officer may add operational challenges manually before finalizing.</p>';
    }

    return '<p>Challenges identified from procurement ERP data:</p><ul>' . implode('', $items) . '</ul>'
        . '<p><em>Additional challenges may be entered manually by the Procurement Officer.</em></p>';
}

function reportDomainProcurementRecommendationsProse(bool $idle, array $kpis, array $exceptions): string
{
    if ($idle) {
        return '<ul>'
            . '<li>Confirm whether procurement activity was expected for the selected period.</li>'
            . '<li>Ensure purchase orders and shipments are posted in the ERP when work occurs.</li>'
            . '</ul>'
            . '<p><em>Officers may replace or extend these observations with manual recommendations.</em></p>';
    }

    $items = [];
    if ((int) ($kpis['delayed_delivery_count'] ?? 0) > 0) {
        $items[] = '<li>Strengthen follow-up on delayed shipments and missed ETAs.</li>';
    }
    if ((int) ($kpis['pending_count'] ?? 0) > 0) {
        $items[] = '<li>Prioritize clearance of open purchase orders still awaiting receipt or completion.</li>';
    }
    if ((float) ($kpis['import_value'] ?? 0) <= 0 && (float) ($kpis['domestic_value'] ?? 0) > 0) {
        $items[] = '<li>Where international orders are used, record them as import purchase orders so reporting can separate internal vs international spend accurately.</li>';
    }
    if ($items === []) {
        $items[] = '<li>Maintain supplier follow-up discipline and keep purchase/shipment statuses current.</li>';
        $items[] = '<li>Continue period comparison reviews to spot unusual swings in procurement value.</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>'
        . '<p><em>System observations above are based only on ERP data; officers should edit recommendations as needed.</em></p>';
}

function reportDomainProcurementComparisonProse(
    array $kpis,
    array $prevKpis,
    array $filters,
    array $prevFilters
): string {
    $cur = salesReportsFormatPeriod((string) $filters['start_date'], (string) $filters['end_date']);
    $prev = salesReportsFormatPeriod((string) $prevFilters['start_date'], (string) $prevFilters['end_date']);
    $curTotal = (float) ($kpis['total_procurement'] ?? 0);
    $prevTotal = (float) ($prevKpis['total_procurement'] ?? 0);
    $pct = reportDomainProcurementPctChange($curTotal, $prevTotal);
    $pctLabel = $pct === null ? 'n/a (no prior-period baseline)' : (number_format($pct, 1) . '%');
    $direction = $curTotal > $prevTotal ? 'increased' : ($curTotal < $prevTotal ? 'decreased' : 'was unchanged');

    return '<p>Total procurement ' . $direction . ' from ' . salesReportsFormatMoney($prevTotal)
        . ' in ' . htmlspecialchars($prev, ENT_QUOTES, 'UTF-8')
        . ' to ' . salesReportsFormatMoney($curTotal)
        . ' in ' . htmlspecialchars($cur, ENT_QUOTES, 'UTF-8')
        . ' (' . htmlspecialchars($pctLabel, ENT_QUOTES, 'UTF-8') . '). '
        . 'Internal purchases changed by ' . htmlspecialchars(reportDomainProcurementChangeLabel((float) ($kpis['domestic_value'] ?? 0), (float) ($prevKpis['domestic_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . '; international by ' . htmlspecialchars(reportDomainProcurementChangeLabel((float) ($kpis['import_value'] ?? 0), (float) ($prevKpis['import_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . '; customs &amp; clearance by ' . htmlspecialchars(reportDomainProcurementChangeLabel((float) ($kpis['customs_value'] ?? 0), (float) ($prevKpis['customs_value'] ?? 0)), ENT_QUOTES, 'UTF-8')
        . '.</p>';
}

function reportDomainProcurementTrendProse(PDO $pdo, array $filters): string
{
    $trend = reportDomainProcurementMonthlyTrend($pdo, $filters, 6);
    $withData = array_values(array_filter($trend, static fn($r) => (float) ($r['total'] ?? 0) > 0 || (int) ($r['count'] ?? 0) > 0));
    if ($withData === []) {
        return '<p>No multi-month procurement totals were available for the recent months ending in this reporting period.</p>';
    }
    $sum = 0.0;
    foreach ($withData as $r) {
        $sum += (float) $r['total'];
    }

    return '<p>Across the last ' . count($trend) . ' months ending '
        . htmlspecialchars(reportDomainProcurementPeriodMonthLabel($filters), ENT_QUOTES, 'UTF-8')
        . ', combined procurement totaled ' . salesReportsFormatMoney($sum)
        . ' (months with recorded activity: ' . count($withData) . '). '
        . 'The table below shows internal, international, and customs components by month.</p>';
}

function reportDomainProcurementConclusion(bool $idle, string $monthLabel, array $kpis, array $prevKpis): string
{
    $month = htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8');
    if ($idle) {
        return '<p>' . $month . ' shows no posted procurement purchases or clearance costs in the ERP. '
            . 'Management should confirm capture completeness before treating this as an idle operating month.</p>';
    }
    $pct = reportDomainProcurementPctChange(
        (float) ($kpis['total_procurement'] ?? 0),
        (float) ($prevKpis['total_procurement'] ?? 0)
    );
    $vsPrev = $pct === null
        ? 'Prior-period comparison was limited by missing baseline activity.'
        : ('Versus the previous period, total procurement changed by ' . number_format($pct, 1) . '%.');

    return '<p>' . $month . ' recorded total procurement of '
        . salesReportsFormatMoney((float) ($kpis['total_procurement'] ?? 0))
        . ' across ' . number_format((int) ($kpis['purchase_count'] ?? 0)) . ' order(s). '
        . $vsPrev
        . ' Focus going forward should remain on timely order completion, supplier/shipment follow-up, and accurate classification of import versus domestic purchases.</p>';
}
