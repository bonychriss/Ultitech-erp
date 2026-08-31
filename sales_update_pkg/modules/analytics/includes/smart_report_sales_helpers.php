<?php
/**
 * Smart Report � Cumulative Sales drill-down metrics.
 */

require_once __DIR__ . '/analytics_company_scope.php';

if (!function_exists('smart_report_sales_sync_territory_mappings')) {
    function smart_report_sales_sync_territory_mappings(PDO $pdo, string $territoryCol): void
    {
        // Create table if not exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_territory_mappings (
                raw_name VARCHAR(255) NOT NULL PRIMARY KEY,
                normalized_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('smart_report_sales_sync_territory_mappings table creation failed: ' . $e->getMessage());
            return;
        }

        // Fetch all unique raw names from customers.city / customers.country
        try {
            $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(c.{$territoryCol}), ''), 'Unassigned') FROM customers c";
            $params = [];
            if (function_exists('analytics_scoped_tables')) {
                analytics_scoped_tables($sql, $params, ['c' => 'customers'], $pdo);
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $allRaw = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            error_log('smart_report_sales_sync_territory_mappings fetch failed: ' . $e->getMessage());
            return;
        }

        if (empty($allRaw)) {
            return;
        }

        // Fetch existing mappings
        try {
            $st = $pdo->query("SELECT raw_name, normalized_name FROM ai_territory_mappings");
            $mappings = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            error_log('smart_report_sales_sync_territory_mappings select mappings failed: ' . $e->getMessage());
            return;
        }

        // Identify unmapped names
        $unmapped = [];
        foreach ($allRaw as $raw) {
            if (!isset($mappings[$raw])) {
                $unmapped[] = $raw;
            }
        }

        if (empty($unmapped)) {
            return;
        }

        // Try to map them using OpenAI if enabled, else use local capitalization
        $newMappings = [];
        $aiHelpersPath = __DIR__ . '/../../includes/ai_helpers.php';
        if (is_file($aiHelpersPath)) {
            require_once $aiHelpersPath;
        }

        if (function_exists('ai_fetch_settings_row') && function_exists('ai_openai_request')) {
            try {
                $settings = ai_fetch_settings_row();
                if ($settings && (int)($settings['is_enabled'] ?? 0)) {
                    $prompt = "You are a data cleaning assistant. Analyze this list of territory/city names and group similar, misspelled, or differently-cased names into a single clean, canonicalized version (e.g. 'Dar es Salaam', 'Morogoro', 'Arusha', 'Zanzibar', 'Pwani', 'Mtwara', 'Tanga', 'Iringa', 'Mwanza'). Keep other valid city names but normalize their casing (e.g. 'MOROGORO' -> 'Morogoro').
Return a valid JSON object where the keys are the original names and the values are the normalized/canonical names. Do not include markdown code block formatting (like ```json ... ```) - return ONLY the raw JSON string.

Input list: " . json_encode($unmapped);

                    $messages = [
                        ['role' => 'system', 'content' => 'You only output valid JSON. No conversational text, no markdown formatting.'],
                        ['role' => 'user', 'content' => $prompt]
                    ];

                    $result = ai_openai_request($messages);
                    $decoded = json_decode($result['content'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        foreach ($unmapped as $raw) {
                            if (isset($decoded[$raw])) {
                                $newMappings[$raw] = trim((string)$decoded[$raw]);
                            }
                        }
                    } else {
                        error_log('smart_report_sales_sync_territory_mappings JSON decode failed: ' . json_last_error_msg());
                    }
                }
            } catch (Throwable $e) {
                error_log('smart_report_sales_sync_territory_mappings AI call failed: ' . $e->getMessage());
            }
        }

        // Local/Fallback normalization for any remaining/unmapped keys
        foreach ($unmapped as $raw) {
            if (!isset($newMappings[$raw])) {
                $trimmed = trim((string)$raw);
                if ($trimmed === '' || strtolower($trimmed) === 'unassigned') {
                    $normalized = 'Unassigned';
                } else {
                    $normalized = ucwords(strtolower($trimmed));
                }
                $newMappings[$raw] = $normalized;
            }
        }

        // Insert new mappings into database
        if (!empty($newMappings)) {
            try {
                $st = $pdo->prepare("INSERT INTO ai_territory_mappings (raw_name, normalized_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE normalized_name = VALUES(normalized_name)");
                foreach ($newMappings as $raw => $normalized) {
                    $st->execute([$raw, $normalized]);
                }
            } catch (Throwable $e) {
                error_log('smart_report_sales_sync_territory_mappings insert failed: ' . $e->getMessage());
            }
        }
    }
}


