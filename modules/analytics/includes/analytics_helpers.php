<?php
/**
 * Data Analysis & Reports � shared query helpers.
 */

require_once __DIR__ . '/analytics_company_scope.php';

if (!function_exists('analytics_parse_filters')) {
    function analytics_parse_filters(): array
    {
        $start = $_GET['start_date'] ?? date('Y-m-01');
        $end = $_GET['end_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = date('Y-m-d');
        }
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'department' => trim((string) ($_GET['department'] ?? '')),
            'employee' => (int) ($_GET['employee'] ?? 0),
            'module' => trim((string) ($_GET['module'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'week_start' => trim((string) ($_GET['week_start'] ?? '')),
        ];
    }
}

if (!function_exists('analytics_fmt_money')) {
    function analytics_fmt_money($amount): string
    {
        return 'TSh ' . number_format((float) $amount, 0, '.', ',');
    }
}

if (!function_exists('analytics_get_departments')) {
    function analytics_get_departments(PDO $pdo): array
    {
        if (!tableExists('users', $pdo)) {
            return [];
        }
        $sql = "SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND TRIM(department) != ''";
        $params = [];
        analytics_append_company_scope($sql, $params, 'users', '', $pdo);
        $sql .= ' ORDER BY department ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }
}

if (!function_exists('analytics_get_employees')) {
    function analytics_get_employees(PDO $pdo, string $department = ''): array
    {
        if (!tableExists('users', $pdo)) {
            return [];
        }
        $sql = "SELECT id, full_name, department FROM users WHERE is_active = 1 AND role != 'admin'";
        $params = [];
        if ($department !== '') {
            $sql .= ' AND department = ?';
            $params[] = $department;
        }
        analytics_append_company_scope($sql, $params, 'users', '', $pdo);
        $sql .= ' ORDER BY full_name ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('analytics_week_start')) {
    function analytics_week_start(array $filters): string
    {
        if ($filters['week_start'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['week_start'])) {
            return $filters['week_start'];
        }
        if (function_exists('wm_get_week_bounds')) {
            return wm_get_week_bounds()['week_start'];
        }
        $monday = new DateTime();
        $monday->setISODate((int) date('o'), (int) date('W'));
        return $monday->format('Y-m-d');
    }
}

if (!function_exists('analytics_sum_invoices')) {
    function analytics_sum_invoices(PDO $pdo, string $start, string $end, string $field = 'total_amount'): float
    {
        if (!tableExists('invoices', $pdo)) {
            return 0.0;
        }
        $allowed = ['total_amount', 'amount_paid', 'balance_due'];
        if (!in_array($field, $allowed, true)) {
            $field = 'total_amount';
        }
        $sql = "SELECT COALESCE(SUM($field), 0) FROM invoices WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?";
        $params = [$start, $end];
        analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (float) $st->fetchColumn();
    }
}

if (!function_exists('analytics_sum_expenses')) {
    function analytics_sum_expenses(PDO $pdo, string $start, string $end): float
    {
        $total = 0.0;
        if (tableExists('payment_vouchers', $pdo)) {
            $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM payment_vouchers WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
            $params = [$start, $end];
            analytics_append_company_scope($sql, $params, 'payment_vouchers', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $total += (float) $st->fetchColumn();
        }
        if ($total <= 0 && tableExists('erp_expenses', $pdo)) {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM erp_expenses WHERE date BETWEEN ? AND ?"
            );
            $st->execute([$start, $end]);
            $total += (float) $st->fetchColumn();
        }
        if ($total <= 0 && tableExists('expenses_requests', $pdo)) {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM expenses_requests WHERE status = 'approved' AND date BETWEEN ? AND ?"
            );
            $st->execute([$start, $end]);
            $total += (float) $st->fetchColumn();
        }
        return $total;
    }
}

if (!function_exists('analytics_low_stock_count')) {
    function analytics_low_stock_count(PDO $pdo): int
    {
        if (!tableExists('products', $pdo)) {
            return 0;
        }
        if (tableExists('stock', $pdo)) {
            $st = $pdo->query(
                "SELECT COUNT(*) FROM products p
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE COALESCE(s.quantity, 0) <= COALESCE(p.reorder_level, 0)"
            );
            return (int) ($st->fetchColumn() ?: 0);
        }
        return 0;
    }
}

if (!function_exists('analytics_overview_kpis')) {
    function analytics_overview_kpis(PDO $pdo, array $filters): array
    {
        $start = $filters['start_date'];
        $end = $filters['end_date'];

        $totalSales = analytics_sum_invoices($pdo, $start, $end, 'total_amount');
        $totalExpenses = analytics_sum_expenses($pdo, $start, $end);
        $totalPaid = analytics_sum_invoices($pdo, $start, $end, 'amount_paid');
        $pendingPayments = max(0, $totalSales - $totalPaid);

        if (tableExists('invoices', $pdo)) {
            $sql = "SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status != 'cancelled'";
            $params = [];
            analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $pendingPayments = (float) ($st->fetchColumn() ?: $pendingPayments);
        }

        $weekStart = analytics_week_start($filters);
        $missionRate = 0.0;
        $avgPerformance = 0.0;

        if (tableExists('performance_points', $pdo)) {
            $sql = 'SELECT AVG(completion_rate) AS rate FROM performance_points WHERE week_start = ?';
            $params = [$weekStart];
            if ($filters['employee'] > 0) {
                $sql .= ' AND user_id = ?';
                $params[] = $filters['employee'];
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $missionRate = round((float) ($st->fetchColumn() ?: 0), 1);

            $sql2 = 'SELECT AVG(completion_rate) AS rate FROM performance_points WHERE week_start >= ? AND week_start <= ?';
            $params2 = [$start, $end];
            if ($filters['employee'] > 0) {
                $sql2 .= ' AND user_id = ?';
                $params2[] = $filters['employee'];
            }
            $st2 = $pdo->prepare($sql2);
            $st2->execute($params2);
            $avgPerformance = round((float) ($st2->fetchColumn() ?: 0), 1);
        }

        return [
            'total_sales' => $totalSales,
            'total_expenses' => $totalExpenses,
            'net_profit' => $totalPaid - $totalExpenses,
            'pending_payments' => $pendingPayments,
            'low_stock_alerts' => analytics_low_stock_count($pdo),
            'employee_performance_score' => $avgPerformance,
            'mission_completion_rate' => $missionRate,
        ];
    }
}

if (!function_exists('analytics_sales_trend')) {
    function analytics_sales_trend(PDO $pdo, array $filters): array
    {
        $labels = [];
        $data = [];
        if (!tableExists('invoices', $pdo)) {
            return ['labels' => $labels, 'data' => $data];
        }
        $sql = "SELECT DATE(invoice_date) AS d, COALESCE(SUM(total_amount), 0) AS total
             FROM invoices
             WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
        $sql .= " GROUP BY DATE(invoice_date) ORDER BY d ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $labels[] = date('M j', strtotime($row['d']));
            $data[] = (float) $row['total'];
        }
        return ['labels' => $labels, 'data' => $data];
    }
}

if (!function_exists('analytics_income_vs_expense')) {
    function analytics_income_vs_expense(PDO $pdo, array $filters): array
    {
        $labels = [];
        $income = [];
        $expense = [];
        if (!tableExists('invoices', $pdo)) {
            return ['labels' => $labels, 'income' => $income, 'expense' => $expense];
        }

        $sql = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym,
                    COALESCE(SUM(amount_paid), 0) AS paid
             FROM invoices
             WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        analytics_append_company_scope($sql, $params, 'invoices', '', $pdo);
        $sql .= ' GROUP BY ym ORDER BY ym ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $incomeMap = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $incomeMap[$row['ym']] = (float) $row['paid'];
        }

        $expenseMap = [];
        if (tableExists('payment_vouchers', $pdo)) {
            $sql2 = "SELECT DATE_FORMAT(date_created, '%Y-%m') AS ym,
                        COALESCE(SUM(total_amount), 0) AS spent
                 FROM payment_vouchers
                 WHERE status = 'approved' AND date_created BETWEEN ? AND ?";
            $params2 = [$filters['start_date'], $filters['end_date']];
            analytics_append_company_scope($sql2, $params2, 'payment_vouchers', '', $pdo);
            $sql2 .= ' GROUP BY ym ORDER BY ym ASC';
            $st2 = $pdo->prepare($sql2);
            $st2->execute($params2);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $expenseMap[$row['ym']] = (float) $row['spent'];
            }
        }

        $months = array_unique(array_merge(array_keys($incomeMap), array_keys($expenseMap)));
        sort($months);
        foreach ($months as $ym) {
            $labels[] = date('M Y', strtotime($ym . '-01'));
            $income[] = $incomeMap[$ym] ?? 0;
            $expense[] = $expenseMap[$ym] ?? 0;
        }
        return ['labels' => $labels, 'income' => $income, 'expense' => $expense];
    }
}

if (!function_exists('analytics_employee_performance_chart')) {
    function analytics_employee_performance_chart(PDO $pdo, array $filters): array
    {
        $weekStart = analytics_week_start($filters);
        if (function_exists('wm_leaderboard')) {
            $board = wm_leaderboard($pdo, $weekStart, 12);
            if ($filters['department'] !== '') {
                $board = array_values(array_filter($board, static function ($r) use ($filters) {
                    return ($r['department'] ?? '') === $filters['department'];
                }));
            }
            if ($filters['employee'] > 0) {
                $board = array_values(array_filter($board, static function ($r) use ($filters) {
                    return (int) $r['user_id'] === $filters['employee'];
                }));
            }
            return [
                'labels' => array_column($board, 'full_name'),
                'data' => array_map(static function ($r) {
                    return (float) $r['completion_rate'];
                }, $board),
            ];
        }
        return ['labels' => [], 'data' => []];
    }
}

if (!function_exists('analytics_mission_status_chart')) {
    function analytics_mission_status_chart(PDO $pdo, array $filters): array
    {
        if (!tableExists('weekly_missions', $pdo)) {
            return ['labels' => ['Completed', 'In Progress', 'Pending', 'Delayed'], 'data' => [0, 0, 0, 0]];
        }

        $weekStart = analytics_week_start($filters);
        $sql = 'SELECT wm.status, wm.completed_at, wm.due_day FROM weekly_missions wm';
        $params = [$weekStart];
        $where = ['wm.week_start = ?'];

        if ($filters['employee'] > 0) {
            $where[] = 'wm.user_id = ?';
            $params[] = $filters['employee'];
        }
        if ($filters['module'] !== '') {
            $where[] = 'wm.category = ?';
            $params[] = $filters['module'];
        }
        if ($filters['department'] !== '' && tableExists('users', $pdo)) {
            $sql .= ' INNER JOIN users u ON u.id = wm.user_id';
            $where[] = 'u.department = ?';
            $params[] = $filters['department'];
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        if ($filters['department'] !== '' && tableExists('users', $pdo)) {
            analytics_scoped_tables($sql, $params, ['u' => 'users'], $pdo);
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $counts = ['Completed' => 0, 'In Progress' => 0, 'Pending' => 0, 'Delayed' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $status = function_exists('wm_compute_status')
                ? wm_compute_status($m)
                : ($m['status'] ?? 'Pending');
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
        }

        if ($filters['status'] !== '' && isset($counts[$filters['status']])) {
            $filtered = array_fill_keys(array_keys($counts), 0);
            $filtered[$filters['status']] = $counts[$filters['status']];
            $counts = $filtered;
        }

        return ['labels' => array_keys($counts), 'data' => array_values($counts)];
    }
}

if (!function_exists('analytics_performance_rows')) {
    function analytics_performance_rows(PDO $pdo, array $filters): array
    {
        if (!tableExists('users', $pdo)) {
            return [];
        }

        $weekStart = analytics_week_start($filters);
        $hasPoints = tableExists('performance_points', $pdo);

        if ($hasPoints) {
            $sql = "SELECT u.id, u.full_name, u.department,
                    COALESCE(pp.total_missions, 0) AS total_missions,
                    COALESCE(pp.completed_missions, 0) AS completed_missions,
                    COALESCE(pp.delayed_missions, 0) AS delayed_missions,
                    COALESCE(pp.completion_rate, 0) AS completion_rate,
                    COALESCE(pp.award_points, 0) AS award_points,
                    COALESCE(pp.streak_count, 0) AS streak_count
                    FROM users u
                    LEFT JOIN performance_points pp ON pp.user_id = u.id AND pp.week_start = ?
                    WHERE u.is_active = 1 AND u.role != 'admin'";
            $params = [$weekStart];
        } else {
            $sql = "SELECT u.id, u.full_name, u.department,
                    0 AS total_missions, 0 AS completed_missions, 0 AS delayed_missions,
                    0 AS completion_rate, 0 AS award_points, 0 AS streak_count
                    FROM users u
                    WHERE u.is_active = 1 AND u.role != 'admin'";
            $params = [];
        }

        if ($filters['department'] !== '') {
            $sql .= ' AND u.department = ?';
            $params[] = $filters['department'];
        }
        if ($filters['employee'] > 0) {
            $sql .= ' AND u.id = ?';
            $params[] = $filters['employee'];
        }

        analytics_scoped_tables($sql, $params, ['u' => 'users'], $pdo);
        if ($hasPoints) {
            $sql .= ' ORDER BY pp.award_points DESC, pp.completion_rate DESC, u.full_name ASC';
        } else {
            $sql .= ' ORDER BY u.full_name ASC';
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $total = (int) $row['total_missions'];
            $completed = (int) $row['completed_missions'];
            $delayed = (int) $row['delayed_missions'];
            $row['pending_missions'] = max(0, $total - $completed - $delayed);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('analytics_monthly_top_performer')) {
    function analytics_monthly_top_performer(PDO $pdo): ?array
    {
        if (!tableExists('performance_points', $pdo) || !tableExists('users', $pdo)) {
            return null;
        }
        $monthStart = date('Y-m-01');
        $sql = "SELECT u.full_name, u.department, AVG(pp.completion_rate) AS avg_rate, SUM(pp.award_points) AS total_points
             FROM performance_points pp
             INNER JOIN users u ON u.id = pp.user_id
             WHERE pp.week_start >= ?";
        $params = [$monthStart];
        analytics_scoped_tables($sql, $params, ['u' => 'users'], $pdo);
        $sql .= ' GROUP BY u.id, u.full_name, u.department
             ORDER BY total_points DESC, avg_rate DESC
             LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('analytics_sales_rows')) {
    function analytics_sales_rows(PDO $pdo, array $filters): array
    {
        if (!tableExists('invoices', $pdo)) {
            return [];
        }
        $sql = "SELECT i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.balance_due, i.status,
                    COALESCE(c.company_name, 'Walk-in') AS customer_name
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        analytics_scoped_tables($sql, $params, ['i' => 'invoices', 'c' => 'customers'], $pdo);
        $sql .= ' ORDER BY i.invoice_date DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('analytics_finance_rows')) {
    function analytics_finance_rows(PDO $pdo, array $filters): array
    {
        $rows = [];
        if (tableExists('payment_vouchers', $pdo)) {
            $sql = "SELECT voucher_no AS ref, payee_name AS party, total_amount AS amount,
                        date_created AS txn_date, status, 'Payment Voucher' AS source
                 FROM payment_vouchers
                 WHERE date_created BETWEEN ? AND ?";
            $params = [$filters['start_date'], $filters['end_date']];
            analytics_append_company_scope($sql, $params, 'payment_vouchers', '', $pdo);
            $sql .= ' ORDER BY date_created DESC LIMIT 150';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        if (tableExists('erp_expenses', $pdo)) {
            $st = $pdo->prepare(
                "SELECT CONCAT('EXP-', id) AS ref, payee AS party, amount,
                        date AS txn_date, status, 'ERP Expense' AS source
                 FROM erp_expenses
                 WHERE date BETWEEN ? AND ?
                 ORDER BY date DESC
                 LIMIT 150"
            );
            $st->execute([$filters['start_date'], $filters['end_date']]);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        usort($rows, static function ($a, $b) {
            return strcmp((string) ($b['txn_date'] ?? ''), (string) ($a['txn_date'] ?? ''));
        });
        return array_slice($rows, 0, 200);
    }
}

if (!function_exists('analytics_mission_categories')) {
    function analytics_mission_categories(PDO $pdo): array
    {
        if (function_exists('wm_mission_categories')) {
            return wm_mission_categories();
        }
        if (!tableExists('weekly_missions', $pdo)) {
            return [];
        }
        return $pdo->query(
            "SELECT DISTINCT category FROM weekly_missions WHERE category IS NOT NULL AND TRIM(category) != '' ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
