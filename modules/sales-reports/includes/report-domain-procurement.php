<?php

declare(strict_types=1);

/**
 * Procurement Department Monthly Report — real ERP purchase / shipment data only.
 * Sources: stocks_purchase_orders, purchases (legacy), shipments, suppliers.
 * Does not include Store/Inventory stock balances or movements.
 */

require_once __DIR__ . '/report-domain-procurement-extra.php';

function reportDomainProcurementSnapshot(PDO $pdo, array $filters): array
{
    $kpis = reportDomainProcurementKpis($pdo, $filters);
    $prevFilters = reportDomainProcurementPreviousPeriod($filters);
    $prevKpis = reportDomainProcurementKpis($pdo, $prevFilters, true);

    return [
        'kpis' => $kpis,
        'prev_kpis' => $prevKpis,
        'prev_filters' => $prevFilters,
        'activity_breakdown' => reportDomainProcurementActivityRows($kpis),
        'key_activities' => reportDomainProcurementKeyActivities($pdo, $filters, $kpis),
        'suppliers' => reportDomainProcurementSuppliers($pdo, $filters),
        'order_status' => reportDomainProcurementOrderStatus($pdo, $filters),
        'period_comparison' => reportDomainProcurementPeriodComparisonRows($kpis, $prevKpis, $filters, $prevFilters),
        'monthly_trend' => reportDomainProcurementMonthlyTrend($pdo, $filters, 6),
        'achievements' => reportDomainProcurementAchievements($pdo, $filters, $kpis),
        'exceptions' => reportDomainProcurementExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainProcurementAvailableSections($kpis),
    ];
}

function reportDomainProcurementAvailableSections(array $kpis): array
{
    return reportEngineDefaultSections('procurement');
}

function reportDomainProcurementErpMenu(): array
{
    return [
        'Summary' => [
            'procurement_summary' => 'Procurement Summary KPIs',
            'period_comparison' => 'Period Comparison',
            'monthly_trend' => 'Multi-Month Trend',
        ],
        'Analysis' => [
            'key_activities' => 'Key Procurement Activities',
            'suppliers' => 'Suppliers (Period)',
            'order_status' => 'Order Status',
            'pending_deliveries' => 'Pending / Delayed Shipments',
        ],
    ];
}

function reportDomainProcurementFetch(PDO $pdo, string $source, array $filters): array
{
    $snapshot = reportDomainProcurementSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];

    return match ($source) {
        'procurement_summary', 'procurement_overview_table' => [
            'html' => reportDomainProcurementOverviewTableHtml($kpis, $filters),
            'snapshot' => $kpis,
        ],
        'period_comparison' => [
            'html' => reportDomainProcurementPeriodComparisonTableHtml($snapshot['period_comparison'] ?? []),
            'snapshot' => $snapshot['period_comparison'] ?? [],
        ],
        'monthly_trend' => [
            'html' => reportDomainProcurementMonthlyTrendTableHtml($snapshot['monthly_trend'] ?? []),
            'snapshot' => $snapshot['monthly_trend'] ?? [],
        ],
        'key_activities' => [
            'html' => reportDomainProcurementKeyActivitiesTableHtml($snapshot['key_activities'] ?? []),
            'snapshot' => $snapshot['key_activities'] ?? [],
        ],
        'suppliers' => [
            'html' => reportDomainProcurementSuppliersTableHtml($snapshot['suppliers'] ?? [], $kpis),
            'snapshot' => $snapshot['suppliers'] ?? [],
        ],
        'order_status' => [
            'html' => reportDomainProcurementOrderStatusTableHtml($snapshot['order_status'] ?? []),
            'snapshot' => $snapshot['order_status'] ?? [],
        ],
        'pending_deliveries' => [
            'html' => reportDomainProcurementPendingDeliveriesTableHtml(
                reportDomainProcurementPendingDeliveries($pdo, $filters)
            ),
            'snapshot' => [],
        ],
        default => ['html' => '<p>Unknown procurement data source.</p>', 'snapshot' => []],
    };
}