if (!function_exists('smart_report_sales_drilldown')) {
    function smart_report_sales_drilldown(PDO $pdo, ?array $filters = null): array
    {
        $rangeStart = ($filters['start_date'] ?? null) ?: date('Y-01-01');
        $rangeEnd = ($filters['end_date'] ?? null) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart)) {
            $rangeStart = date('Y-01-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
            $rangeEnd = date('Y-m-d');
        }

        $out = [
            'has_data' => false,
            'period' => [
                'start_date' => $rangeStart,
                'end_date' => $rangeEnd,
                'label' => date('M j, Y', strtotime($rangeStart))
                    . ' – '
                    . date('M j, Y', strtotime($rangeEnd)),
            ],
            'summary' => [
                'total_revenue' => 0.0,
                'total_collected' => 0.0,
                'outstanding' => 0.0,
                'invoice_count' => 0,
            ],
            'revenue_by_month' => [],
            'revenue_by_segment' => [],
            'revenue_by_territory' => [],
            'revenue_by_product' => [],
            'gross_profit' => [
                'revenue' => 0.0,
                'cogs' => 0.0,
                'gross_profit' => 0.0,
                'margin_pct' => 0.0,
            ],
            'sales_performance' => [
                'team_target' => 0.0,
                'company_target_admin' => 0.0,
                'has_company_target' => false,
                'team_actual' => 0.0,
                'achievement_pct' => null,
                'rep_count' => 0,
                'reps_on_track' => 0,
                'has_targets' => false,
                'uses_company_target_share' => false,
                'reps' => [],
            ],
            'top_customers' => [],
            'dormant_products' => [],
            'fulfillment' => [
                'total_orders' => 0,
                'delivered' => 0,
                'on_time_rate' => null,
                'avg_lead_days' => null,
                'avg_order_to_cash_days' => null,
            ],
            'pipeline' => [
                'count' => 0,
                'value' => 0.0,
                'items' => [],
            ],
            'ar_aging' => [
                'current' => 0.0,
                'days_1_30' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'days_90_plus' => 0.0,
                'total_outstanding' => 0.0,
            ],
        ];

        if (!tableExists('invoices', $pdo)) {
            return $out;
        }

        $out['has_data'] = true;
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
        $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
        $yearStart = date('Y-01-01');
        $priorYearStart = date('Y-01-01', strtotime('-1 year'));
        $priorYearEnd = date('Y-m-d', strtotime('-1 year'));

        try {
            $sql = "SELECT COALESCE(SUM(total_amount), 0), COALESCE(SUM(amount_paid), 0),
                        COALESCE(SUM(balance_due), 0), COUNT(*)
                 FROM invoices WHERE status != 'cancelled'
                   AND invoice_date BETWEEN ? AND ?";
            $params = [$rangeStart, $rangeEnd];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0];
            $out['summary'] = [
                'total_revenue' => (float) ($row[0] ?? 0),
                'total_collected' => (float) ($row[1] ?? 0),
                'outstanding' => (float) ($row[2] ?? 0),
                'invoice_count' => (int) ($row[3] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('smart_report_sales_drilldown summary: ' . $e->getMessage());
        }

        try {
            $sql = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym,
                        COALESCE(SUM(total_amount), 0) AS revenue,
                        COUNT(*) AS invoice_count
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND invoice_date BETWEEN ? AND ?";
            $params = [$rangeStart, $rangeEnd];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $sql .= ' GROUP BY ym ORDER BY ym ASC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $out['revenue_by_month'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('smart_report_sales_drilldown monthly: ' . $e->getMessage());
        }

        if (tableExists('customers', $pdo) && columnExists('customers', 'customer_type', $pdo)) {
            try {
                $sql = "SELECT COALESCE(c.customer_type, 'other') AS segment,
                            COALESCE(SUM(i.total_amount), 0) AS revenue,
                            COUNT(i.id) AS invoice_count
                     FROM invoices i
                     LEFT JOIN customers c ON c.id = i.customer_id
                     WHERE i.status != 'cancelled'
                       AND i.invoice_date BETWEEN ? AND ?";
                $params = [$rangeStart, $rangeEnd];
                analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                $sql .= ' GROUP BY segment ORDER BY revenue DESC';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $out['revenue_by_segment'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('smart_report_sales_drilldown segment: ' . $e->getMessage());
            }
        }

        if (tableExists('customers', $pdo)) {
            $territoryCol = columnExists('customers', 'city', $pdo) ? 'city' : (columnExists('customers', 'country', $pdo) ? 'country' : null);
            if ($territoryCol) {
                try {
                    smart_report_sales_sync_territory_mappings($pdo, $territoryCol);
                    $sql = "SELECT COALESCE(m.normalized_name, COALESCE(NULLIF(TRIM(c.{$territoryCol}), ''), 'Unassigned')) AS territory,
                                COALESCE(SUM(i.total_amount), 0) AS revenue
                         FROM invoices i
                         LEFT JOIN customers c ON c.id = i.customer_id
                         LEFT JOIN ai_territory_mappings m ON m.raw_name = COALESCE(NULLIF(TRIM(c.{$territoryCol}), ''), 'Unassigned')
                         WHERE i.status != 'cancelled'
                           AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$rangeStart, $rangeEnd];
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                    $sql .= ' GROUP BY territory ORDER BY revenue DESC LIMIT 15';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $out['revenue_by_territory'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                    error_log('smart_report_sales_drilldown territory: ' . $e->getMessage());
                }
            }
        }

        if (tableExists('sales_order_items', $pdo) && tableExists('sales_orders', $pdo) && tableExists('products', $pdo)) {
            try {
                $costCol = '0';
                foreach (['purchase_price', 'cost_price', 'buying_price', 'unit_price', 'price'] as $col) {
                    if (columnExists('products', $col, $pdo)) {
                        $costCol = 'p.' . $col;
                        break;
                    }
                }
                $costExpr = "COALESCE({$costCol}, 0)";
                $sql = "SELECT COALESCE(p.name, 'Unknown') AS product_name,
                            COALESCE(SUM(soi.line_total), 0) AS revenue,
                            COALESCE(SUM(soi.quantity * {$costExpr}), 0) AS cogs
                     FROM sales_order_items soi
                     INNER JOIN sales_orders so ON so.id = soi.order_id
                     INNER JOIN products p ON p.id = soi.product_id
                     WHERE so.status NOT IN ('cancelled', 'draft')
                       AND so.quote_date BETWEEN ? AND ?";
                $params = [$rangeStart, $rangeEnd];
                analytics_scoped_tables($sql, $params, [
                    'soi' => 'sales_order_items',
                    'so' => 'sales_orders',
                    'p' => 'products',
                ], $pdo);
                $sql .= ' GROUP BY p.id, p.name ORDER BY revenue DESC LIMIT 15';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $out['revenue_by_product'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $sqlGp = "SELECT COALESCE(SUM(soi.line_total), 0) AS revenue,
                            COALESCE(SUM(soi.quantity * {$costExpr}), 0) AS cogs
                     FROM sales_order_items soi
                     INNER JOIN sales_orders so ON so.id = soi.order_id
                     INNER JOIN products p ON p.id = soi.product_id
                     WHERE so.status NOT IN ('cancelled', 'draft')
                       AND so.quote_date BETWEEN ? AND ?";
                $paramsGp = [$rangeStart, $rangeEnd];
                analytics_scoped_tables($sqlGp, $paramsGp, [
                    'soi' => 'sales_order_items',
                    'so' => 'sales_orders',
                    'p' => 'products',
                ], $pdo);
                $stGp = $pdo->prepare($sqlGp);
                $stGp->execute($paramsGp);
                $gpRow = $stGp ? $stGp->fetch(PDO::FETCH_ASSOC) : [];
                $rev = (float) ($gpRow['revenue'] ?? 0);
                $cogs = (float) ($gpRow['cogs'] ?? 0);
                if ($rev <= 0) {
                    $rev = $out['summary']['total_revenue'];
                }
                $gp = $rev - $cogs;
                $out['gross_profit'] = [
                    'revenue' => $rev,
                    'cogs' => $cogs,
                    'gross_profit' => $gp,
                    'margin_pct' => $rev > 0 ? round(($gp / $rev) * 100, 1) : 0.0,
                ];
            } catch (Throwable $e) {
                error_log('smart_report_sales_drilldown product/cogs: ' . $e->getMessage());
            }
        }
        if ($out['gross_profit']['revenue'] <= 0 && $out['summary']['total_revenue'] > 0) {
            $rev = $out['summary']['total_revenue'];
            $cogs = (float) ($out['gross_profit']['cogs'] ?? 0);
            $gp = $rev - $cogs;
            $out['gross_profit'] = [
                'revenue' => $rev,
                'cogs' => $cogs,
                'gross_profit' => $gp,
                'margin_pct' => $rev > 0 ? round(($gp / $rev) * 100, 1) : 0.0,
            ];
        }

        try {
            $out['sales_performance'] = smart_report_sales_team_performance_data(
                $pdo,
                ['start_date' => $rangeStart, 'end_date' => $rangeEnd]
            );
        } catch (Throwable $e) {
            error_log('smart_report_sales_drilldown sales_performance: ' . $e->getMessage());
        }

        if (tableExists('customers', $pdo)) {
            try {
                $gpJoin = tableExists('sales_order_items', $pdo) && tableExists('sales_orders', $pdo) && tableExists('products', $pdo);
                $costCol = '0';
                foreach (['purchase_price', 'cost_price', 'buying_price', 'unit_price', 'price'] as $col) {
                    if (columnExists('products', $col, $pdo)) {
                        $costCol = 'p.' . $col;
                        break;
                    }
                }
                $costExpr = "COALESCE({$costCol}, 0)";
                $sql = $gpJoin
                    ? "SELECT COALESCE(c.company_name, 'Walk-in') AS customer_name,
                              COUNT(DISTINCT i.id) AS invoice_count,
                              COALESCE(SUM(i.total_amount), 0) AS revenue,
                              COALESCE(SUM(i.amount_paid), 0) AS collected,
                              COALESCE(SUM(soi.quantity * {$costExpr}), 0) AS cogs
                       FROM invoices i
                       LEFT JOIN customers c ON c.id = i.customer_id
                       LEFT JOIN sales_orders so ON so.id = i.order_id
                       LEFT JOIN sales_order_items soi ON soi.order_id = so.id
                       LEFT JOIN products p ON p.id = soi.product_id
                       WHERE i.status != 'cancelled'
                         AND i.invoice_date BETWEEN ? AND ?"
                    : "SELECT COALESCE(c.company_name, 'Walk-in') AS customer_name,
                              COUNT(i.id) AS invoice_count,
                              COALESCE(SUM(i.total_amount), 0) AS revenue,
                              COALESCE(SUM(i.amount_paid), 0) AS collected,
                              0 AS cogs
                       FROM invoices i
                       LEFT JOIN customers c ON c.id = i.customer_id
                       WHERE i.status != 'cancelled'
                         AND i.invoice_date BETWEEN ? AND ?";
                $params = [$rangeStart, $rangeEnd];
                if ($gpJoin) {
                    analytics_scoped_tables($sql, $params, [
                        'i' => 'invoices',
                        'c' => 'customers',
                        'so' => 'sales_orders',
                        'soi' => 'sales_order_items',
                        'p' => 'products',
                    ], $pdo);
                } else {
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                }
                $sql .= ' GROUP BY c.id, c.company_name ORDER BY revenue DESC LIMIT 15';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $customers = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                foreach ($customers as &$c) {
                    $rev = (float) $c['revenue'];
                    $cogs = (float) $c['cogs'];
                    $c['gross_margin_pct'] = $rev > 0 ? round((($rev - $cogs) / $rev) * 100, 1) : 0.0;
                    $c['outstanding'] = $rev - (float) $c['collected'];
                }
                unset($c);
                $out['top_customers'] = $customers;
            } catch (Throwable $e) {
                error_log('smart_report_sales_drilldown customers: ' . $e->getMessage());
            }
        }

        if (tableExists('products', $pdo) && tableExists('sales_order_items', $pdo) && tableExists('sales_orders', $pdo)) {
            try {
                $sql = "SELECT p.name AS product_name,
                            MAX(so.quote_date) AS last_sold,
                            DATEDIFF(?, MAX(so.quote_date)) AS days_since_sale
                     FROM products p
                     INNER JOIN sales_order_items soi ON soi.product_id = p.id
                     INNER JOIN sales_orders so ON so.id = soi.order_id
                     WHERE so.status NOT IN ('cancelled')
                       AND so.quote_date BETWEEN ? AND ?";
                $params = [$rangeEnd, $rangeStart, $rangeEnd];
                analytics_scoped_tables($sql, $params, [
                    'p' => 'products',
                    'soi' => 'sales_order_items',
                    'so' => 'sales_orders',
                ], $pdo);
                $sql .= ' GROUP BY p.id, p.name
                     HAVING days_since_sale >= 90
                     ORDER BY days_since_sale DESC
                     LIMIT 10';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $out['dormant_products'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable $e) {
                error_log('smart_report_sales_drilldown dormant: ' . $e->getMessage());
            }
        }

        if (tableExists('sales_orders', $pdo)) {
            $hasOrderDate = columnExists('sales_orders', 'order_date', $pdo);
            $orderDateExpr = $hasOrderDate ? 'COALESCE(order_date, quote_date, DATE(created_at))' : 'COALESCE(quote_date, DATE(created_at))';
            $openStatuses = "('draft', 'quotation', 'confirmed', 'processing', 'on_hold')";
            try {
                $sql = "SELECT COUNT(*) AS total,
                            SUM(CASE WHEN status IN ('delivered', 'paid', 'invoiced', 'shipped') THEN 1 ELSE 0 END) AS fulfilled
                     FROM sales_orders
                     WHERE status != 'cancelled'
                       AND {$orderDateExpr} BETWEEN ? AND ?";
                $params = [$rangeStart, $rangeEnd];
                analytics_append_company_scope($sql, $params, 'sales_orders', '', $pdo);
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $f = $st ? $st->fetch(PDO::FETCH_ASSOC) : [];
                $total = (int) ($f['total'] ?? 0);
                $fulfilled = (int) ($f['fulfilled'] ?? 0);
                $out['fulfillment']['total_orders'] = $total;
                $out['fulfillment']['delivered'] = $fulfilled;
                $out['fulfillment']['on_time_rate'] = $total > 0 ? round(($fulfilled / $total) * 100, 1) : null;

                try {
                    $sql2 = "SELECT AVG(DATEDIFF(DATE(COALESCE(shipped_at, updated_at)), {$orderDateExpr})) AS avg_days
                         FROM sales_orders
                         WHERE status IN ('delivered', 'paid', 'invoiced', 'shipped')
                           AND {$orderDateExpr} BETWEEN ? AND ?";
                    $params2 = [$rangeStart, $rangeEnd];
                    analytics_append_company_scope($sql2, $params2, 'sales_orders', '', $pdo);
                    $st2 = $pdo->prepare($sql2);
                    $st2->execute($params2);
                    $avgLead = $st2 ? $st2->fetchColumn() : null;
                    $out['fulfillment']['avg_lead_days'] = $avgLead !== null ? round((float) $avgLead, 1) : null;
                } catch (Throwable $e) {
                    error_log('smart_report_sales_drilldown avg_lead_days: ' . $e->getMessage());
                }

                if (tableExists('invoices', $pdo)) {
                    $sql3 = "SELECT AVG(DATEDIFF(DATE(COALESCE(paid_at, updated_at)), invoice_date)) AS avg_days
                         FROM invoices
                         WHERE status = 'paid'
                           AND invoice_date IS NOT NULL
                           AND invoice_date BETWEEN ? AND ?";
                    $params3 = [$rangeStart, $rangeEnd];
                    analytics_append_company_scope($sql3, $params3, 'invoices', '', $pdo);
                    $st3 = $pdo->prepare($sql3);
                    $st3->execute($params3);
                    $otc = $st3 ? $st3->fetchColumn() : null;
                    $out['fulfillment']['avg_order_to_cash_days'] = $otc !== null ? round((float) $otc, 1) : null;
                }

                $sql4 = "SELECT so.order_number, COALESCE(c.company_name, 'Customer') AS customer_name,
                            so.status, so.total_amount, so.quote_date
                     FROM sales_orders so
                     LEFT JOIN customers c ON c.id = so.customer_id
                     WHERE so.status IN {$openStatuses}
                       AND so.quote_date BETWEEN ? AND ?";
                $params4 = [$rangeStart, $rangeEnd];
                analytics_scoped_tables($sql4, $params4, ['so' => 'sales_orders', 'c' => 'customers'], $pdo);
                $sql4 .= ' ORDER BY so.total_amount DESC LIMIT 8';
                $st4 = $pdo->prepare($sql4);
                $st4->execute($params4);
                $pipelineRows = $st4 ? ($st4->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                $pipelineValue = 0.0;
                foreach ($pipelineRows as $pr) {
                    $pipelineValue += (float) $pr['total_amount'];
                }
                $sql5 = "SELECT COUNT(*), COALESCE(SUM(total_amount), 0)
                     FROM sales_orders
                     WHERE status IN {$openStatuses}
                       AND quote_date BETWEEN ? AND ?";
                $params5 = [$rangeStart, $rangeEnd];
                analytics_append_company_scope($sql5, $params5, 'sales_orders', '', $pdo);
                $st5 = $pdo->prepare($sql5);
                $st5->execute($params5);
                $pc = $st5 ? $st5->fetch(PDO::FETCH_NUM) : [count($pipelineRows), $pipelineValue];
                $out['pipeline'] = [
                    'count' => (int) ($pc[0] ?? 0),
                    'value' => (float) ($pc[1] ?? 0),
                    'items' => $pipelineRows,
                ];
            } catch (Throwable $e) {
                error_log('smart_report_sales_drilldown fulfillment: ' . $e->getMessage());
            }
        }

        try {
            $dueCol = columnExists('invoices', 'due_date', $pdo) ? 'due_date' : 'invoice_date';
            $sql = "SELECT balance_due,
                        DATEDIFF(?, {$dueCol}) AS days_overdue
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND balance_due > 0
                   AND invoice_date BETWEEN ? AND ?";
            $params = [$rangeEnd, $rangeStart, $rangeEnd];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $aging = [
                'current' => 0.0,
                'days_1_30' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'days_90_plus' => 0.0,
                'total_outstanding' => 0.0,
            ];
            foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $inv) {
                $bal = (float) $inv['balance_due'];
                $days = (int) $inv['days_overdue'];
                $aging['total_outstanding'] += $bal;
                if ($days <= 0) {
                    $aging['current'] += $bal;
                } elseif ($days <= 30) {
                    $aging['days_1_30'] += $bal;
                } elseif ($days <= 60) {
                    $aging['days_31_60'] += $bal;
                } elseif ($days <= 90) {
                    $aging['days_61_90'] += $bal;
                } else {
                    $aging['days_90_plus'] += $bal;
                }
            }
            $out['ar_aging'] = $aging;
        } catch (Throwable $e) {
            error_log('smart_report_sales_drilldown aging: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('smart_report_pct_label')) {
    function smart_report_pct_label(?float $pct): string
    {
        if ($pct === null) {
            return 'N/A';
        }
        $sign = $pct > 0 ? '+' : '';
        return $sign . number_format($pct, 1) . '%';
    }
}

if (!function_exists('smart_report_pct_tone')) {
    function smart_report_pct_tone(?float $pct): string
    {
        if ($pct === null) {
            return 'neutral';
        }
        if ($pct > 0) {
            return 'up';
        }
        if ($pct < 0) {
            return 'down';
        }
        return 'neutral';
    }
}

if (!function_exists('smart_report_sales_admin_company_target')) {
    function smart_report_sales_admin_company_target(PDO $pdo, string $year): float
    {
        if (!tableExists('sales_targets', $pdo)) {
            return 0.0;
        }

        try {
            $sql = 'SELECT target_amount FROM sales_targets WHERE user_id = 0 AND period = ?';
            $params = [$year];
            analytics_append_company_scope($sql, $params, 'sales_targets', '', $pdo);
            $stCompany = $pdo->prepare($sql);
            $stCompany->execute($params);
            return (float) ($stCompany->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('smart_report_sales_company_period_target')) {
    function smart_report_sales_company_period_target(PDO $pdo, array $months, string $year): float
    {
        if (!tableExists('sales_targets', $pdo)) {
            return 0.0;
        }

        try {
            $companyYearly = smart_report_sales_admin_company_target($pdo, $year);
            if ($companyYearly > 0 && count($months) > 0) {
                return $companyYearly * (count($months) / 12);
            }

            return $companyYearly;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('smart_report_sales_rep_period_target')) {
    function smart_report_sales_rep_period_target(PDO $pdo, int $userId, array $months, string $year): float
    {
        if ($userId <= 0 || !tableExists('sales_targets', $pdo)) {
            return 0.0;
        }

        try {
            $sql = 'SELECT target_amount FROM sales_targets WHERE user_id = ? AND period = ?';
            $params = [$userId, $year];
            analytics_append_company_scope($sql, $params, 'sales_targets', '', $pdo);
            $stYear = $pdo->prepare($sql);
            $stYear->execute($params);
            $yearly = (float) ($stYear->fetchColumn() ?: 0);
            if ($yearly > 0) {
                return count($months) > 0 ? $yearly * (count($months) / 12) : $yearly;
            }

            if (empty($months)) {
                return 0.0;
            }

            $placeholders = implode(',', array_fill(0, count($months), '?'));
            $params = array_merge([$userId], $months);
            $sql = "SELECT COALESCE(SUM(target_amount), 0)
                 FROM sales_targets
                 WHERE user_id = ? AND period IN ({$placeholders})";
            analytics_append_company_scope($sql, $params, 'sales_targets', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);

            return (float) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('smart_report_sales_period_team_target')) {
    function smart_report_sales_period_team_target(PDO $pdo, array $months, string $year): float
    {
        if (!tableExists('sales_targets', $pdo)) {
            return 0.0;
        }

        try {
            if (!empty($months)) {
                $placeholders = implode(',', array_fill(0, count($months), '?'));
                $sql = "SELECT COALESCE(SUM(target_amount), 0)
                     FROM sales_targets
                     WHERE user_id != 0 AND period IN ({$placeholders})";
                $params = $months;
                analytics_append_company_scope($sql, $params, 'sales_targets', '', $pdo);
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $monthlyTotal = (float) ($st->fetchColumn() ?: 0);
                if ($monthlyTotal > 0) {
                    return $monthlyTotal;
                }
            }

            return smart_report_sales_company_period_target($pdo, $months, $year);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('smart_report_sales_is_sales_department')) {
    function smart_report_sales_is_sales_department(?string $department): bool
    {
        $dept = strtolower(trim((string) $department));
        if ($dept === '') {
            return false;
        }

        return $dept === 'sales' || str_contains($dept, 'sales');
    }
}

if (!function_exists('smart_report_sales_rep_counts_toward_target')) {
    function smart_report_sales_rep_counts_toward_target(int $userId, ?string $department): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return smart_report_sales_is_sales_department($department);
    }
}

if (!function_exists('smart_report_sales_team_performance_data')) {
    function smart_report_sales_team_performance_data(PDO $pdo, array $filters): array
    {
        $empty = [
            'team_target' => 0.0,
            'company_target_admin' => 0.0,
            'has_company_target' => false,
            'team_actual' => 0.0,
            'achievement_pct' => null,
            'rep_count' => 0,
            'reps_on_track' => 0,
            'has_targets' => false,
            'uses_company_target_share' => false,
            'reps' => [],
        ];

        if (!tableExists('invoices', $pdo)) {
            return $empty;
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $months = smart_report_sales_month_columns($start, $end);
        $year = date('Y', strtotime($end));

        try {
            $sql = "SELECT COALESCE(SUM(total_amount), 0)
                 FROM invoices
                 WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?";
            $params = [$start, $end];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $stActual = $pdo->prepare($sql);
            $stActual->execute($params);
            $teamActual = (float) ($stActual->fetchColumn() ?: 0);

            $teamTarget = smart_report_sales_period_team_target($pdo, $months, $year);
            $adminCompanyTarget = smart_report_sales_admin_company_target($pdo, $year);
            $companyTarget = $adminCompanyTarget > 0
                ? $adminCompanyTarget
                : smart_report_sales_company_period_target($pdo, $months, $year);
            $hasCompanyTarget = $adminCompanyTarget > 0;
            $hasTargets = $hasCompanyTarget || $teamTarget > 0;

            $reps = [];
            $quotationCounts = [];
            if (tableExists('sales_orders', $pdo) && columnExists('sales_orders', 'created_by', $pdo)) {
                $quoteDateCol = columnExists('sales_orders', 'quote_date', $pdo)
                    ? 'quote_date'
                    : 'DATE(created_at)';
                $sqlQuotes = "SELECT created_by AS user_id, COUNT(*) AS quotation_count
                     FROM sales_orders
                     WHERE status IN ('draft', 'quotation')
                       AND {$quoteDateCol} BETWEEN ? AND ?";
                $paramsQuotes = [$start, $end];
                analytics_append_company_scope($sqlQuotes, $paramsQuotes, 'sales_orders', '', $pdo);
                $sqlQuotes .= ' GROUP BY created_by';
                $stQuotes = $pdo->prepare($sqlQuotes);
                $stQuotes->execute($paramsQuotes);
                foreach ($stQuotes->fetchAll(PDO::FETCH_ASSOC) ?: [] as $quoteRow) {
                    $quotationCounts[(int) ($quoteRow['user_id'] ?? 0)] = (int) ($quoteRow['quotation_count'] ?? 0);
                }
            }

            if (tableExists('users', $pdo) && columnExists('invoices', 'created_by', $pdo)) {
                $deptSelect = columnExists('users', 'department', $pdo) ? 'u.department' : 'NULL AS department';
                $sqlReps = "SELECT i.created_by AS user_id,
                            {$deptSelect},
                            COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.username), ''), 'Unassigned') AS rep_name,
                            COUNT(i.id) AS invoice_count,
                            COALESCE(SUM(i.total_amount), 0) AS actual
                     FROM invoices i
                     LEFT JOIN users u ON u.id = i.created_by
                     WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                $paramsReps = [$start, $end];
                analytics_scoped_tables($sqlReps, $paramsReps, ['i' => 'invoices', 'u' => 'users'], $pdo);
                $sqlReps .= ' GROUP BY i.created_by, rep_name, department
                     HAVING actual > 0
                     ORDER BY actual DESC';
                $stReps = $pdo->prepare($sqlReps);
                $stReps->execute($paramsReps);
                foreach ($stReps->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $userId = (int) ($row['user_id'] ?? 0);
                    $department = isset($row['department']) ? (string) $row['department'] : '';
                    $countsTowardTarget = smart_report_sales_rep_counts_toward_target($userId, $department);
                    $actual = (float) ($row['actual'] ?? 0);
                    $target = $countsTowardTarget
                        ? smart_report_sales_rep_period_target($pdo, $userId, $months, $year)
                        : 0.0;
                    if ($target > 0) {
                        $hasTargets = true;
                    }
                    $achievement = null;
                    $achievementIsContribution = false;
                    if ($countsTowardTarget) {
                        $achievement = $target > 0 ? round(($actual / $target) * 100, 1) : null;
                    } elseif ($teamActual > 0) {
                        $achievement = round(($actual / $teamActual) * 100, 1);
                        $achievementIsContribution = true;
                    }
                    $reps[] = [
                        'user_id' => $userId,
                        'name' => (string) ($row['rep_name'] ?? 'Unassigned'),
                        'department' => $department,
                        'counts_toward_target' => $countsTowardTarget,
                        'invoice_count' => (int) ($row['invoice_count'] ?? 0),
                        'quotation_count' => $quotationCounts[$userId] ?? 0,
                        'target' => $target,
                        'target_from_company' => false,
                        'actual' => $actual,
                        'achievement_pct' => $achievement,
                        'achievement_is_contribution' => $achievementIsContribution,
                        'gap' => $target > 0 ? $actual - $target : 0.0,
                    ];
                }
            }

            $usesCompanyTargetShare = false;
            if ($companyTarget > 0) {
                $withoutTargetIndexes = [];
                foreach ($reps as $index => $rep) {
                    if (($rep['target'] ?? 0) <= 0 && !empty($rep['counts_toward_target'])) {
                        $withoutTargetIndexes[] = $index;
                    }
                }
                if (!empty($withoutTargetIndexes)) {
                    $share = $companyTarget / count($withoutTargetIndexes);
                    $usesCompanyTargetShare = true;
                    $hasTargets = true;
                    foreach ($withoutTargetIndexes as $index) {
                        $actual = (float) ($reps[$index]['actual'] ?? 0);
                        $reps[$index]['target'] = $share;
                        $reps[$index]['target_from_company'] = true;
                        $reps[$index]['achievement_pct'] = round(($actual / $share) * 100, 1);
                        $reps[$index]['gap'] = $actual - $share;
                    }
                }
            }

            if ($teamTarget <= 0 && !$hasCompanyTarget) {
                $teamTarget = array_sum(array_column($reps, 'target'));
                $hasTargets = $teamTarget > 0;
            }

            $kpiTeamTarget = $hasCompanyTarget ? $adminCompanyTarget : $teamTarget;
            $achievementPct = $kpiTeamTarget > 0 ? round(($teamActual / $kpiTeamTarget) * 100, 1) : null;
            $repsOnTrack = 0;
            $salesRepCount = 0;
            foreach ($reps as $rep) {
                if (empty($rep['counts_toward_target'])) {
                    continue;
                }
                $salesRepCount++;
                if (($rep['target'] ?? 0) > 0 && ($rep['achievement_pct'] ?? 0) >= 100) {
                    $repsOnTrack++;
                }
            }

            return [
                'team_target' => $kpiTeamTarget,
                'company_target_admin' => $adminCompanyTarget,
                'has_company_target' => $hasCompanyTarget,
                'team_actual' => $teamActual,
                'achievement_pct' => $achievementPct,
                'rep_count' => $salesRepCount,
                'reps_on_track' => $repsOnTrack,
                'has_targets' => $hasTargets,
                'uses_company_target_share' => $usesCompanyTargetShare,
                'reps' => $reps,
            ];
        } catch (Throwable $e) {
            error_log('smart_report_sales_team_performance_data: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('smart_report_rep_achievement_class')) {
    function smart_report_rep_achievement_class(?float $achievement, bool $isContribution = false): string
    {
        if ($isContribution) {
            return 'sa-achieve--contrib';
        }
        if ($achievement === null) {
            return 'sa-achieve--na';
        }
        if ($achievement >= 100) {
            return 'sa-achieve--good';
        }
        if ($achievement >= 80) {
            return 'sa-achieve--warn';
        }
        return 'sa-achieve--low';
    }
}

if (!function_exists('smart_report_rep_detail_url')) {
    function smart_report_rep_detail_url(int $userId, string $repName, ?array $filters = null): string
    {
        $filters = $filters ?? smart_report_sales_parse_filters();
        $params = [
            'module' => 'analytics',
            'user_id' => $userId,
            'rep_name' => $repName,
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
        ];

        return 'smart_report_rep_detail.php?' . http_build_query($params);
    }
}

if (!function_exists('smart_report_sales_back_url')) {
    function smart_report_sales_back_url(?array $filters = null): string
    {
        $filters = $filters ?? smart_report_sales_parse_filters();
        $params = [
            'module' => 'analytics',
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
        ];

        return 'smart_report_sales.php?' . http_build_query($params);
    }
}

if (!function_exists('smart_report_render_rep_performance_html')) {
    function smart_report_render_rep_performance_html(array $salesPerformance): string
    {
        $reps = $salesPerformance['reps'] ?? [];
        if (empty($reps)) {
            return '<p class="text-muted mb-0 sa-rep-performance-empty">No invoiced sales by sales employees in this period.</p>';
        }

        $fmt = 'analytics_fmt_money';
        $totalActual = (float) ($salesPerformance['team_actual'] ?? 0);
        $teamTarget = (float) ($salesPerformance['team_target'] ?? 0);
        $teamAchievement = $salesPerformance['achievement_pct'] ?? null;
        $totalGap = $teamTarget > 0
            ? $totalActual - $teamTarget
            : array_sum(array_map(static fn(array $rep): float => (float) ($rep['gap'] ?? 0), $reps));
        $totalQuotes = array_sum(array_map(static fn(array $rep): int => (int) ($rep['quotation_count'] ?? 0), $reps));
        $totalInvoices = array_sum(array_map(static fn(array $rep): int => (int) ($rep['invoice_count'] ?? 0), $reps));
        $hasNonSalesReps = false;
        foreach ($reps as $rep) {
            if (empty($rep['counts_toward_target'])) {
                $hasNonSalesReps = true;
                break;
            }
        }

        ob_start();
        ?>
        <div class="sa-matrix-block sa-rep-performance-block">
            <h4 class="sales-drill-sub">Sales employee performance</h4>
            <div class="sa-matrix-card is-tree-collapsed" data-matrix="sa-rep-performance">
                <div class="sa-matrix-scroll">
                    <table class="sa-matrix sa-matrix--rep-performance" id="sa-rep-performance">
                        <thead>
                            <tr>
                                <th class="sa-col-label">Sales person</th>
                                <th>Target</th>
                                <th>Actual sales</th>
                                <th>Achievement</th>
                                <th>Gap</th>
                                <th>Quotations</th>
                                <th>Invoices</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="sa-row-total sa-row-parent">
                                <td class="sa-col-label">
                                    <button type="button" class="sa-tree-toggle bi bi-chevron-right" aria-label="Toggle rows" aria-expanded="false"></button>
                                    All sales employees
                                </td>
                                <td class="sa-matrix-val sa-matrix-val--total"><?= $teamTarget > 0 ? $fmt($teamTarget) : '—' ?></td>
                                <td class="sa-matrix-val sa-matrix-val--total"><?= $fmt($totalActual) ?></td>
                                <td class="sa-matrix-val sa-matrix-val--total <?= smart_report_rep_achievement_class($teamAchievement !== null ? (float) $teamAchievement : null) ?>">
                                    <?= $teamAchievement !== null ? number_format((float) $teamAchievement, 1) . '%' : '—' ?>
                                </td>
                                <td class="sa-matrix-val sa-matrix-val--total <?= $totalGap >= 0 ? 'sa-achieve--good' : 'sa-achieve--low' ?>">
                                    <?= $teamTarget > 0 || $totalGap !== 0.0 ? $fmt($totalGap) : '—' ?>
                                </td>
                                <td class="sa-matrix-val sa-matrix-val--total"><?= number_format($totalQuotes) ?></td>
                                <td class="sa-matrix-val sa-matrix-val--total"><?= number_format($totalInvoices) ?></td>
                            </tr>
                            <?php foreach ($reps as $rep): ?>
                                <?php
                                $target = (float) ($rep['target'] ?? 0);
                                $actual = (float) ($rep['actual'] ?? 0);
                                $achievement = $rep['achievement_pct'];
                                $gap = (float) ($rep['gap'] ?? 0);
                                $isContribution = !empty($rep['achievement_is_contribution']);
                                $targetLabel = $target > 0 
                                    ? $fmt($target) . (!empty($rep['target_from_company']) ? ' *' : '') 
                                    : '—';
                                ?>
                                <tr class="sa-row-child sa-row-rep-link"
                                    data-href="<?= htmlspecialchars(smart_report_rep_detail_url((int) ($rep['user_id'] ?? 0), (string) ($rep['name'] ?? ''))) ?>"
                                    title="View quotations and invoices">
                                    <td class="sa-col-label sa-col-label-child"><?= htmlspecialchars((string) ($rep['name'] ?? '')) ?></td>
                                    <td class="sa-matrix-val"><?= $targetLabel ?></td>
                                    <td class="sa-matrix-val sa-matrix-val--mid"><?= $fmt($actual) ?></td>
                                    <td class="sa-matrix-val <?= smart_report_rep_achievement_class($achievement !== null ? (float) $achievement : null, $isContribution) ?>"
                                        <?= $isContribution ? ' title="Share of total team sales"' : '' ?>>
                                        <?= $achievement !== null ? number_format((float) $achievement, 1) . '%' : '—' ?>
                                    </td>
                                    <td class="sa-matrix-val <?= !empty($rep['counts_toward_target']) && $target > 0 ? ($gap >= 0 ? 'sa-achieve--good' : 'sa-achieve--low') : '' ?>">
                                        <?= !empty($rep['counts_toward_target']) && $target > 0 ? $fmt($gap) : '—' ?>
                                    </td>
                                    <td class="sa-matrix-val"><?= number_format((int) ($rep['quotation_count'] ?? 0)) ?></td>
                                    <td class="sa-matrix-val"><?= number_format((int) ($rep['invoice_count'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if (empty($salesPerformance['has_targets'])): ?>
                <p class="sa-rep-performance-note text-muted mb-0">
                    No sales targets are defined for this period. Set targets in Sales &rarr; Admin &rarr; Targets.
                </p>
            <?php elseif ($hasNonSalesReps): ?>
                <p class="sa-rep-performance-note text-muted mb-0">
                    Sales staff achievement is measured against targets. Staff outside Sales show their share of total team sales and are not penalized on gap.
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_render_kpi_card')) {
    function smart_report_render_kpi_card(string $icon, string $label, string $value, string $tone = 'slate'): string
    {
        return '<div class="sales-kpi-card sales-kpi-card--' . htmlspecialchars($tone) . '">'
            . '<div class="sales-kpi-icon" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon) . '"></i></div>'
            . '<div class="sales-kpi-body">'
            . '<span class="sales-kpi-label">' . htmlspecialchars($label) . '</span>'
            . '<span class="sales-kpi-value">' . $value . '</span>'
            . '</div></div>';
    }
}

if (!function_exists('smart_report_render_kpi_card_soon')) {
    function smart_report_render_kpi_card_soon(string $icon, string $label, string $tone = 'slate'): string
    {
        return '<div class="sales-kpi-card sales-kpi-card--soon sales-kpi-card--' . htmlspecialchars($tone) . '" aria-disabled="true">'
            . '<div class="sales-kpi-icon" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon) . '"></i></div>'
            . '<div class="sales-kpi-body">'
            . '<span class="sales-kpi-label">' . htmlspecialchars($label) . '</span>'
            . '<span class="sales-kpi-value sales-kpi-value--soon">Coming soon</span>'
            . '</div></div>';
    }
}

if (!function_exists('smart_report_render_coming_soon_panel')) {
    function smart_report_render_coming_soon_panel(string $icon = 'bi-hourglass-split'): string
    {
        return '<div class="sa-soon-panel" role="status">'
            . '<div class="sa-soon-panel-graphic" aria-hidden="true">'
            . '<span class="sa-soon-panel-ring"></span>'
            . '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>'
            . '</div>'
            . '<p class="sa-soon-panel-eyebrow">Coming soon</p>'
            . '</div>';
    }
}

if (!function_exists('smart_report_render_section_head')) {
    function smart_report_render_section_head(string $icon, string $title, string $desc, string $tone): string
    {
        return '<div class="sales-drill-section-head">'
            . '<div class="sales-drill-section-badge sales-drill-section-badge--' . htmlspecialchars($tone) . '">'
            . '<i class="bi ' . htmlspecialchars($icon) . '"></i></div>'
            . '<div class="sales-drill-section-copy">'
            . '<h3>' . htmlspecialchars($title) . '</h3>'
            . '<p class="sales-drill-desc">' . htmlspecialchars($desc) . '</p>'
            . '</div></div>';
    }
}

if (!function_exists('smart_report_sales_parse_filters')) {
    function smart_report_sales_parse_filters(): array
    {
        $start = $_GET['start_date'] ?? date('Y-01-01');
        $end = $_GET['end_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-01-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = date('Y-m-d');
        }
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }

        $tree = (string) ($_GET['tree_type'] ?? 'customer_group');
        $allowedTrees = ['customer_group', 'customer', 'item', 'item_group', 'territory', 'order_type'];
        if (!in_array($tree, $allowedTrees, true)) {
            $tree = 'customer_group';
        }

        $valueQty = (string) ($_GET['value_qty'] ?? 'value');
        if (!in_array($valueQty, ['value', 'quantity'], true)) {
            $valueQty = 'value';
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'tree_type' => $tree,
            'value_qty' => $valueQty,
            'based_on' => 'sales_invoice',
        ];
    }
}

if (!function_exists('smart_report_sales_tree_label')) {
    function smart_report_sales_tree_label(string $treeType): string
    {
        $map = [
            'customer_group' => 'Customer Group',
            'customer' => 'Customer',
            'item' => 'Item',
            'item_group' => 'Item Group',
            'territory' => 'Territory',
            'order_type' => 'Order Type',
        ];
        return $map[$treeType] ?? 'Category';
    }
}

if (!function_exists('smart_report_sales_tree_totals_label')) {
    function smart_report_sales_tree_totals_label(string $treeType): string
    {
        $map = [
            'customer_group' => 'All Customer Groups',
            'customer' => 'All Customers',
            'item' => 'All Items',
            'item_group' => 'All Item Groups',
            'territory' => 'All Territories',
            'order_type' => 'All Order Types',
        ];
        return $map[$treeType] ?? 'All ' . smart_report_sales_tree_label($treeType) . 's';
    }
}

if (!function_exists('smart_report_sales_month_columns')) {
    function smart_report_sales_month_columns(string $start, string $end): array
    {
        $months = [];
        try {
            $cur = new DateTime(date('Y-m-01', strtotime($start)));
            $endDt = new DateTime(date('Y-m-01', strtotime($end)));
            while ($cur <= $endDt) {
                $months[] = $cur->format('Y-m');
                $cur->modify('+1 month');
            }
        } catch (Throwable $e) {
            $months[] = date('Y-m');
        }
        return $months;
    }
}

if (!function_exists('smart_report_sales_fmt_matrix_cell')) {
    function smart_report_sales_fmt_matrix_cell(float $value, bool $isQuantity): string
    {
        if ($isQuantity) {
            return number_format($value, 0, '.', ',');
        }
        return number_format($value, 3, '.', ',');
    }
}

if (!function_exists('smart_report_matrix_month_maxima')) {
    function smart_report_matrix_month_maxima(array $matrix): array
    {
        $maxima = array_fill_keys($matrix['months'] ?? [], 0.0);
        foreach ($matrix['rows'] ?? [] as $row) {
            foreach ($matrix['months'] as $ym) {
                $value = (float) ($row['months'][$ym] ?? 0);
                if ($value > $maxima[$ym]) {
                    $maxima[$ym] = $value;
                }
            }
        }
        return $maxima;
    }
}

if (!function_exists('smart_report_matrix_value_class')) {
    function smart_report_matrix_value_class(float $value, float $monthMax, bool $isTotalRow = false): string
    {
        if ($value <= 0) {
            return 'sa-matrix-val--zero';
        }
        if ($isTotalRow) {
            return 'sa-matrix-val--total';
        }
        $ratio = $monthMax > 0 ? $value / $monthMax : 1.0;
        if ($ratio >= 0.7) {
            return 'sa-matrix-val--high';
        }
        if ($ratio >= 0.35) {
            return 'sa-matrix-val--mid';
        }
        return 'sa-matrix-val--low';
    }
}

if (!function_exists('smart_report_product_image_url')) {
    function smart_report_product_image_url(PDO $pdo, int $productId, array $product = []): string
    {
        static $salesFnsLoaded = false;
        if (!$salesFnsLoaded) {
            $salesFns = dirname(__DIR__, 2) . '/sales/functions.php';
            if (is_file($salesFns)) {
                require_once $salesFns;
            }
            $salesFnsLoaded = true;
        }

        $item = [
            'product_id' => $productId,
            'main_image' => (string) ($product['main_image'] ?? $product['image'] ?? ''),
            'image' => (string) ($product['image'] ?? $product['main_image'] ?? ''),
        ];
        if (function_exists('sales_enrich_order_items_images')) {
            $enriched = sales_enrich_order_items_images([$item], $pdo);
            $item = $enriched[0] ?? $item;
        }
        if (function_exists('sales_order_item_image_url')) {
            return sales_order_item_image_url($item, 'thumbnail');
        }

        $filename = trim((string) ($item['main_image'] ?? ''));
        if ($productId > 0 && $filename !== '') {
            return '/stock/uploads/products/' . $productId . '/thumbnail/' . rawurlencode($filename);
        }

        return '/stock/assets/images/no-image.png';
    }
}

if (!function_exists('smart_report_render_matrix_meta_cell')) {
    function smart_report_render_matrix_meta_cell(array $col, array $row, bool $isTotalRow = false): string
    {
        if ($isTotalRow) {
            return '<td class="sa-col-meta">—</td>';
        }

        $type = (string) ($col['type'] ?? 'text');
        if ($type === 'image') {
            $url = (string) ($row[$col['field']] ?? '/stock/assets/images/no-image.png');
            $alt = htmlspecialchars((string) ($row['label'] ?? 'Product'), ENT_QUOTES, 'UTF-8');
            return '<td class="sa-col-meta sa-col-image">'
                . '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="sa-product-thumb" loading="lazy">'
                . '</td>';
        }

        return '<td class="sa-col-meta">' . htmlspecialchars((string) ($row[$col['field']] ?? 'Unassigned')) . '</td>';
    }
}

if (!function_exists('smart_report_sales_matrix_data')) {
    function smart_report_sales_matrix_data(PDO $pdo, array $filters): array
    {
        $empty = [
            'has_data' => false,
            'months' => smart_report_sales_month_columns($filters['start_date'], $filters['end_date']),
            'rows' => [],
            'totals_row' => ['label' => smart_report_sales_tree_totals_label($filters['tree_type']), 'months' => [], 'total' => 0.0],
            'chart' => ['labels' => [], 'data' => []],
        ];

        if (!tableExists('invoices', $pdo)) {
            return $empty;
        }

        $months = $empty['months'];
        $isQty = $filters['value_qty'] === 'quantity';
        $valExpr = $isQty ? 'COUNT(DISTINCT i.id)' : 'COALESCE(SUM(i.total_amount), 0)';
        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $tree = $filters['tree_type'];

        $raw = [];
        try {
            switch ($tree) {
                case 'customer':
                    if (!tableExists('customers', $pdo)) {
                        break;
                    }
                    $sql = "SELECT COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in Customer') AS label,
                                DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                {$valExpr} AS metric
                         FROM invoices i
                         LEFT JOIN customers c ON c.id = i.customer_id
                         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$start, $end];
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                    $sql .= ' GROUP BY label, ym ORDER BY label ASC, ym ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'customer_group':
                    if (!tableExists('customers', $pdo) || !columnExists('customers', 'customer_type', $pdo)) {
                        $sql = "SELECT 'All Customers' AS label,
                                    DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                    {$valExpr} AS metric
                             FROM invoices i
                             WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                        $params = [$start, $end];
                        analytics_scoped_tables($sql, $params, ['i' => 'invoices'], $pdo);
                        $sql .= ' GROUP BY ym';
                        $st = $pdo->prepare($sql);
                        $st->execute($params);
                        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        break;
                    }
                    $sql = "SELECT COALESCE(NULLIF(TRIM(c.customer_type), ''), 'Other') AS label,
                                DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                {$valExpr} AS metric
                         FROM invoices i
                         LEFT JOIN customers c ON c.id = i.customer_id
                         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$start, $end];
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                    $sql .= ' GROUP BY label, ym ORDER BY label ASC, ym ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'territory':
                    if (!tableExists('customers', $pdo)) {
                        break;
                    }
                    $territoryCol = columnExists('customers', 'city', $pdo) ? 'city'
                        : (columnExists('customers', 'country', $pdo) ? 'country' : null);
                    if (!$territoryCol) {
                        break;
                    }
                    smart_report_sales_sync_territory_mappings($pdo, $territoryCol);
                    $sql = "SELECT COALESCE(m.normalized_name, COALESCE(NULLIF(TRIM(c.{$territoryCol}), ''), 'Unassigned')) AS label,
                                DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                {$valExpr} AS metric
                         FROM invoices i
                         LEFT JOIN customers c ON c.id = i.customer_id
                         LEFT JOIN ai_territory_mappings m ON m.raw_name = COALESCE(NULLIF(TRIM(c.{$territoryCol}), ''), 'Unassigned')
                         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$start, $end];
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
                    $sql .= ' GROUP BY label, ym ORDER BY label ASC, ym ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'order_type':
                    if (!tableExists('sales_orders', $pdo) || !columnExists('invoices', 'order_id', $pdo)) {
                        break;
                    }
                    $statusExpr = 'COALESCE(NULLIF(TRIM(so.status), \'\'), \'Unknown\')';
                    $sql = "SELECT {$statusExpr} AS label,
                                DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                {$valExpr} AS metric
                         FROM invoices i
                         LEFT JOIN sales_orders so ON so.id = i.order_id
                         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$start, $end];
                    analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'so' => 'sales_orders'], $pdo);
                    $sql .= ' GROUP BY label, ym ORDER BY label ASC, ym ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'item':
                case 'item_group':
                    if (!tableExists('sales_order_items', $pdo) || !tableExists('sales_orders', $pdo) || !tableExists('products', $pdo)) {
                        break;
                    }
                    $itemVal = $isQty ? 'COALESCE(SUM(soi.quantity), 0)' : 'COALESCE(SUM(soi.line_total), 0)';
                    if ($tree === 'item_group' && tableExists('categories', $pdo) && columnExists('products', 'category_id', $pdo)) {
                        $labelExpr = "COALESCE(NULLIF(TRIM(cat.name), ''), 'Uncategorized')";
                        $joinCat = 'LEFT JOIN categories cat ON cat.id = p.category_id';
                    } else {
                        $labelExpr = "COALESCE(NULLIF(TRIM(p.name), ''), 'Unknown Item')";
                        $joinCat = '';
                    }
                    $sql = "SELECT {$labelExpr} AS label,
                                DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                                {$itemVal} AS metric
                         FROM invoices i
                         INNER JOIN sales_orders so ON so.id = i.order_id
                         INNER JOIN sales_order_items soi ON soi.order_id = so.id
                         INNER JOIN products p ON p.id = soi.product_id
                         {$joinCat}
                         WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                    $params = [$start, $end];
                    analytics_scoped_tables($sql, $params, [
                        'i' => 'invoices',
                        'so' => 'sales_orders',
                        'soi' => 'sales_order_items',
                        'p' => 'products',
                    ], $pdo);
                    $sql .= ' GROUP BY label, ym ORDER BY label ASC, ym ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;
            }
        } catch (Throwable $e) {
            error_log('smart_report_sales_matrix_data: ' . $e->getMessage());
            return $empty;
        }

        if (empty($raw)) {
            return $empty;
        }

        $rowMap = [];
        $totalsMonths = array_fill_keys($months, 0.0);
        $grandTotal = 0.0;

        foreach ($raw as $r) {
            $label = (string) ($r['label'] ?? 'Unknown');
            $ym = (string) ($r['ym'] ?? '');
            $metric = (float) ($r['metric'] ?? 0);
            if (!isset($rowMap[$label])) {
                $rowMap[$label] = ['label' => $label, 'months' => array_fill_keys($months, 0.0), 'total' => 0.0];
            }
            if (isset($rowMap[$label]['months'][$ym])) {
                $rowMap[$label]['months'][$ym] += $metric;
            }
            $rowMap[$label]['total'] += $metric;
            if (isset($totalsMonths[$ym])) {
                $totalsMonths[$ym] += $metric;
            }
            $grandTotal += $metric;
        }

        $rows = array_values($rowMap);
        usort($rows, static function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $chartLabels = [];
        $chartData = [];
        foreach ($months as $ym) {
            $chartLabels[] = date('M Y', strtotime($ym . '-01'));
            $chartData[] = round($totalsMonths[$ym], $isQty ? 0 : 2);
        }

        return [
            'has_data' => true,
            'months' => $months,
            'rows' => $rows,
            'totals_row' => [
                'label' => smart_report_sales_tree_totals_label($tree),
                'months' => $totalsMonths,
                'total' => $grandTotal,
            ],
            'chart' => ['labels' => $chartLabels, 'data' => $chartData],
        ];
    }
}

if (!function_exists('smart_report_assemble_metric_matrix')) {
    function smart_report_assemble_metric_matrix(array $months, array $rowMap, string $totalsLabel, ?callable $sortFn = null): array
    {
        $empty = [
            'has_data' => false,
            'months' => $months,
            'rows' => [],
            'totals_row' => [
                'label' => $totalsLabel,
                'months' => array_fill_keys($months, 0.0),
                'total' => 0.0,
            ],
        ];

        if (empty($rowMap)) {
            return $empty;
        }

        $totalsMonths = array_fill_keys($months, 0.0);
        $grandTotal = 0.0;
        foreach ($rowMap as $row) {
            foreach ($months as $ym) {
                $value = (float) ($row['months'][$ym] ?? 0);
                $totalsMonths[$ym] += $value;
                $grandTotal += $value;
            }
        }

        $rows = array_values($rowMap);
        if ($sortFn) {
            usort($rows, $sortFn);
        } else {
            usort($rows, static function ($a, $b) {
                return $b['total'] <=> $a['total'];
            });
        }
        foreach ($rows as &$row) {
            unset($row['sort'], $row['qty_total']);
        }
        unset($row);

        return [
            'has_data' => true,
            'months' => $months,
            'rows' => $rows,
            'totals_row' => [
                'label' => $totalsLabel,
                'months' => $totalsMonths,
                'total' => $grandTotal,
            ],
        ];
    }
}

if (!function_exists('smart_report_rep_created_by_clause')) {
    function smart_report_rep_created_by_clause(string $alias, int $userId): array
    {
        if ($userId > 0) {
            return ["{$alias}.created_by = ?", [$userId]];
        }

        return ["({$alias}.created_by IS NULL OR {$alias}.created_by = 0)", []];
    }
}

if (!function_exists('smart_report_rep_quotations')) {
    function smart_report_rep_quotations(PDO $pdo, array $filters, int $userId): array
    {
        if (!tableExists('sales_orders', $pdo) || !columnExists('sales_orders', 'created_by', $pdo)) {
            return [];
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];
        [$createdClause, $createdParams] = smart_report_rep_created_by_clause('so', $userId);
        $quoteDateCol = columnExists('sales_orders', 'quote_date', $pdo)
            ? 'so.quote_date'
            : 'DATE(so.created_at)';
        $hasCustomer = tableExists('customers', $pdo) && columnExists('sales_orders', 'customer_id', $pdo);
        $customerSelect = $hasCustomer
            ? ", COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS customer_name"
            : ", 'Walk-in' AS customer_name";
        $customerJoin = $hasCustomer ? ' LEFT JOIN customers c ON c.id = so.customer_id' : '';

        $sql = "SELECT so.id, so.order_number, {$quoteDateCol} AS quote_date, so.total_amount, so.status
                       {$customerSelect}
                FROM sales_orders so{$customerJoin}
                WHERE so.status IN ('draft', 'quotation')
                  AND {$quoteDateCol} BETWEEN ? AND ?
                  AND {$createdClause}";
        $params = array_merge([$start, $end], $createdParams);
        $scopes = ['so' => 'sales_orders'];
        if ($hasCustomer) {
            $scopes['c'] = 'customers';
        }
        analytics_scoped_tables($sql, $params, $scopes, $pdo);
        $sql .= " ORDER BY {$quoteDateCol} DESC, so.id DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smart_report_rep_invoices')) {
    function smart_report_rep_invoices(PDO $pdo, array $filters, int $userId): array
    {
        if (!tableExists('invoices', $pdo) || !columnExists('invoices', 'created_by', $pdo)) {
            return [];
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];
        [$createdClause, $createdParams] = smart_report_rep_created_by_clause('i', $userId);
        $hasCustomer = tableExists('customers', $pdo) && columnExists('invoices', 'customer_id', $pdo);
        $customerSelect = $hasCustomer
            ? ", COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS customer_name"
            : ", 'Walk-in' AS customer_name";
        $customerJoin = $hasCustomer ? ' LEFT JOIN customers c ON c.id = i.customer_id' : '';

        $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status
                       {$customerSelect}
                FROM invoices i{$customerJoin}
                WHERE i.status != 'cancelled'
                  AND i.invoice_date BETWEEN ? AND ?
                  AND {$createdClause}";
        $params = array_merge([$start, $end], $createdParams);
        $scopes = ['i' => 'invoices'];
        if ($hasCustomer) {
            $scopes['c'] = 'customers';
        }
        analytics_scoped_tables($sql, $params, $scopes, $pdo);
        $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smart_report_render_rep_sales_detail_html')) {
    function smart_report_render_rep_sales_detail_html(
        array $quotations,
        array $invoices,
        string $repName = '',
        bool $fullPage = false
    ): string {
        $fmt = 'analytics_fmt_money';
        $quoteTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $quotations));
        $invoiceTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $invoices));
        $orderViewBase = '../sales/orders/view.php?module=sales&id=';
        $invoiceViewBase = '../sales/view.php?module=sales&id=';

        ob_start();
        ?>
        <div class="sa-drill-rep-detail<?= $fullPage ? ' sa-drill-rep-detail--page' : '' ?>">
            <div class="sa-drill-rep-section">
                <h5 class="sa-drill-rep-heading">
                    Quotations
                    <span class="sa-drill-rep-meta"><?= number_format(count($quotations)) ?> &middot; Total <?= $fmt($quoteTotal) ?></span>
                </h5>
                <?php if (empty($quotations)): ?>
                    <p class="sa-drill-empty text-muted mb-0">No quotations in this period<?= $repName !== '' ? ' for ' . htmlspecialchars($repName) : '' ?>.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="sa-drill-rep-table">
                            <thead>
                                <tr>
                                    <th>Quote #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($quotations as $quote): ?>
                                <?php
                                $quoteDate = (string) ($quote['quote_date'] ?? '');
                                $dateLabel = $quoteDate !== '' ? date('M j, Y', strtotime($quoteDate)) : '—';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars($orderViewBase . (int) ($quote['id'] ?? 0)) ?>">
                                            <?= htmlspecialchars((string) ($quote['order_number'] ?? '')) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($dateLabel) ?></td>
                                    <td><?= htmlspecialchars((string) ($quote['customer_name'] ?? 'Walk-in')) ?></td>
                                    <td class="text-end"><?= $fmt((float) ($quote['total_amount'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="sa-drill-rep-total">
                                    <td colspan="3">Total</td>
                                    <td class="text-end"><?= $fmt($quoteTotal) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="sa-drill-rep-section">
                <h5 class="sa-drill-rep-heading">
                    Invoices
                    <span class="sa-drill-rep-meta"><?= number_format(count($invoices)) ?> &middot; Total <?= $fmt($invoiceTotal) ?></span>
                </h5>
                <?php if (empty($invoices)): ?>
                    <p class="sa-drill-empty text-muted mb-0">No invoices in this period<?= $repName !== '' ? ' for ' . htmlspecialchars($repName) : '' ?>.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="sa-drill-rep-table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                $invoiceDate = (string) ($inv['invoice_date'] ?? '');
                                $dateLabel = $invoiceDate !== '' ? date('M j, Y', strtotime($invoiceDate)) : '—';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars($invoiceViewBase . (int) ($inv['id'] ?? 0)) ?>">
                                            <?= htmlspecialchars((string) ($inv['invoice_number'] ?? '')) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($dateLabel) ?></td>
                                    <td><?= htmlspecialchars((string) ($inv['customer_name'] ?? 'Walk-in')) ?></td>
                                    <td class="text-end"><?= $fmt((float) ($inv['total_amount'] ?? 0)) ?></td>
                                    <td><?= htmlspecialchars(ucfirst((string) ($inv['status'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="sa-drill-rep-total">
                                    <td colspan="3">Total</td>
                                    <td class="text-end"><?= $fmt($invoiceTotal) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_rep_performance_snapshot')) {
    function smart_report_rep_performance_snapshot(
        PDO $pdo,
        array $filters,
        int $userId,
        string $repName,
        array $quotations,
        array $invoices
    ): array {
        $quoteTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $quotations));
        $invoiceTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $invoices));
        $quoteCount = count($quotations);
        $invoiceCount = count($invoices);

        $teamPerf = smart_report_sales_team_performance_data($pdo, $filters);
        $repRow = null;
        foreach ($teamPerf['reps'] ?? [] as $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId) {
                $repRow = $row;
                break;
            }
        }

        $conversionCountPct = $quoteCount > 0 ? round(($invoiceCount / $quoteCount) * 100, 1) : null;
        $quoteValueRealizedPct = $quoteTotal > 0 ? round(($invoiceTotal / $quoteTotal) * 100, 1) : null;

        return [
            'user_id' => $userId,
            'name' => $repName,
            'department' => (string) ($repRow['department'] ?? ''),
            'period_start' => $filters['start_date'],
            'period_end' => $filters['end_date'],
            'quotation_count' => $quoteCount,
            'quotation_total' => $quoteTotal,
            'invoice_count' => $invoiceCount,
            'invoice_total' => $invoiceTotal,
            'conversion_count_pct' => $conversionCountPct,
            'quote_value_realized_pct' => $quoteValueRealizedPct,
            'target' => (float) ($repRow['target'] ?? 0),
            'achievement_pct' => $repRow['achievement_pct'] ?? null,
            'gap' => (float) ($repRow['gap'] ?? 0),
            'counts_toward_target' => !empty($repRow['counts_toward_target']),
            'team_target' => (float) ($teamPerf['team_target'] ?? 0),
            'team_actual' => (float) ($teamPerf['team_actual'] ?? 0),
            'team_achievement_pct' => $teamPerf['achievement_pct'] ?? null,
        ];
    }
}

if (!function_exists('smart_report_rep_build_insights')) {
    function smart_report_rep_build_insights(array $snapshot): array
    {
        $achievements = [];
        $suggestions = [];
        $fmt = 'analytics_fmt_money';

        $name = (string) ($snapshot['name'] ?? 'Sales employee');
        $quoteCount = (int) ($snapshot['quotation_count'] ?? 0);
        $invoiceCount = (int) ($snapshot['invoice_count'] ?? 0);
        $quoteTotal = (float) ($snapshot['quotation_total'] ?? 0);
        $invoiceTotal = (float) ($snapshot['invoice_total'] ?? 0);
        $achievement = $snapshot['achievement_pct'];
        $target = (float) ($snapshot['target'] ?? 0);
        $gap = (float) ($snapshot['gap'] ?? 0);
        $conversion = $snapshot['conversion_count_pct'];
        $valueRealized = $snapshot['quote_value_realized_pct'];

        if ($invoiceTotal > 0) {
            $achievements[] = $name . ' invoiced ' . $fmt($invoiceTotal) . ' across '
                . number_format($invoiceCount) . ' invoice' . ($invoiceCount === 1 ? '' : 's') . ' in this period.';
        }

        if ($achievement !== null && !empty($snapshot['counts_toward_target'])) {
            if ((float) $achievement >= 100) {
                $achievements[] = 'Target achievement is ' . number_format((float) $achievement, 1)
                    . '%' . ($gap > 0 ? ' (ahead by ' . $fmt($gap) . ').' : '.');
            } elseif ((float) $achievement >= 80) {
                $achievements[] = 'On track at ' . number_format((float) $achievement, 1) . '% of sales target.';
            } else {
                $suggestions[] = 'Target achievement is ' . number_format((float) $achievement, 1)
                    . '% — prioritize closing high-value deals to close the ' . $fmt(abs($gap)) . ' gap.';
            }
        }

        if ($quoteCount > 0) {
            $achievements[] = 'Active pipeline with ' . number_format($quoteCount) . ' quotation'
                . ($quoteCount === 1 ? '' : 's') . ' worth ' . $fmt($quoteTotal) . '.';
        }

        if ($conversion !== null) {
            if ($conversion >= 60) {
                $achievements[] = 'Strong quote-to-invoice conversion at '
                    . number_format((float) $conversion, 1) . '% by count.';
            } elseif ($conversion < 35 && $quoteCount >= 5) {
                $suggestions[] = 'Only ' . number_format((float) $conversion, 1)
                    . '% of quotations converted to invoices — review follow-up on open quotes.';
            }
        }

        if ($valueRealized !== null && $quoteTotal > 0 && $invoiceTotal > 0) {
            if ($valueRealized < 50 && $quoteCount >= 3) {
                $unrealized = max(0, $quoteTotal - $invoiceTotal);
                $suggestions[] = 'Quoted pipeline (' . $fmt($quoteTotal) . ') exceeds invoiced sales — '
                    . $fmt($unrealized) . ' in quotes may still need closing.';
            } elseif ($valueRealized >= 75) {
                $achievements[] = 'Converted roughly ' . number_format((float) $valueRealized, 1)
                    . '% of quoted value into invoiced revenue.';
            }
        }

        $teamActual = (float) ($snapshot['team_actual'] ?? 0);
        if ($teamActual > 0 && $invoiceTotal > 0) {
            $share = round(($invoiceTotal / $teamActual) * 100, 1);
            if ($share >= 40) {
                $achievements[] = 'Contributing ' . number_format($share, 1) . '% of total team invoiced sales.';
            }
        }

        if ($invoiceCount === 0 && $quoteCount > 0) {
            $suggestions[] = 'Quotations exist but no invoices yet — confirm pricing and push confirmed orders to billing.';
        }

        if ($quoteCount === 0 && $invoiceCount > 0) {
            $suggestions[] = 'No quotations logged in this period — create quotes for new opportunities to grow pipeline visibility.';
        }

        if (empty($snapshot['counts_toward_target']) && $invoiceTotal > 0) {
            $achievements[] = 'Sales activity is recorded; this profile is not measured against a personal sales target.';
        }

        if (count($achievements) < 2) {
            $achievements[] = 'Maintain consistent customer follow-up to grow quotation and invoice volume.';
        }
        if (count($suggestions) < 2) {
            $suggestions[] = 'Review the largest open quotations weekly and set next-step dates with each customer.';
            $suggestions[] = 'Balance new prospecting with follow-up on pending quotes to improve conversion.';
        }

        return [
            'achievements' => array_slice($achievements, 0, 5),
            'suggestions' => array_slice($suggestions, 0, 5),
            'source' => 'rules',
        ];
    }
}

if (!function_exists('smart_report_parse_ai_labeled_lines')) {
    function smart_report_parse_ai_labeled_lines(string $content): array
    {
        $achievements = [];
        $suggestions = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if (stripos($line, 'ACHIEVEMENT:') === 0) {
                $achievements[] = trim(substr($line, 12));
            } elseif (stripos($line, 'SUGGESTION:') === 0) {
                $suggestions[] = trim(substr($line, 11));
            }
        }

        return [
            'achievements' => array_values(array_filter($achievements)),
            'suggestions' => array_values(array_filter($suggestions)),
        ];
    }
}

if (!function_exists('smart_report_rep_fetch_ai_insights')) {
    function smart_report_rep_fetch_ai_insights(PDO $pdo, array $snapshot): array
    {
        $fallback = smart_report_rep_build_insights($snapshot);

        try {
            $aiHelpers = __DIR__ . '/../../../includes/ai_helpers.php';
            if (!is_file($aiHelpers)) {
                return $fallback;
            }
            require_once $aiHelpers;

            $settings = ai_fetch_settings_row();
            if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
                return $fallback;
            }

            $fmt = 'analytics_fmt_money';
            $statsText = "Sales employee performance snapshot:\n";
            $statsText .= '- Name: ' . ($snapshot['name'] ?? '') . "\n";
            $statsText .= '- Period: ' . ($snapshot['period_start'] ?? '') . ' to ' . ($snapshot['period_end'] ?? '') . "\n";
            $statsText .= '- Quotations: ' . number_format((int) ($snapshot['quotation_count'] ?? 0))
                . ' totalling ' . $fmt((float) ($snapshot['quotation_total'] ?? 0)) . "\n";
            $statsText .= '- Invoices: ' . number_format((int) ($snapshot['invoice_count'] ?? 0))
                . ' totalling ' . $fmt((float) ($snapshot['invoice_total'] ?? 0)) . "\n";
            if ($snapshot['conversion_count_pct'] !== null) {
                $statsText .= '- Quote-to-invoice conversion (count): '
                    . number_format((float) $snapshot['conversion_count_pct'], 1) . "%\n";
            }
            if ($snapshot['quote_value_realized_pct'] !== null) {
                $statsText .= '- Quote value realized as invoices: '
                    . number_format((float) $snapshot['quote_value_realized_pct'], 1) . "%\n";
            }
            if (!empty($snapshot['counts_toward_target']) && $snapshot['achievement_pct'] !== null) {
                $statsText .= '- Target achievement: ' . number_format((float) $snapshot['achievement_pct'], 1) . "%\n";
                $statsText .= '- Gap vs target: ' . $fmt((float) ($snapshot['gap'] ?? 0)) . "\n";
            }
            $statsText .= '- Team invoiced sales: ' . $fmt((float) ($snapshot['team_actual'] ?? 0)) . "\n";

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a B2B sales coach for an ERP analytics dashboard. '
                        . 'Based on the employee metrics, return concise, actionable insights. '
                        . 'Use plain language and Tanzanian Shilling context when mentioning money. '
                        . 'Format exactly like this, up to 4 items each:\n'
                        . "ACHIEVEMENT: [positive insight]\nSUGGESTION: [actionable recommendation]",
                ],
                [
                    'role' => 'user',
                    'content' => $statsText,
                ],
            ];

            $openai = ai_openai_request($messages);
            $parsed = smart_report_parse_ai_labeled_lines((string) ($openai['content'] ?? ''));
            if (empty($parsed['achievements']) && empty($parsed['suggestions'])) {
                return $fallback;
            }

            return [
                'achievements' => array_slice(
                    !empty($parsed['achievements']) ? $parsed['achievements'] : $fallback['achievements'],
                    0,
                    5
                ),
                'suggestions' => array_slice(
                    !empty($parsed['suggestions']) ? $parsed['suggestions'] : $fallback['suggestions'],
                    0,
                    5
                ),
                'source' => 'ai',
            ];
        } catch (Throwable $e) {
            error_log('smart_report_rep_fetch_ai_insights: ' . $e->getMessage());
            return $fallback;
        }
    }
}

if (!function_exists('smart_report_render_rep_ai_insights_html')) {
    function smart_report_render_rep_ai_insights_html(array $insights, string $source = 'rules'): string
    {
        $achievements = $insights['achievements'] ?? [];
        $suggestions = $insights['suggestions'] ?? [];
        $sourceLabel = $source === 'ai' ? 'AI-powered' : 'Smart rules';

        ob_start();
        ?>
        <section class="sa-rep-ai-insights" id="sa-rep-ai-insights">
            <div class="sa-rep-ai-head">
                <h3><i class="bi bi-stars" aria-hidden="true"></i> AI Suggestions</h3>
                <span class="sa-rep-ai-source"><?= htmlspecialchars($sourceLabel) ?></span>
            </div>
            <div class="sa-rep-ai-grid">
                <div class="sa-rep-ai-block">
                    <h4 class="sa-rep-ai-block-title sa-rep-ai-block-title--good">
                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Highlights
                    </h4>
                    <ul class="sa-rep-ai-list">
                        <?php foreach ($achievements as $item): ?>
                            <li><?= htmlspecialchars((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="sa-rep-ai-block">
                    <h4 class="sa-rep-ai-block-title sa-rep-ai-block-title--warn">
                        <i class="bi bi-lightbulb-fill" aria-hidden="true"></i> Recommendations
                    </h4>
                    <ul class="sa-rep-ai-list">
                        <?php foreach ($suggestions as $item): ?>
                            <li><?= htmlspecialchars((string) $item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_customer_invoices')) {
    function smart_report_customer_invoices(
        PDO $pdo,
        array $filters,
        int $customerId,
        string $customerLabel
    ): array {
        if (!tableExists('invoices', $pdo)) {
            return [];
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $label = trim($customerLabel);

        if ($customerId <= 0 && $label === '') {
            return [];
        }

        if ($customerId > 0) {
            $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.balance_due, i.status
                    FROM invoices i
                    WHERE i.status != 'cancelled'
                      AND i.invoice_date BETWEEN ? AND ?
                      AND i.customer_id = ?";
            $params = [$start, $end, $customerId];
            analytics_scoped_tables($sql, $params, ['i' => 'invoices'], $pdo);
            $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 200';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.balance_due, i.status
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE i.status != 'cancelled'
                  AND i.invoice_date BETWEEN ? AND ?
                  AND COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') = ?";
        $params = [$start, $end, $label];
        analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
        $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smart_report_render_customer_invoices_html')) {
    function smart_report_render_customer_invoices_html(
        array $invoices,
        string $invoiceViewBase,
        array $months = []
    ): string {
        if (empty($invoices)) {
            return '<p class="sa-drill-empty text-muted mb-0">No invoices found for this customer in the selected period.</p>';
        }

        $amountMax = 0.0;
        foreach ($invoices as $inv) {
            $amountMax = max(
                $amountMax,
                (float) ($inv['total_amount'] ?? 0),
                (float) ($inv['amount_paid'] ?? 0),
                (float) ($inv['balance_due'] ?? 0)
            );
        }

        $monthLabels = ['Total', 'Paid', 'Balance'];
        $useMonthGrid = count($months) >= 4;
        $statusMonthIdx = $useMonthGrid ? count($months) - 1 : -1;

        ob_start();
        ?>
        <div class="sa-drill-invoices">
            <table class="sa-matrix sa-matrix--invoices">
                <colgroup>
                    <col class="sa-col-check">
                    <col class="sa-invoice-gap">
                    <col class="sa-col-label">
                    <col class="sa-col-meta">
                    <?php if ($useMonthGrid): ?>
                        <?php foreach ($months as $idx => $ym): ?>
                            <col class="<?= $idx === $statusMonthIdx ? 'sa-col-status' : ($idx < 3 ? 'sa-col-amount' : 'sa-col-month-gap') ?>">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <col class="sa-col-amount">
                        <col class="sa-col-amount">
                        <col class="sa-col-amount">
                        <col class="sa-col-status">
                    <?php endif; ?>
                </colgroup>
                <thead>
                    <tr>
                        <th class="sa-col-check" aria-hidden="true"></th>
                        <th class="sa-col-num sa-invoice-gap" aria-hidden="true"></th>
                        <th class="sa-col-label">Invoice #</th>
                        <th class="sa-col-meta">Date</th>
                        <?php if ($useMonthGrid): ?>
                            <?php foreach ($months as $idx => $ym): ?>
                                <?php
                                $label = $monthLabels[$idx] ?? '';
                                if ($idx === $statusMonthIdx) {
                                    $label = 'Status';
                                }
                                ?>
                                <th class="<?= $idx === $statusMonthIdx ? 'sa-col-status' : ($label === '' ? 'sa-col-month-gap' : 'sa-col-amount') ?>"<?= $label === '' ? ' aria-hidden="true"' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </th>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th class="sa-col-status">Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                    <?php
                    $total = (float) ($inv['total_amount'] ?? 0);
                    $paid = (float) ($inv['amount_paid'] ?? 0);
                    $balance = (float) ($inv['balance_due'] ?? 0);
                    $invoiceDate = (string) ($inv['invoice_date'] ?? '');
                    $dateLabel = $invoiceDate !== '' ? date('M j, Y', strtotime($invoiceDate)) : '—';
                    $statusLabel = ucfirst((string) ($inv['status'] ?? ''));
                    ?>
                    <tr class="sa-row-child sa-row-invoice">
                        <td class="sa-col-check" aria-hidden="true"></td>
                        <td class="sa-col-num sa-invoice-gap" aria-hidden="true"></td>
                        <td class="sa-col-label sa-col-label-child">
                            <a href="<?= htmlspecialchars($invoiceViewBase . (int) ($inv['id'] ?? 0)) ?>">
                                <?= htmlspecialchars((string) ($inv['invoice_number'] ?? '')) ?>
                            </a>
                        </td>
                        <td class="sa-col-meta"><?= htmlspecialchars($dateLabel) ?></td>
                        <?php if ($useMonthGrid): ?>
                            <?php foreach ($months as $idx => $ym): ?>
                                <?php if ($idx === 0): ?>
                                    <td class="sa-matrix-val sa-col-amount <?= smart_report_matrix_value_class($total, $amountMax) ?>">
                                        <?= smart_report_sales_fmt_matrix_cell($total, false) ?>
                                    </td>
                                <?php elseif ($idx === 1): ?>
                                    <td class="sa-matrix-val sa-col-amount <?= smart_report_matrix_value_class($paid, $amountMax) ?>">
                                        <?= smart_report_sales_fmt_matrix_cell($paid, false) ?>
                                    </td>
                                <?php elseif ($idx === 2): ?>
                                    <td class="sa-matrix-val sa-col-amount sa-col-balance <?= smart_report_matrix_value_class($balance, $amountMax) ?>">
                                        <?= smart_report_sales_fmt_matrix_cell($balance, false) ?>
                                    </td>
                                <?php elseif ($idx === $statusMonthIdx): ?>
                                    <td class="sa-col-status"><?= htmlspecialchars($statusLabel) ?></td>
                                <?php else: ?>
                                    <td class="sa-col-month-gap" aria-hidden="true"></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td class="sa-matrix-val sa-col-amount <?= smart_report_matrix_value_class($total, $amountMax) ?>">
                                <?= smart_report_sales_fmt_matrix_cell($total, false) ?>
                            </td>
                            <td class="sa-matrix-val sa-col-amount <?= smart_report_matrix_value_class($paid, $amountMax) ?>">
                                <?= smart_report_sales_fmt_matrix_cell($paid, false) ?>
                            </td>
                            <td class="sa-matrix-val sa-col-amount sa-col-balance <?= smart_report_matrix_value_class($balance, $amountMax) ?>">
                                <?= smart_report_sales_fmt_matrix_cell($balance, false) ?>
                            </td>
                            <td class="sa-col-status"><?= htmlspecialchars($statusLabel) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_top_customers_matrix_data')) {
    function smart_report_top_customers_matrix_data(PDO $pdo, array $filters): array
    {
        $months = smart_report_sales_month_columns($filters['start_date'], $filters['end_date']);
        $empty = [
            'has_data' => false,
            'months' => $months,
            'rows' => [],
            'totals_row' => ['label' => 'All Top Customers', 'months' => array_fill_keys($months, 0.0), 'total' => 0.0],
            'label_col' => 'Customer',
        ];

        if (!tableExists('invoices', $pdo)) {
            return $empty;
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];

        try {
            $sqlTop = "SELECT c.id AS customer_id,
                        COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS label,
                        COALESCE(SUM(i.total_amount), 0) AS revenue
                 FROM invoices i
                 LEFT JOIN customers c ON c.id = i.customer_id
                 WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
            $paramsTop = [$start, $end];
            analytics_scoped_tables($sqlTop, $paramsTop, ['i' => 'invoices', 'c' => 'customers'], $pdo);
            $sqlTop .= ' GROUP BY c.id, label ORDER BY revenue DESC LIMIT 15';
            $stTop = $pdo->prepare($sqlTop);
            $stTop->execute($paramsTop);
            $topLabels = $stTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($topLabels)) {
                return $empty;
            }

            $rowMap = [];
            foreach ($topLabels as $t) {
                $label = (string) $t['label'];
                $rowMap[$label] = [
                    'label' => $label,
                    'customer_id' => isset($t['customer_id']) ? (int) $t['customer_id'] : 0,
                    'months' => array_fill_keys($months, 0.0),
                    'total' => 0.0,
                ];
            }

            $sql = "SELECT COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS label,
                        DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                        COALESCE(SUM(i.total_amount), 0) AS metric
                 FROM invoices i
                 LEFT JOIN customers c ON c.id = i.customer_id
                 WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
            $params = [$start, $end];
            analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
            $sql .= ' GROUP BY c.id, label, ym';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $label = (string) ($r['label'] ?? '');
                if (!isset($rowMap[$label])) {
                    continue;
                }
                $ym = (string) ($r['ym'] ?? '');
                $metric = (float) ($r['metric'] ?? 0);
                if (isset($rowMap[$label]['months'][$ym])) {
                    $rowMap[$label]['months'][$ym] += $metric;
                }
                $rowMap[$label]['total'] += $metric;
            }

            $hasSalesPerson = tableExists('users', $pdo) && columnExists('invoices', 'created_by', $pdo);
            if ($hasSalesPerson) {
                $sqlSp = "SELECT COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS label,
                            COALESCE(NULLIF(TRIM(u.full_name), ''), 'Unassigned') AS sales_person,
                            COALESCE(SUM(i.total_amount), 0) AS revenue
                     FROM invoices i
                     LEFT JOIN customers c ON c.id = i.customer_id
                     LEFT JOIN users u ON i.created_by = u.id
                     WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
                $paramsSp = [$start, $end];
                analytics_scoped_tables($sqlSp, $paramsSp, ['i' => 'invoices', 'c' => 'customers', 'u' => 'users'], $pdo);
                $sqlSp .= ' GROUP BY c.id, label, sales_person ORDER BY label ASC, revenue DESC';
                $stSp = $pdo->prepare($sqlSp);
                $stSp->execute($paramsSp);
                $salesByCustomer = [];
                foreach ($stSp->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sp) {
                    $label = (string) ($sp['label'] ?? '');
                    if ($label !== '' && !isset($salesByCustomer[$label])) {
                        $salesByCustomer[$label] = (string) ($sp['sales_person'] ?? 'Unassigned');
                    }
                }
                foreach ($rowMap as $label => &$row) {
                    $row['sales_person'] = $salesByCustomer[$label] ?? 'Unassigned';
                }
                unset($row);
            }

            $matrix = smart_report_assemble_metric_matrix($months, $rowMap, 'All Top Customers');
            $matrix['label_col'] = 'Customer';
            $matrix['row_drill'] = 'customer';
            if ($hasSalesPerson) {
                $matrix['meta_cols'] = [
                    ['field' => 'sales_person', 'label' => 'Sales Person'],
                ];
            }
            return $matrix;
        } catch (Throwable $e) {
            error_log('smart_report_top_customers_matrix_data: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('smart_report_dormant_products_matrix_data')) {
    function smart_report_dormant_products_matrix_data(PDO $pdo, array $filters): array
    {
        $months = smart_report_sales_month_columns($filters['start_date'], $filters['end_date']);
        $empty = [
            'has_data' => false,
            'months' => $months,
            'rows' => [],
            'totals_row' => ['label' => 'All Most Purchased Products', 'months' => array_fill_keys($months, 0.0), 'total' => 0.0],
            'label_col' => 'Product',
        ];

        if (!tableExists('products', $pdo) || !tableExists('sales_order_items', $pdo) || !tableExists('sales_orders', $pdo)) {
            return $empty;
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];

        try {
            $imageGroup = [];
            $imageSelectParts = [];
            if (columnExists('products', 'main_image', $pdo)) {
                $imageSelectParts[] = 'p.main_image';
                $imageGroup[] = 'p.main_image';
            }
            if (columnExists('products', 'image', $pdo)) {
                $imageSelectParts[] = 'p.image';
                $imageGroup[] = 'p.image';
            }
            $imageSelect = !empty($imageSelectParts)
                ? implode(', ', $imageSelectParts)
                : "'' AS main_image";
            $imageGroupSql = !empty($imageGroup) ? ', ' . implode(', ', $imageGroup) : '';

            $periodSub = "SELECT soi2.product_id,
                            COALESCE(SUM(soi2.line_total), 0) AS period_total,
                            COALESCE(SUM(soi2.quantity), 0) AS period_qty
                     FROM sales_order_items soi2
                     INNER JOIN sales_orders so2 ON so2.id = soi2.order_id
                     WHERE so2.status NOT IN ('cancelled', 'draft')
                       AND so2.quote_date BETWEEN ? AND ?";
            $paramsDormant = [$start, $end];
            analytics_scoped_tables($periodSub, $paramsDormant, [
                'soi2' => 'sales_order_items',
                'so2' => 'sales_orders',
            ], $pdo);
            $periodSub .= ' GROUP BY soi2.product_id';

            $sqlDormant = "SELECT p.id,
                        p.name AS product_name,
                        {$imageSelect},
                        DATEDIFF(CURDATE(), MAX(so.quote_date)) AS days_since_sale,
                        COALESCE(period_sales.period_total, 0) AS period_total,
                        COALESCE(period_sales.period_qty, 0) AS period_qty
                 FROM products p
                 INNER JOIN sales_order_items soi ON soi.product_id = p.id
                 INNER JOIN sales_orders so ON so.id = soi.order_id
                 LEFT JOIN ({$periodSub}) period_sales ON period_sales.product_id = p.id
                 WHERE so.status NOT IN ('cancelled')";
            analytics_scoped_tables($sqlDormant, $paramsDormant, [
                'p' => 'products',
                'soi' => 'sales_order_items',
                'so' => 'sales_orders',
            ], $pdo);
            $sqlDormant .= " GROUP BY p.id, p.name, period_sales.period_total, period_sales.period_qty{$imageGroupSql}
                 HAVING days_since_sale >= 90
                    AND (COALESCE(period_sales.period_total, 0) > 0 OR COALESCE(period_sales.period_qty, 0) > 0)
                 ORDER BY period_qty DESC, period_total DESC, days_since_sale DESC
                 LIMIT 50";
            $stDormant = $pdo->prepare($sqlDormant);
            $stDormant->execute($paramsDormant);
            $dormantList = $stDormant->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($dormantList)) {
                return $empty;
            }

            $productIds = [];
            $rowMap = [];
            foreach ($dormantList as $p) {
                $productIds[] = (int) $p['id'];
                $label = (string) $p['product_name'];
                $rowMap[$label] = [
                    'label' => $label,
                    'product_id' => (int) $p['id'],
                    'image_url' => smart_report_product_image_url($pdo, (int) $p['id'], $p),
                    'months' => array_fill_keys($months, 0.0),
                    'total' => 0.0,
                    'qty_total' => (float) ($p['period_qty'] ?? 0),
                    'sort' => (int) ($p['days_since_sale'] ?? 0),
                ];
            }

            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql = "SELECT p.name AS label,
                        DATE_FORMAT(so.quote_date, '%Y-%m') AS ym,
                        COALESCE(SUM(soi.line_total), 0) AS metric
                 FROM sales_order_items soi
                 INNER JOIN sales_orders so ON so.id = soi.order_id
                 INNER JOIN products p ON p.id = soi.product_id
                 WHERE so.status NOT IN ('cancelled', 'draft')
                   AND p.id IN ({$placeholders})
                   AND so.quote_date BETWEEN ? AND ?";
            $params = array_merge($productIds, [$start, $end]);
            analytics_scoped_tables($sql, $params, [
                'soi' => 'sales_order_items',
                'so' => 'sales_orders',
                'p' => 'products',
            ], $pdo);
            $sql .= ' GROUP BY p.id, p.name, ym';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $label = (string) ($r['label'] ?? '');
                if (!isset($rowMap[$label])) {
                    continue;
                }
                $ym = (string) ($r['ym'] ?? '');
                $metric = (float) ($r['metric'] ?? 0);
                if (isset($rowMap[$label]['months'][$ym])) {
                    $rowMap[$label]['months'][$ym] += $metric;
                }
                $rowMap[$label]['total'] += $metric;
            }

            $hasCustomer = tableExists('customers', $pdo) && columnExists('sales_orders', 'customer_id', $pdo);
            if ($hasCustomer) {
                $sqlCust = "SELECT p.name AS label,
                            COALESCE(NULLIF(TRIM(c.company_name), ''), 'Walk-in') AS customer,
                            COALESCE(SUM(soi.line_total), 0) AS revenue
                     FROM sales_order_items soi
                     INNER JOIN sales_orders so ON so.id = soi.order_id
                     INNER JOIN products p ON p.id = soi.product_id
                     LEFT JOIN customers c ON c.id = so.customer_id
                     WHERE so.status NOT IN ('cancelled', 'draft')
                       AND p.id IN ({$placeholders})
                       AND so.quote_date BETWEEN ? AND ?";
                $paramsCust = array_merge($productIds, [$start, $end]);
                analytics_scoped_tables($sqlCust, $paramsCust, [
                    'soi' => 'sales_order_items',
                    'so' => 'sales_orders',
                    'p' => 'products',
                    'c' => 'customers',
                ], $pdo);
                $sqlCust .= ' GROUP BY p.id, p.name, customer ORDER BY label ASC, revenue DESC';
                $stCust = $pdo->prepare($sqlCust);
                $stCust->execute($paramsCust);
                $customerByProduct = [];
                foreach ($stCust->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cust) {
                    $label = (string) ($cust['label'] ?? '');
                    if ($label !== '' && !isset($customerByProduct[$label])) {
                        $customerByProduct[$label] = (string) ($cust['customer'] ?? 'Walk-in');
                    }
                }
                foreach ($rowMap as $label => &$row) {
                    $row['customer'] = $customerByProduct[$label] ?? 'Walk-in';
                }
                unset($row);
            }

            $rowMap = array_filter($rowMap, static function (array $row): bool {
                return ((float) ($row['total'] ?? 0)) > 0;
            });
            if (empty($rowMap)) {
                return $empty;
            }

            $matrix = smart_report_assemble_metric_matrix(
                $months,
                $rowMap,
                'All Most Purchased Products',
                static function ($a, $b) {
                    $byQty = ($b['qty_total'] ?? 0) <=> ($a['qty_total'] ?? 0);
                    if ($byQty !== 0) {
                        return $byQty;
                    }
                    $byTotal = ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
                    if ($byTotal !== 0) {
                        return $byTotal;
                    }
                    return ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0);
                }
            );
            $matrix['label_col'] = 'Product';
            $matrix['prefix_cols'] = [
                ['field' => 'image_url', 'label' => 'Image', 'type' => 'image'],
            ];
            if ($hasCustomer) {
                $matrix['meta_cols'] = [
                    ['field' => 'customer', 'label' => 'Customer'],
                ];
            }
            $matrix['preview_rows'] = 10;
            $matrix['view_all_label'] = 'products';
            return $matrix;
        } catch (Throwable $e) {
            error_log('smart_report_dormant_products_matrix_data: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('smart_report_ranking_matrices')) {
    function smart_report_ranking_matrices(PDO $pdo, array $filters): array
    {
        return [
            'top_customers' => smart_report_top_customers_matrix_data($pdo, $filters),
            'dormant_products' => smart_report_dormant_products_matrix_data($pdo, $filters),
        ];
    }
}

if (!function_exists('smart_report_pipeline_matrix_data')) {
    function smart_report_pipeline_matrix_data(PDO $pdo, array $filters): array
    {
        $months = smart_report_sales_month_columns($filters['start_date'], $filters['end_date']);
        $empty = [
            'has_data' => false,
            'months' => $months,
            'rows' => [],
            'totals_row' => ['label' => 'All Open Deals', 'months' => array_fill_keys($months, 0.0), 'total' => 0.0],
            'label_col' => 'Deal',
            'preserve_labels' => true,
        ];

        if (!tableExists('sales_orders', $pdo)) {
            return $empty;
        }

        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $openStatuses = "('draft', 'quotation', 'confirmed', 'processing', 'on_hold')";

        try {
            $sqlDeals = "SELECT so.order_number,
                        COALESCE(NULLIF(TRIM(c.company_name), ''), 'Customer') AS customer_name,
                        so.total_amount,
                        so.quote_date
                 FROM sales_orders so
                 LEFT JOIN customers c ON c.id = so.customer_id
                 WHERE so.status IN {$openStatuses}
                   AND so.quote_date BETWEEN ? AND ?";
            $paramsDeals = [$start, $end];
            analytics_scoped_tables($sqlDeals, $paramsDeals, ['so' => 'sales_orders', 'c' => 'customers'], $pdo);
            $sqlDeals .= ' ORDER BY so.total_amount DESC LIMIT 50';
            $stDeals = $pdo->prepare($sqlDeals);
            $stDeals->execute($paramsDeals);
            $deals = $stDeals ? ($stDeals->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            if (empty($deals)) {
                return $empty;
            }

            $rowMap = [];
            foreach ($deals as $deal) {
                $label = (string) $deal['order_number'] . ' — ' . (string) $deal['customer_name'];
                $rowMap[$label] = [
                    'label' => $label,
                    'months' => array_fill_keys($months, 0.0),
                    'total' => 0.0,
                    'sort' => (float) ($deal['total_amount'] ?? 0),
                ];
                $quoteDate = (string) ($deal['quote_date'] ?? '');
                if ($quoteDate !== '') {
                    $ym = date('Y-m', strtotime($quoteDate));
                    $amount = (float) ($deal['total_amount'] ?? 0);
                    if (isset($rowMap[$label]['months'][$ym])) {
                        $rowMap[$label]['months'][$ym] = $amount;
                        $rowMap[$label]['total'] = $amount;
                    }
                }
            }

            $matrix = smart_report_assemble_metric_matrix(
                $months,
                $rowMap,
                'All Open Deals',
                static function ($a, $b) {
                    return ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0);
                }
            );

            $totalsMonths = array_fill_keys($months, 0.0);
            $grandTotal = 0.0;
            $sqlTotals = "SELECT DATE_FORMAT(so.quote_date, '%Y-%m') AS ym,
                        COALESCE(SUM(so.total_amount), 0) AS metric
                 FROM sales_orders so
                 WHERE so.status IN {$openStatuses}
                   AND so.quote_date BETWEEN ? AND ?";
            $paramsTotals = [$start, $end];
            analytics_scoped_tables($sqlTotals, $paramsTotals, ['so' => 'sales_orders'], $pdo);
            $sqlTotals .= ' GROUP BY ym';
            $stTotals = $pdo->prepare($sqlTotals);
            $stTotals->execute($paramsTotals);
            foreach ($stTotals->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ym = (string) ($r['ym'] ?? '');
                $metric = (float) ($r['metric'] ?? 0);
                if (isset($totalsMonths[$ym])) {
                    $totalsMonths[$ym] += $metric;
                    $grandTotal += $metric;
                }
            }

            $matrix['totals_row'] = [
                'label' => 'All Open Deals',
                'months' => $totalsMonths,
                'total' => $grandTotal,
            ];
            $matrix['label_col'] = 'Deal';
            $matrix['preserve_labels'] = true;
            $matrix['preview_rows'] = 10;
            $matrix['view_all_label'] = 'open deals';

            return $matrix;
        } catch (Throwable $e) {
            error_log('smart_report_pipeline_matrix_data: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('smart_report_pipeline_matrices')) {
    function smart_report_pipeline_matrices(PDO $pdo, array $filters): array
    {
        return [
            'open_deals' => smart_report_pipeline_matrix_data($pdo, $filters),
        ];
    }
}

if (!function_exists('smart_report_sales_revenue_matrices')) {
    function smart_report_sales_revenue_matrices(PDO $pdo, array $filters): array
    {
        $base = [
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
            'based_on' => 'sales_invoice',
            'tree_type' => 'territory',
            'value_qty' => 'value',
        ];

        return [
            'territory_revenue' => smart_report_sales_matrix_data($pdo, $base),
        ];
    }
}

if (!function_exists('smart_report_render_erp_matrix_html')) {
    function smart_report_render_erp_matrix_html(
        array $matrix,
        bool $isQty,
        string $tableId,
        string $title = '',
        bool $showTitle = true
    ): string {
        if (empty($matrix['has_data']) || empty($matrix['months'])) {
            return '';
        }

        $labelCol = (string) ($matrix['label_col'] ?? '');
        if ($labelCol === '') {
            $labelCol = smart_report_sales_tree_label(
                str_contains($tableId, 'territory') ? 'territory' : 'customer_group'
            );
            if ($tableId === 'sa-main-matrix') {
                $tree = $matrix['tree_type'] ?? 'customer_group';
                $labelCol = smart_report_sales_tree_label($tree);
            }
        }
        $preserveLabels = str_contains($tableId, 'dormant')
            || str_contains($tableId, 'customer-rank')
            || str_contains($tableId, 'pipeline')
            || ($matrix['preserve_labels'] ?? false);
        $monthMaxima = smart_report_matrix_month_maxima($matrix);
        $metaCols = $matrix['meta_cols'] ?? [];
        $prefixCols = $matrix['prefix_cols'] ?? [];
        $previewRows = max(0, (int) ($matrix['preview_rows'] ?? 0));
        $rowCount = count($matrix['rows']);
        $extraCount = $previewRows > 0 ? max(0, $rowCount - $previewRows) : 0;
        $viewAllLabel = (string) ($matrix['view_all_label'] ?? 'rows');
        $rowDrill = (string) ($matrix['row_drill'] ?? '');

        ob_start();
        ?>
        <?php if ($showTitle && $title !== ''): ?>
        <div class="sa-matrix-block">
            <h4 class="sales-drill-sub"><?= htmlspecialchars($title) ?></h4>
        <?php endif; ?>
            <div class="sa-matrix-card is-tree-collapsed" data-matrix="<?= htmlspecialchars($tableId) ?>"<?= $previewRows > 0 ? ' data-preview-rows="' . $previewRows . '"' : '' ?><?= $viewAllLabel !== '' ? ' data-view-all-label="' . htmlspecialchars($viewAllLabel) . '"' : '' ?>>
                <div class="sa-matrix-scroll">
                    <table class="sa-matrix" id="<?= htmlspecialchars($tableId) ?>">
                        <thead>
                            <tr>
                                <th class="sa-col-check"><input type="checkbox" aria-label="Select all"></th>
                                <th class="sa-col-num">#</th>
                                <?php foreach ($prefixCols as $col): ?>
                                    <th class="sa-col-meta<?= ($col['type'] ?? '') === 'image' ? ' sa-col-image' : '' ?>"><?= htmlspecialchars((string) $col['label']) ?></th>
                                <?php endforeach; ?>
                                <th class="sa-col-label"><?= htmlspecialchars($labelCol) ?></th>
                                <?php foreach ($metaCols as $col): ?>
                                    <th class="sa-col-meta"><?= htmlspecialchars((string) $col['label']) ?></th>
                                <?php endforeach; ?>
                                <?php foreach ($matrix['months'] as $ym): ?>
                                    <th><?= htmlspecialchars(date('M Y', strtotime($ym . '-01'))) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="sa-row-total sa-row-parent">
                                <td class="sa-col-check"><input type="checkbox" checked aria-label="Select total row"></td>
                                <td class="sa-col-num">1</td>
                                <?php foreach ($prefixCols as $col): ?>
                                    <?= smart_report_render_matrix_meta_cell($col, [], true) ?>
                                <?php endforeach; ?>
                                <td class="sa-col-label">
                                    <button type="button" class="sa-tree-toggle bi bi-chevron-right" aria-label="Toggle rows" aria-expanded="false"></button>
                                    <?= htmlspecialchars((string) $matrix['totals_row']['label']) ?>
                                </td>
                                <?php foreach ($metaCols as $col): ?>
                                    <?= smart_report_render_matrix_meta_cell($col, [], true) ?>
                                <?php endforeach; ?>
                                <?php foreach ($matrix['months'] as $ym): ?>
                                    <?php $cellVal = (float) ($matrix['totals_row']['months'][$ym] ?? 0); ?>
                                    <td class="sa-matrix-val <?= smart_report_matrix_value_class($cellVal, (float) ($monthMaxima[$ym] ?? 0), true) ?>">
                                        <?= smart_report_sales_fmt_matrix_cell($cellVal, $isQty) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php foreach ($matrix['rows'] as $idx => $row): ?>
                            <tr class="sa-row-child<?= ($previewRows > 0 && $idx >= $previewRows) ? ' sa-row-extra' : '' ?><?= $rowDrill === 'customer' ? ' sa-row-drillable' : '' ?>"<?= $rowDrill === 'customer' ? ' data-customer-id="' . (int) ($row['customer_id'] ?? 0) . '" data-customer-label="' . htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES) . '"' : '' ?>>
                                <td class="sa-col-check"><input type="checkbox" aria-label="Select row"></td>
                                <td class="sa-col-num"><?= $idx + 2 ?></td>
                                <?php foreach ($prefixCols as $col): ?>
                                    <?= smart_report_render_matrix_meta_cell($col, $row) ?>
                                <?php endforeach; ?>
                                <td class="sa-col-label sa-col-label-child"><?= htmlspecialchars(
                                    $preserveLabels
                                        ? (string) $row['label']
                                        : ucwords(str_replace('_', ' ', (string) $row['label']))
                                ) ?></td>
                                <?php foreach ($metaCols as $col): ?>
                                    <?= smart_report_render_matrix_meta_cell($col, $row) ?>
                                <?php endforeach; ?>
                                <?php foreach ($matrix['months'] as $ym): ?>
                                    <?php $cellVal = (float) ($row['months'][$ym] ?? 0); ?>
                                    <td class="sa-matrix-val <?= smart_report_matrix_value_class($cellVal, (float) ($monthMaxima[$ym] ?? 0)) ?>">
                                        <?= smart_report_sales_fmt_matrix_cell($cellVal, $isQty) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($extraCount > 0): ?>
                <div class="sa-matrix-actions">
                    <button type="button" class="sa-view-all-btn" aria-expanded="false">
                        View all <?= number_format($rowCount) ?> <?= htmlspecialchars($viewAllLabel) ?>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        <?php if ($showTitle && $title !== ''): ?>
        </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_render_sales_drilldown_html')) {
    function smart_report_render_sales_drilldown_html(array $d): string
    {
        if (!$d['has_data']) {
            return '<p class="text-muted mb-0">No invoice data available in the system.</p>';
        }

        $fmt = 'analytics_fmt_money';
        $periodLabel = htmlspecialchars((string) ($d['period']['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $periodNote = $periodLabel !== '' ? ' Data for ' . $periodLabel . '.' : '';
        ob_start();
        ?>
        <div class="sales-drill-sections">
            <section class="sales-drill-section" id="sa-revenue">
                <?= smart_report_render_section_head(
                    'bi-bar-chart-line',
                    'Sales Revenue & Volume',
                    'Total sales revenue categorized by time period, customer segment, and territory. Gross profit margins track revenue versus COGS.' . $periodNote,
                    'primary'
                ) ?>
                <div class="sales-kpi-grid sa-revenue-kpi-row">
                    <?= smart_report_render_kpi_card('bi-receipt', 'Invoices', number_format($d['summary']['invoice_count']), 'indigo') ?>
                    <?= smart_report_render_kpi_card('bi-cash-stack', 'Revenue', $fmt($d['summary']['total_revenue']), 'blue') ?>
                    <?= smart_report_render_kpi_card('bi-box-seam', 'COGS', $fmt($d['gross_profit']['cogs']), 'amber') ?>
                    <?= smart_report_render_kpi_card('bi-graph-up', 'Gross profit', $fmt($d['gross_profit']['gross_profit']), 'green') ?>
                    <?= smart_report_render_kpi_card('bi-percent', 'Margin', number_format($d['gross_profit']['margin_pct'], 1) . '%', 'violet') ?>
                </div>
                <?php
                $matrices = $d['revenue_matrices'] ?? [];
                echo smart_report_render_erp_matrix_html(
                    $matrices['territory_revenue'] ?? [],
                    false,
                    'sa-rev-territory-value',
                    'Revenue by Territory'
                );
                ?>
            </section>

            <section class="sales-drill-section sales-drill-section--metrics" id="sa-performance">
                <?php
                $sp = $d['sales_performance'] ?? [];
                $teamAchievement = $sp['achievement_pct'] ?? null;
                $achTone = 'slate';
                if ($teamAchievement !== null) {
                    if ($teamAchievement >= 100) {
                        $achTone = 'green';
                    } elseif ($teamAchievement >= 80) {
                        $achTone = 'amber';
                    } else {
                        $achTone = 'down';
                    }
                }
                ?>
                <?= smart_report_render_section_head(
                    'bi-graph-up-arrow',
                    'Performance vs. Targets',
                    'Overall team results and individual sales employee performance against defined targets.' . $periodNote,
                    'info'
                ) ?>
                <div class="sales-kpi-grid sa-performance-kpi-row">
                    <?= smart_report_render_kpi_card(
                        'bi-bullseye',
                        !empty($sp['has_company_target']) ? 'Company target' : 'Team target',
                        ($sp['team_target'] ?? 0) > 0 ? $fmt($sp['team_target']) : 'Not set',
                        'indigo'
                    ) ?>
                    <?= smart_report_render_kpi_card('bi-cash-stack', 'Team sales', $fmt($sp['team_actual'] ?? 0), 'blue') ?>
                    <?= smart_report_render_kpi_card(
                        'bi-speedometer2',
                        'Overall achievement',
                        $teamAchievement !== null ? number_format($teamAchievement, 1) . '%' : 'N/A',
                        $achTone
                    ) ?>
                    <?= smart_report_render_kpi_card(
                        'bi-people',
                        'Reps on target',
                        ($sp['rep_count'] ?? 0) > 0
                            ? number_format((int) ($sp['reps_on_track'] ?? 0)) . ' / ' . number_format((int) ($sp['rep_count'] ?? 0))
                            : '0',
                        'green'
                    ) ?>
                </div>
                <?= smart_report_render_rep_performance_html($sp) ?>
            </section>

            <section class="sales-drill-section" id="sa-ranking">
                <?= smart_report_render_section_head(
                    'bi-people',
                    'Customer & Product Ranking',
                    'Top customers by buying behavior and gross margin; products not recently purchased.' . $periodNote,
                    'warning'
                ) ?>
                <?php
                $rankingMatrices = $d['ranking_matrices'] ?? [];
                echo smart_report_render_erp_matrix_html(
                    $rankingMatrices['top_customers'] ?? [],
                    false,
                    'sa-customer-rank',
                    'Top Customers by Revenue'
                );
                echo smart_report_render_erp_matrix_html(
                    $rankingMatrices['dormant_products'] ?? [],
                    false,
                    'sa-dormant-products',
                    'Most Purchased Products — Not Recently Reordered (90+ days)'
                );
                ?>
            </section>

            <section class="sales-drill-section sales-drill-section--metrics" id="sa-fulfillment">
                <?= smart_report_render_section_head(
                    'bi-truck',
                    'Order Fulfillment Metrics',
                    'On-time delivery rates, lead times, and order-to-cash cycle for orders in the selected period.' . $periodNote,
                    'secondary'
                ) ?>
                <?= smart_report_render_coming_soon_panel('bi-hourglass-split') ?>
            </section>

            <section class="sales-drill-section sales-drill-section--metrics" id="sa-pipeline">
                <?= smart_report_render_section_head(
                    'bi-funnel',
                    'Sales Pipeline & Forecasts',
                    'Open sales orders (draft, quotation, confirmed, processing, on hold) quoted in the selected period.' . $periodNote,
                    'primary'
                ) ?>
                <div class="sales-kpi-grid sales-kpi-grid--2">
                    <?= smart_report_render_kpi_card('bi-briefcase', 'Open Deals', number_format((int) $d['pipeline']['count']), 'blue') ?>
                    <?= smart_report_render_kpi_card('bi-cash-stack', 'Pipeline Value', $fmt($d['pipeline']['value']), 'indigo') ?>
                </div>
                <?php
                $pipelineMatrices = $d['pipeline_matrices'] ?? [];
                echo smart_report_render_erp_matrix_html(
                    $pipelineMatrices['open_deals'] ?? [],
                    false,
                    'sa-pipeline-matrix',
                    'Open Deals by Month'
                );
                ?>
            </section>

            <section class="sales-drill-section sales-drill-section--metrics" id="sa-ar-aging">
                <?php $ar = $d['ar_aging'] ?? []; ?>
                <?= smart_report_render_section_head(
                    'bi-clock-history',
                    'Accounts Receivable Aging',
                    'Outstanding balance on invoices issued in the selected period, aged as of the period end date.' . $periodNote,
                    'danger'
                ) ?>
                <div class="sales-kpi-grid sa-ar-aging-kpi-row">
                    <?= smart_report_render_kpi_card('bi-wallet2', 'Current', $fmt($ar['current'] ?? 0), 'green') ?>
                    <?= smart_report_render_kpi_card('bi-calendar3', '1-30 days', $fmt($ar['days_1_30'] ?? 0), 'blue') ?>
                    <?= smart_report_render_kpi_card('bi-calendar-week', '31-60 days', $fmt($ar['days_31_60'] ?? 0), 'amber') ?>
                    <?= smart_report_render_kpi_card('bi-calendar-range', '61-90 days', $fmt($ar['days_61_90'] ?? 0), 'violet') ?>
                    <?= smart_report_render_kpi_card('bi-exclamation-triangle', '90+ days', $fmt($ar['days_90_plus'] ?? 0), 'down') ?>
                    <?= smart_report_render_kpi_card('bi-cash-stack', 'Total outstanding', $fmt($ar['total_outstanding'] ?? 0), 'indigo') ?>
                </div>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('smart_report_sales_amounts_close')) {
    function smart_report_sales_amounts_close(float $a, float $b, float $tolerance = 0.01): bool
    {
        return abs($a - $b) <= $tolerance;
    }
}

if (!function_exists('smart_report_sales_ground_truth_metrics')) {
    function smart_report_sales_ground_truth_metrics(PDO $pdo, array $filters): array
    {
        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $truth = [
            'invoice_revenue' => 0.0,
            'invoice_count' => 0,
            'pipeline_count' => 0,
            'pipeline_value' => 0.0,
            'ar_total_outstanding' => 0.0,
        ];

        if (!tableExists('invoices', $pdo)) {
            return $truth;
        }

        try {
            $sql = "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND invoice_date BETWEEN ? AND ?";
            $params = [$start, $end];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $truth['invoice_revenue'] = (float) ($row[0] ?? 0);
            $truth['invoice_count'] = (int) ($row[1] ?? 0);
        } catch (Throwable $e) {
            error_log('smart_report_sales_ground_truth_metrics invoices: ' . $e->getMessage());
        }

        if (tableExists('sales_orders', $pdo)) {
            $openStatuses = "('draft', 'quotation', 'confirmed', 'processing', 'on_hold')";
            try {
                $sql = "SELECT COUNT(*), COALESCE(SUM(total_amount), 0)
                     FROM sales_orders
                     WHERE status IN {$openStatuses}
                       AND quote_date BETWEEN ? AND ?";
                $params = [$start, $end];
                analytics_append_company_scope($sql, $params, 'sales_orders', '', $pdo);
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $pc = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
                $truth['pipeline_count'] = (int) ($pc[0] ?? 0);
                $truth['pipeline_value'] = (float) ($pc[1] ?? 0);
            } catch (Throwable $e) {
                error_log('smart_report_sales_ground_truth_metrics pipeline: ' . $e->getMessage());
            }
        }

        try {
            $dueCol = columnExists('invoices', 'due_date', $pdo) ? 'due_date' : 'invoice_date';
            $sql = "SELECT COALESCE(SUM(balance_due), 0)
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND balance_due > 0
                   AND invoice_date BETWEEN ? AND ?";
            $params = [$start, $end];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $truth['ar_total_outstanding'] = (float) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log('smart_report_sales_ground_truth_metrics ar: ' . $e->getMessage());
        }

        return $truth;
    }
}

if (!function_exists('smart_report_sales_add_verify_issue')) {
    function smart_report_sales_add_verify_issue(
        array &$issues,
        string $id,
        string $metric,
        string $section,
        float $displayed,
        float $expected,
        string $message,
        bool $isCount = false
    ): void {
        $fmt = 'analytics_fmt_money';
        $issues[] = [
            'id' => $id,
            'metric' => $metric,
            'section' => $section,
            'displayed' => $displayed,
            'expected' => $expected,
            'displayed_fmt' => $isCount ? number_format((int) round($displayed)) : $fmt($displayed),
            'expected_fmt' => $isCount ? number_format((int) round($expected)) : $fmt($expected),
            'message' => $message,
        ];
    }
}

if (!function_exists('smart_report_sales_add_verify_text_issue')) {
    function smart_report_sales_add_verify_text_issue(
        array &$issues,
        string $id,
        string $metric,
        string $section,
        string $shown,
        string $expected,
        string $message
    ): void {
        $issues[] = [
            'id' => $id,
            'metric' => $metric,
            'section' => $section,
            'displayed' => 0.0,
            'expected' => 0.0,
            'displayed_fmt' => $shown,
            'expected_fmt' => $expected,
            'message' => $message,
        ];
    }
}

if (!function_exists('smart_report_sales_period_company_isolation')) {
    /**
     * Compare scoped vs unscoped period metrics to detect cross-company leakage.
     *
     * @return array{
     *   invoice_unscoped_revenue: float,
     *   invoice_unscoped_count: int,
     *   invoice_foreign_revenue: float,
     *   invoice_foreign_count: int,
     *   invoice_foreign_companies: int,
     *   pipeline_unscoped_value: float,
     *   pipeline_unscoped_count: int,
     *   pipeline_foreign_value: float,
     *   pipeline_foreign_count: int
     * }
     */
    function smart_report_sales_period_company_isolation(PDO $pdo, array $filters, int $companyId): array
    {
        $start = $filters['start_date'];
        $end = $filters['end_date'];
        $out = [
            'invoice_unscoped_revenue' => 0.0,
            'invoice_unscoped_count' => 0,
            'invoice_foreign_revenue' => 0.0,
            'invoice_foreign_count' => 0,
            'invoice_foreign_companies' => 0,
            'pipeline_unscoped_value' => 0.0,
            'pipeline_unscoped_count' => 0,
            'pipeline_foreign_value' => 0.0,
            'pipeline_foreign_count' => 0,
        ];

        if ($companyId <= 0 || !tableExists('invoices', $pdo) || !columnExists('invoices', 'company_id', $pdo)) {
            return $out;
        }

        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND invoice_date BETWEEN ? AND ?"
            );
            $st->execute([$start, $end]);
            $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $out['invoice_unscoped_revenue'] = (float) ($row[0] ?? 0);
            $out['invoice_unscoped_count'] = (int) ($row[1] ?? 0);
        } catch (Throwable $e) {
            error_log('smart_report_sales_period_company_isolation invoices unscoped: ' . $e->getMessage());
        }

        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(total_amount), 0), COUNT(*), COUNT(DISTINCT company_id)
                 FROM invoices
                 WHERE status != 'cancelled'
                   AND invoice_date BETWEEN ? AND ?
                   AND company_id IS NOT NULL
                   AND company_id != 0
                   AND company_id != ?"
            );
            $st->execute([$start, $end, $companyId]);
            $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
            $out['invoice_foreign_revenue'] = (float) ($row[0] ?? 0);
            $out['invoice_foreign_count'] = (int) ($row[1] ?? 0);
            $out['invoice_foreign_companies'] = (int) ($row[2] ?? 0);
        } catch (Throwable $e) {
            error_log('smart_report_sales_period_company_isolation invoices foreign: ' . $e->getMessage());
        }

        if (tableExists('sales_orders', $pdo) && columnExists('sales_orders', 'company_id', $pdo)) {
            $openStatuses = "('draft', 'quotation', 'confirmed', 'processing', 'on_hold')";
            try {
                $st = $pdo->prepare(
                    "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
                     FROM sales_orders
                     WHERE status IN {$openStatuses}
                       AND quote_date BETWEEN ? AND ?"
                );
                $st->execute([$start, $end]);
                $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
                $out['pipeline_unscoped_value'] = (float) ($row[0] ?? 0);
                $out['pipeline_unscoped_count'] = (int) ($row[1] ?? 0);
            } catch (Throwable $e) {
                error_log('smart_report_sales_period_company_isolation pipeline unscoped: ' . $e->getMessage());
            }

            try {
                $st = $pdo->prepare(
                    "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
                     FROM sales_orders
                     WHERE status IN {$openStatuses}
                       AND quote_date BETWEEN ? AND ?
                       AND company_id IS NOT NULL
                       AND company_id != 0
                       AND company_id != ?"
                );
                $st->execute([$start, $end, $companyId]);
                $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
                $out['pipeline_foreign_value'] = (float) ($row[0] ?? 0);
                $out['pipeline_foreign_count'] = (int) ($row[1] ?? 0);
            } catch (Throwable $e) {
                error_log('smart_report_sales_period_company_isolation pipeline foreign: ' . $e->getMessage());
            }
        }

        return $out;
    }
}

if (!function_exists('smart_report_sales_verify_company_scope')) {
    /**
     * @param array<int, array<string, mixed>> $issues
     */
    function smart_report_sales_verify_company_scope(
        PDO $pdo,
        array $filters,
        array $displayed,
        array $truth,
        array &$issues,
        int &$checks
    ): array {
        $context = analytics_company_display_context($pdo);
        $companyId = (int) ($context['company_id'] ?? 0);
        $companyName = (string) ($context['company_name'] ?? '');

        $urlMismatch = analytics_url_company_mismatch($pdo);
        if ($urlMismatch !== null) {
            $checks++;
            smart_report_sales_add_verify_text_issue(
                $issues,
                'company_url_mismatch',
                'Company context',
                'Data isolation',
                $companyName !== '' ? $companyName : ('Company #' . $companyId),
                (string) ($urlMismatch['name'] ?? ('Company #' . ($urlMismatch['id'] ?? 0))),
                'The active session company does not match the company in this URL. Analytics may be showing the wrong company\'s data.'
            );

            return $context;
        }

        // Verify that the active connection database matches the company's configured database
        global $control_pdo;
        $dbPdo = $control_pdo ?? $pdo;
        $expectedDb = '';
        if ($companyId > 0 && tableExists('companies', $dbPdo) && columnExists('companies', 'db_name', $dbPdo)) {
            try {
                $st = $dbPdo->prepare("SELECT db_name FROM companies WHERE id = ? LIMIT 1");
                $st->execute([$companyId]);
                $expectedDb = trim((string) $st->fetchColumn());
            } catch (Throwable $e) {
            }
        }
        $activeDb = '';
        try {
            $activeDb = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        } catch (Throwable $e) {
        }
        if ($expectedDb !== '' && $activeDb !== '' && strcasecmp($expectedDb, $activeDb) !== 0) {
            $checks++;
            smart_report_sales_add_verify_text_issue(
                $issues,
                'company_database_mismatch',
                'Database isolation',
                'Data isolation',
                $activeDb,
                $expectedDb,
                'The active database connection does not match the company\'s configured database. You are viewing another company\'s database.'
            );
        }

        if (!analytics_should_verify_company_isolation($pdo)) {
            return $context;
        }

        if ($companyId <= 0) {
            $checks++;
            smart_report_sales_add_verify_text_issue(
                $issues,
                'company_context',
                'Company context',
                'Data isolation',
                'Not resolved',
                'Active company required',
                'Sales analytics could not be tied to a company. Metrics may include records from multiple companies.'
            );

            return $context;
        }

        $isolation = smart_report_sales_period_company_isolation($pdo, $filters, $companyId);
        $displayedRevenue = (float) (($displayed['summary']['total_revenue'] ?? 0));
        $scopedRevenue = (float) ($truth['invoice_revenue'] ?? 0);
        $unscopedRevenue = (float) ($isolation['invoice_unscoped_revenue'] ?? 0);
        $foreignRevenue = (float) ($isolation['invoice_foreign_revenue'] ?? 0);
        $displayedPipeline = (float) (($displayed['pipeline']['value'] ?? 0));
        $scopedPipeline = (float) ($truth['pipeline_value'] ?? 0);
        $unscopedPipeline = (float) ($isolation['pipeline_unscoped_value'] ?? 0);
        $foreignPipeline = (float) ($isolation['pipeline_foreign_value'] ?? 0);

        $checks++;
        $multiCompanyInvoices = $unscopedRevenue > $scopedRevenue + 0.01;
        if ($multiCompanyInvoices
            && smart_report_sales_amounts_close($displayedRevenue, $unscopedRevenue)
            && !smart_report_sales_amounts_close($displayedRevenue, $scopedRevenue)
        ) {
            smart_report_sales_add_verify_issue(
                $issues,
                'company_revenue',
                'Revenue company scope',
                'Data isolation',
                $displayedRevenue,
                $scopedRevenue,
                'Displayed revenue matches all companies in the database, not only '
                    . ($companyName !== '' ? $companyName : ('company #' . $companyId))
                    . '. Other companies have '
                    . number_format((int) ($isolation['invoice_foreign_count'] ?? 0))
                    . ' invoice(s) in this period.'
            );
        }

        $checks++;
        if ($foreignRevenue > 0.01
            && smart_report_sales_amounts_close($displayedRevenue, $scopedRevenue + $foreignRevenue)
            && !smart_report_sales_amounts_close($displayedRevenue, $scopedRevenue)
        ) {
            smart_report_sales_add_verify_issue(
                $issues,
                'company_foreign_revenue',
                'Foreign company revenue',
                'Data isolation',
                $displayedRevenue,
                $scopedRevenue,
                'Displayed revenue appears to include '
                    . analytics_fmt_money($foreignRevenue)
                    . ' from '
                    . (int) ($isolation['invoice_foreign_companies'] ?? 0)
                    . ' other company/companies in this period.'
            );
        }

        $checks++;
        $multiCompanyPipeline = $unscopedPipeline > $scopedPipeline + 0.01;
        if ($multiCompanyPipeline
            && smart_report_sales_amounts_close($displayedPipeline, $unscopedPipeline)
            && !smart_report_sales_amounts_close($displayedPipeline, $scopedPipeline)
        ) {
            smart_report_sales_add_verify_issue(
                $issues,
                'company_pipeline',
                'Pipeline company scope',
                'Data isolation',
                $displayedPipeline,
                $scopedPipeline,
                'Displayed pipeline value matches all companies, not only '
                    . ($companyName !== '' ? $companyName : ('company #' . $companyId))
                    . '.'
            );
        }

        $checks++;
        if ($foreignPipeline > 0.01
            && smart_report_sales_amounts_close($displayedPipeline, $scopedPipeline + $foreignPipeline)
            && !smart_report_sales_amounts_close($displayedPipeline, $scopedPipeline)
        ) {
            smart_report_sales_add_verify_issue(
                $issues,
                'company_foreign_pipeline',
                'Foreign company pipeline',
                'Data isolation',
                $displayedPipeline,
                $scopedPipeline,
                'Displayed pipeline value appears to include open deals from other companies.'
            );
        }

        foreach (($displayed['sales_performance']['reps'] ?? []) as $rep) {
            $userId = (int) ($rep['user_id'] ?? 0);
            if ($userId <= 0 || analytics_user_in_company($pdo, $userId)) {
                continue;
            }
            $checks++;
            smart_report_sales_add_verify_text_issue(
                $issues,
                'company_rep_' . $userId,
                'Sales rep company',
                'Performance vs. Targets',
                (string) ($rep['name'] ?? ('User #' . $userId)),
                $companyName !== '' ? $companyName : ('Company #' . $companyId),
                'A sales employee shown in performance metrics does not belong to the active company.'
            );
        }

        return $context;
    }
}

if (!function_exists('smart_report_sales_verify_displayed_data')) {
    function smart_report_sales_verify_displayed_data(PDO $pdo, array $filters, ?array $displayed = null): array
    {
        if ($displayed === null) {
            $displayed = smart_report_sales_drilldown($pdo, $filters);
            $displayed['pipeline_matrices'] = smart_report_pipeline_matrices($pdo, $filters);
        }

        $truth = smart_report_sales_ground_truth_metrics($pdo, $filters);
        $issues = [];
        $checks = 0;
        $summary = $displayed['summary'] ?? [];
        $gp = $displayed['gross_profit'] ?? [];
        $sp = $displayed['sales_performance'] ?? [];
        $pipeline = $displayed['pipeline'] ?? [];
        $ar = $displayed['ar_aging'] ?? [];

        $checks++;
        if (!smart_report_sales_amounts_close((float) ($summary['total_revenue'] ?? 0), $truth['invoice_revenue'])) {
            smart_report_sales_add_verify_issue(
                $issues,
                'revenue',
                'Revenue',
                'Sales Revenue & Volume',
                (float) ($summary['total_revenue'] ?? 0),
                $truth['invoice_revenue'],
                'Displayed revenue does not match the sum of non-cancelled invoice totals in the database for this period.'
            );
        }

        $checks++;
        if ((int) ($summary['invoice_count'] ?? 0) !== $truth['invoice_count']) {
            smart_report_sales_add_verify_issue(
                $issues,
                'invoice_count',
                'Invoice count',
                'Sales Revenue & Volume',
                (float) ($summary['invoice_count'] ?? 0),
                (float) $truth['invoice_count'],
                'Displayed invoice count does not match the number of invoices in the database for this period.',
                true
            );
        }

        $checks++;
        $gpRevenue = (float) ($gp['revenue'] ?? 0);
        $gpCogs = (float) ($gp['cogs'] ?? 0);
        $gpProfit = (float) ($gp['gross_profit'] ?? 0);
        $calcProfit = $gpRevenue - $gpCogs;
        if (!smart_report_sales_amounts_close($gpProfit, $calcProfit)) {
            smart_report_sales_add_verify_issue(
                $issues,
                'gross_profit_math',
                'Gross profit',
                'Sales Revenue & Volume',
                $gpProfit,
                $calcProfit,
                'Gross profit shown does not equal revenue minus COGS.'
            );
        }

        $checks++;
        $marginPct = (float) ($gp['margin_pct'] ?? 0);
        $calcMargin = $gpRevenue > 0 ? round(($calcProfit / $gpRevenue) * 100, 1) : 0.0;
        if (!smart_report_sales_amounts_close($marginPct, $calcMargin, 0.05)) {
            smart_report_sales_add_verify_issue(
                $issues,
                'margin_pct',
                'Margin %',
                'Sales Revenue & Volume',
                $marginPct,
                $calcMargin,
                'Margin percentage is inconsistent with gross profit and revenue.'
            );
        }

        $checks++;
        $teamActual = (float) ($sp['team_actual'] ?? 0);
        if (!smart_report_sales_amounts_close($teamActual, $truth['invoice_revenue'])) {
            smart_report_sales_add_verify_issue(
                $issues,
                'team_sales',
                'Team sales',
                'Performance vs. Targets',
                $teamActual,
                $truth['invoice_revenue'],
                'Team sales total does not match invoice revenue for the selected period.'
            );
        }

        $checks++;
        $repSum = 0.0;
        foreach ($sp['reps'] ?? [] as $rep) {
            $repSum += (float) ($rep['actual'] ?? 0);
        }
        if ($repSum > $teamActual + 0.01) {
            smart_report_sales_add_verify_issue(
                $issues,
                'rep_sum',
                'Rep sales total',
                'Performance vs. Targets',
                $repSum,
                $teamActual,
                'The sum of individual rep sales exceeds the team sales total.'
            );
        }

        $checks++;
        $pipelineCount = (int) ($pipeline['count'] ?? 0);
        if ($pipelineCount !== $truth['pipeline_count']) {
            smart_report_sales_add_verify_issue(
                $issues,
                'pipeline_count',
                'Open deals',
                'Sales Pipeline & Forecasts',
                (float) $pipelineCount,
                (float) $truth['pipeline_count'],
                'Open deals count does not match open sales orders in the database for this period.',
                true
            );
        }

        $checks++;
        $pipelineValue = (float) ($pipeline['value'] ?? 0);
        if (!smart_report_sales_amounts_close($pipelineValue, $truth['pipeline_value'])) {
            smart_report_sales_add_verify_issue(
                $issues,
                'pipeline_value',
                'Pipeline value',
                'Sales Pipeline & Forecasts',
                $pipelineValue,
                $truth['pipeline_value'],
                'Pipeline value does not match the total of open order amounts in the database for this period.'
            );
        }

        $checks++;
        $matrixTotal = (float) (($displayed['pipeline_matrices']['open_deals']['totals_row']['total'] ?? 0));
        if ($matrixTotal > 0 && !smart_report_sales_amounts_close($matrixTotal, $pipelineValue)) {
            smart_report_sales_add_verify_issue(
                $issues,
                'pipeline_matrix',
                'Pipeline matrix total',
                'Sales Pipeline & Forecasts',
                $matrixTotal,
                $pipelineValue,
                'The pipeline monthly matrix total does not match the pipeline value KPI.'
            );
        }

        $checks++;
        $arTotal = (float) ($ar['total_outstanding'] ?? 0);
        if (!smart_report_sales_amounts_close($arTotal, $truth['ar_total_outstanding'])) {
            smart_report_sales_add_verify_issue(
                $issues,
                'ar_total',
                'AR total outstanding',
                'Accounts Receivable Aging',
                $arTotal,
                $truth['ar_total_outstanding'],
                'Total outstanding AR does not match unpaid invoice balances in the database for this period.'
            );
        }

        $checks++;
        $bucketSum = (float) ($ar['current'] ?? 0)
            + (float) ($ar['days_1_30'] ?? 0)
            + (float) ($ar['days_31_60'] ?? 0)
            + (float) ($ar['days_61_90'] ?? 0)
            + (float) ($ar['days_90_plus'] ?? 0);
        if (!smart_report_sales_amounts_close($bucketSum, $arTotal)) {
            smart_report_sales_add_verify_issue(
                $issues,
                'ar_buckets',
                'AR aging buckets',
                'Accounts Receivable Aging',
                $bucketSum,
                $arTotal,
                'AR aging buckets do not add up to the total outstanding amount shown.'
            );
        }

        $companyContext = smart_report_sales_verify_company_scope($pdo, $filters, $displayed, $truth, $issues, $checks);

        return [
            'accurate' => empty($issues),
            'check_count' => $checks,
            'issue_count' => count($issues),
            'issues' => $issues,
            'company' => [
                'id' => $companyContext['company_id'] ?? null,
                'name' => $companyContext['company_name'] ?? '',
                'scoping_active' => !empty($companyContext['scoping_active']),
            ],
            'period' => $displayed['period'] ?? [
                'start_date' => $filters['start_date'],
                'end_date' => $filters['end_date'],
            ],
            'verified_at' => date('c'),
        ];
    }
}

if (!function_exists('smart_report_sales_ai_verify_analysis')) {
    function smart_report_sales_ai_verify_analysis(PDO $pdo, array $verification): array
    {
        $accurate = !empty($verification['accurate']);
        $issues = $verification['issues'] ?? [];
        $period = $verification['period'] ?? [];
        $periodLabel = ($period['start_date'] ?? '') . ' to ' . ($period['end_date'] ?? '');
        $company = $verification['company'] ?? [];
        $companyName = trim((string) ($company['name'] ?? ''));
        $companyLabel = $companyName !== '' ? $companyName : 'the active company';

        if ($accurate) {
            return [
                'source' => 'rules',
                'summary' => 'All ' . (int) ($verification['check_count'] ?? 0)
                    . ' verification checks passed successfully. Displayed metrics match '
                    . $companyLabel
                    . ' database records for '
                    . $periodLabel
                    . ' with 100% accuracy and truth, verifying that only this company\'s data is displayed.',
                'details' => [],
            ];
        }

        $rulesDetails = [];
        foreach ($issues as $issue) {
            $rulesDetails[] = ($issue['section'] ?? 'Report') . ' — '
                . ($issue['metric'] ?? 'Metric') . ': shown '
                . ($issue['displayed_fmt'] ?? '') . ', expected '
                . ($issue['expected_fmt'] ?? '') . '. '
                . ($issue['message'] ?? '');
        }

        try {
            $aiHelpers = __DIR__ . '/../../../includes/ai_helpers.php';
            if (!is_file($aiHelpers)) {
                return [
                    'source' => 'rules',
                    'summary' => count($issues) . ' data accuracy issue(s) found.',
                    'details' => $rulesDetails,
                ];
            }
            require_once $aiHelpers;

            $settings = ai_fetch_settings_row();
            if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
                return [
                    'source' => 'rules',
                    'summary' => count($issues) . ' data accuracy issue(s) found.',
                    'details' => $rulesDetails,
                ];
            }

            $issueText = '';
            foreach ($issues as $issue) {
                $issueText .= '- ' . ($issue['metric'] ?? '') . ' (' . ($issue['section'] ?? '') . '): '
                    . 'displayed ' . ($issue['displayed_fmt'] ?? $issue['displayed'] ?? '')
                    . ', expected ' . ($issue['expected_fmt'] ?? $issue['expected'] ?? '') . '. '
                    . ($issue['message'] ?? '') . "\n";
            }

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a strict data accuracy auditor for an ERP sales analytics dashboard. '
                        . 'Verify that only the active company\'s data is displayed and that all company metrics are 100% accurate, truthful, and isolated. '
                        . 'Given verification failures, explain each issue clearly and suggest the most likely root cause '
                        . '(query filter, date range, company scope, wrong company data, rounding, missing joins, unassigned records, etc.). '
                        . 'Use plain language. Format exactly:\n'
                        . "SUMMARY: [one sentence overview]\n"
                        . "DETAIL: [explanation for one issue]\n"
                        . 'Up to one DETAIL line per issue.',
                ],
                [
                    'role' => 'user',
                    'content' => "Active company: {$companyLabel}\nPeriod: {$periodLabel}\nVerification failures:\n{$issueText}",
                ],
            ];

            $openai = ai_openai_request($messages);
            $content = (string) ($openai['content'] ?? '');
            $summary = '';
            $details = [];
            foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (stripos($line, 'SUMMARY:') === 0) {
                    $summary = trim(substr($line, 8));
                } elseif (stripos($line, 'DETAIL:') === 0) {
                    $details[] = trim(substr($line, 7));
                }
            }

            if ($summary === '') {
                $summary = count($issues) . ' data accuracy issue(s) found.';
            }
            if (empty($details)) {
                $details = $rulesDetails;
            }

            return [
                'source' => 'ai',
                'summary' => $summary,
                'details' => $details,
            ];
        } catch (Throwable $e) {
            error_log('smart_report_sales_ai_verify_analysis: ' . $e->getMessage());
            return [
                'source' => 'rules',
                'summary' => count($issues) . ' data accuracy issue(s) found.',
                'details' => $rulesDetails,
            ];
        }
    }
}

if (!function_exists('smart_report_render_data_checker_result')) {
    function smart_report_render_data_checker_result(array $verification, array $analysis, bool $serviceOk = true): string
    {
        $accurate = $serviceOk && !empty($verification['accurate']);
        $stateClass = $accurate ? 'sa-data-checker--ok' : 'sa-data-checker--error';
        $icon = $accurate ? 'bi-shield-check' : 'bi-shield-exclamation';
        $summary = $serviceOk
            ? ($analysis['summary'] ?? ($accurate
                ? 'All metrics verified.'
                : ((int) ($verification['issue_count'] ?? 0)) . ' accuracy issue(s) found.'))
            : 'Verification could not be completed.';

        $bodyHtml = '';
        if (!$accurate && $serviceOk) {
            $issues = $verification['issues'] ?? [];
            $details = $analysis['details'] ?? [];
            if ($issues !== []) {
                $bodyHtml .= '<ul class="sa-data-checker-issues">';
                foreach ($issues as $issue) {
                    $bodyHtml .= '<li><strong>'
                        . htmlspecialchars((string) ($issue['metric'] ?? 'Metric'), ENT_QUOTES, 'UTF-8')
                        . ' — '
                        . htmlspecialchars((string) ($issue['section'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</strong>';
                    $bodyHtml .= 'Shown: '
                        . htmlspecialchars((string) ($issue['displayed_fmt'] ?? $issue['displayed'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . ' · Expected: '
                        . htmlspecialchars((string) ($issue['expected_fmt'] ?? $issue['expected'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $bodyHtml .= '<em>'
                        . htmlspecialchars((string) ($issue['message'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . '</em></li>';
                }
                $bodyHtml .= '</ul>';
            }
            if ($details !== []) {
                $bodyHtml .= '<ul class="sa-data-checker-details">';
                foreach ($details as $detail) {
                    $bodyHtml .= '<li>' . htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8') . '</li>';
                }
                $bodyHtml .= '</ul>';
            }
            if ($bodyHtml !== '') {
                $bodyHtml = '<p class="sa-data-checker-blocked-note">Sales analytics are hidden until these data issues are resolved.</p>'
                    . $bodyHtml;
            }
        }

        return '<div class="sa-data-checker ' . $stateClass . ($accurate ? '' : ' sa-data-checker--blocking') . '" id="saDataChecker" aria-live="polite">'
            . '<div class="sa-data-checker-inner">'
            . '<span class="sa-data-checker-icon" aria-hidden="true"><i class="bi ' . $icon . '"></i></span>'
            . '<div class="sa-data-checker-copy">'
            . '<strong class="sa-data-checker-title">AI Data Checker</strong>'
            . '<span class="sa-data-checker-status">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>'
            . '</div>'
            . ($bodyHtml !== '' ? '<div class="sa-data-checker-body">' . $bodyHtml . '</div>' : '')
            . '</div>';
    }
}

