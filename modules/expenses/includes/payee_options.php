<?php
/**
 * Aggregate payee/vendor names from vouchers, expenses, customers, suppliers, and payees master.
 */
if (!function_exists('expenses_collect_payee_options')) {
    function expenses_normalize_payee_name($name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    }

    function expenses_add_payee_option(array &$merged, string $name, string $source, int $limit): void
    {
        $name = trim((string) $name);
        if ($name === '' || stripos($name, '(draft') === 0) {
            return;
        }
        if (count($merged) >= $limit && !isset($merged[expenses_normalize_payee_name($name)])) {
            return;
        }

        $key = expenses_normalize_payee_name($name);
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name' => $name,
                'sources' => [],
            ];
        }
        if (!in_array($source, $merged[$key]['sources'], true)) {
            $merged[$key]['sources'][] = $source;
        }
    }

    function expenses_collect_payee_options($pdo, string $q = '', int $limit = 100): array
    {
        $merged = [];
        $returnLimit = max(10, min(500, (int) $limit));
        $collectLimit = 2000;

        $runNames = static function ($db, string $sql, array $params, string $source) use (&$merged, $collectLimit): void {
            if (!$db instanceof PDO) {
                return;
            }
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    expenses_add_payee_option($merged, (string) ($row['n'] ?? ''), $source, $collectLimit);
                }
            } catch (Throwable $e) {
            }
        };

        if (function_exists('tableExists') && tableExists('payees', $pdo)) {
            $runNames(
                $pdo,
                "SELECT DISTINCT TRIM(name) AS n FROM payees WHERE is_active = 1 AND name IS NOT NULL AND TRIM(name) != '' ORDER BY name ASC LIMIT 500",
                [],
                'Payee'
            );
        }

        if (function_exists('tableExists') && tableExists('payment_vouchers', $pdo)) {
            $runNames(
                $pdo,
                "SELECT DISTINCT TRIM(payee_name) AS n FROM payment_vouchers
                 WHERE payee_name IS NOT NULL AND TRIM(payee_name) != '' AND payee_name NOT LIKE '(Draft%'
                 ORDER BY payee_name ASC LIMIT 500",
                [],
                'Voucher'
            );
        }

        if (function_exists('tableExists') && tableExists('erp_expenses', $pdo)) {
            $runNames(
                $pdo,
                "SELECT DISTINCT TRIM(payee) AS n FROM erp_expenses
                 WHERE payee IS NOT NULL AND TRIM(payee) != ''
                 ORDER BY payee ASC LIMIT 500",
                [],
                'Expense'
            );
        }

        $customerDb = $pdo;
        $salesFunctions = dirname(__DIR__, 2) . '/sales/functions.php';
        if (is_file($salesFunctions)) {
            require_once $salesFunctions;
            if (function_exists('sales_pdo')) {
                try {
                    $customerDb = sales_pdo();
                } catch (Throwable $e) {
                    $customerDb = $pdo;
                }
            }
        }

        if (function_exists('tableExists') && tableExists('customers', $customerDb)) {
            $customerSql = "SELECT DISTINCT TRIM(company_name) AS n FROM customers
                            WHERE company_name IS NOT NULL AND TRIM(company_name) != ''";
            $customerParams = [];
            if (function_exists('columnExists') && columnExists('customers', 'status', $customerDb)) {
                $customerSql .= " AND status = 'active'";
            }
            $customerSql .= ' ORDER BY company_name ASC LIMIT 500';
            $runNames($customerDb, $customerSql, $customerParams, 'Customer');
        }

        $supplierQueries = [
            ['erp_suppliers', "SELECT DISTINCT TRIM(name) AS n FROM erp_suppliers WHERE name IS NOT NULL AND TRIM(name) != ''"],
            ['suppliers', "SELECT DISTINCT TRIM(name) AS n FROM suppliers WHERE name IS NOT NULL AND TRIM(name) != ''"],
            ['stocks_suppliers', "SELECT DISTINCT TRIM(name) AS n FROM stocks_suppliers WHERE name IS NOT NULL AND TRIM(name) != ''"],
        ];

        foreach ($supplierQueries as [$table, $sql]) {
            if (!function_exists('tableExists') || !tableExists($table, $pdo)) {
                continue;
            }
            if ($table === 'erp_suppliers' && function_exists('columnExists') && columnExists('erp_suppliers', 'status', $pdo)) {
                $sql .= " AND status = 'active'";
            }
            $sql .= ' ORDER BY name ASC LIMIT 500';
            $runNames($pdo, $sql, [], 'Supplier');
        }

        $options = [];
        foreach ($merged as $row) {
            $options[] = [
                'name' => $row['name'],
                'source' => implode(' / ', $row['sources']),
                'sources' => $row['sources'],
            ];
        }

        if ($q !== '') {
            $qNorm = expenses_normalize_payee_name($q);
            $options = array_values(array_filter($options, static function ($opt) use ($qNorm) {
                return strpos(expenses_normalize_payee_name($opt['name']), $qNorm) !== false;
            }));
        }

        usort($options, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return array_slice($options, 0, $returnLimit);
    }

    /**
     * Combined vendor list for expense create: payees, customers, suppliers (+ voucher/expense names).
     */
    function expenses_collect_vendor_picklist($pdo, string $q = '', int $limit = 300): array
    {
        return expenses_collect_payee_options($pdo, $q, $limit);
    }
}