function reportDomainProcurementKpis(PDO $pdo, array $filters, bool $lite = false): array
{
    $empty = [
        'purchase_count' => 0,
        'domestic_count' => 0,
        'import_count' => 0,
        'domestic_value' => 0.0,
        'import_value' => 0.0,
        'customs_value' => 0.0,
        'total_procurement' => 0.0,
        'domestic_pct' => null,
        'import_pct' => null,
        'customs_pct' => null,
        'supplier_count' => 0,
        'completed_count' => 0,
        'pending_count' => 0,
        'cancelled_count' => 0,
        'pending_delivery_count' => 0,
        'delayed_delivery_count' => 0,
        'shipment_count' => 0,
        'exceptions_count' => 0,
        'data_quality_notes' => [],
    ];

    $hasSpo = tableExists('stocks_purchase_orders', $pdo);
    $hasPurchases = tableExists('purchases', $pdo);
    if (!$hasSpo && !$hasPurchases) {
        $empty['data_quality_notes'][] = 'No purchase tables found (stocks_purchase_orders / purchases).';

        return $empty;
    }

    $notes = [];
    $domesticValue = 0.0;
    $importValue = 0.0;
    $domesticCount = 0;
    $importCount = 0;
    $purchaseCount = 0;
    $supplierIds = [];
    $completed = 0;
    $pending = 0;
    $cancelled = 0;

    // Current stock POs
    if ($hasSpo) {
        $dateExpr = reportDomainProcurementSpoDateExpr($pdo);
        $sql = "SELECT
                    LOWER(TRIM(COALESCE(purchase_type, 'domestic'))) AS ptype,
                    LOWER(TRIM(COALESCE(status, ''))) AS st,
                    supplier_id,
                    COALESCE(total_amount, 0) AS amount
                FROM stocks_purchase_orders
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $status = (string) ($row['st'] ?? '');
                if (in_array($status, ['cancelled', 'canceled'], true)) {
                    $cancelled++;
                    continue;
                }
                $amount = (float) ($row['amount'] ?? 0);
                $type = (string) ($row['ptype'] ?? 'domestic');
                if ($type === 'import') {
                    $importValue += $amount;
                    $importCount++;
                } else {
                    $domesticValue += $amount;
                    $domesticCount++;
                }
                $purchaseCount++;
                if (!empty($row['supplier_id'])) {
                    $supplierIds[(int) $row['supplier_id']] = true;
                }
                if (in_array($status, ['received', 'completed', 'closed'], true)) {
                    $completed++;
                } else {
                    $pending++;
                }
            }
        } catch (Throwable $e) {
            $notes[] = 'Could not query stocks_purchase_orders.';
        }
    }

    // Legacy purchases — ERP purchase list classifies these as domestic
    if ($hasPurchases) {
        $dateExpr = reportDomainProcurementLegacyDateExpr($pdo, 'p');
        $sql = "SELECT
                    LOWER(TRIM(COALESCE(p.status, ''))) AS st,
                    p.supplier_id,
                    COALESCE(p.total_amount, 0) AS amount,
                    COALESCE(p.shipment_id, 0) AS shipment_id
                FROM purchases p
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $status = (string) ($row['st'] ?? '');
                if (in_array($status, ['cancelled', 'canceled'], true)) {
                    $cancelled++;
                    continue;
                }
                $amount = (float) ($row['amount'] ?? 0);
                $hasShip = (int) ($row['shipment_id'] ?? 0) > 0;
                // Linked shipment => treat as international; otherwise domestic (ERP convention for legacy).
                if ($hasShip) {
                    $importValue += $amount;
                    $importCount++;
                } else {
                    $domesticValue += $amount;
                    $domesticCount++;
                }
                $purchaseCount++;
                if (!empty($row['supplier_id'])) {
                    $supplierIds[(int) $row['supplier_id']] = true;
                }
                if (in_array($status, ['received', 'completed', 'closed'], true)) {
                    $completed++;
                } else {
                    $pending++;
                }
            }
        } catch (Throwable $e) {
            $notes[] = 'Could not query purchases.';
        }
        if (!$lite) {
            $notes[] = 'Legacy purchase records without a linked shipment are classified as Internal (domestic), matching the ERP purchases list.';
        }
    }

    $customs = 0.0;
    $shipmentCount = 0;
    $pendingDelivery = 0;
    $delayedDelivery = 0;
    if (tableExists('shipments', $pdo)) {
        $shipDate = reportDomainProcurementShipmentDateExpr($pdo);
        $clearanceExpr = reportDomainProcurementClearanceExpr($pdo);
        try {
            $sql = "SELECT COUNT(*) AS cnt,
                           COALESCE(SUM({$clearanceExpr}), 0) AS customs_value,
                           SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('pending','confirmed','shipped','in_transit','arrived_at_port','in_customs','on_way') THEN 1 ELSE 0 END) AS pending_delivery,
                           SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'delayed'
                                    OR (eta IS NOT NULL AND eta < CURDATE()
                                        AND actual_arrival_date IS NULL
                                        AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('delivered','cancelled','canceled'))
                               THEN 1 ELSE 0 END) AS delayed_delivery
                    FROM shipments
                    WHERE DATE({$shipDate}) BETWEEN ? AND ?";
            $st = $pdo->prepare($sql);
            $st->execute([$filters['start_date'], $filters['end_date']]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $shipmentCount = (int) ($row['cnt'] ?? 0);
            $customs = (float) ($row['customs_value'] ?? 0);
            $pendingDelivery = (int) ($row['pending_delivery'] ?? 0);
            $delayedDelivery = (int) ($row['delayed_delivery'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = 'Could not query shipment clearance costs.';
        }
    }

    $total = $domesticValue + $importValue + $customs;
    $pct = static function (float $part, float $all): ?float {
        return $all > 0 ? round(($part / $all) * 100, 1) : null;
    };

    if ($purchaseCount > 0 && $importCount === 0) {
        $notes[] = 'No import purchase orders were found in this period (stocks_purchase_orders.purchase_type = import, or purchases linked to a shipment).';
    }

    $kpisPartial = [
        'total_procurement' => $total,
        'pending_count' => $pending,
        'delayed_delivery_count' => $delayedDelivery,
        'pending_delivery_count' => $pendingDelivery,
    ];
    $exceptions = $lite ? [] : reportDomainProcurementExceptions($pdo, $filters, $kpisPartial);

    return [
        'purchase_count' => $purchaseCount,
        'domestic_count' => $domesticCount,
        'import_count' => $importCount,
        'domestic_value' => round($domesticValue, 2),
        'import_value' => round($importValue, 2),
        'customs_value' => round($customs, 2),
        'total_procurement' => round($total, 2),
        'domestic_pct' => $pct($domesticValue, $total),
        'import_pct' => $pct($importValue, $total),
        'customs_pct' => $pct($customs, $total),
        'supplier_count' => count($supplierIds),
        'completed_count' => $completed,
        'pending_count' => $pending,
        'cancelled_count' => $cancelled,
        'pending_delivery_count' => $pendingDelivery,
        'delayed_delivery_count' => $delayedDelivery,
        'shipment_count' => $shipmentCount,
        'exceptions_count' => count($exceptions),
        'data_quality_notes' => array_values(array_unique($notes)),
    ];
}
