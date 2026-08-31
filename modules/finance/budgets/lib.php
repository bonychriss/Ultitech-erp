<?php
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/../../../includes/mailer.php';

function budget_parse_period(string $periodType, string $periodKey): array
{
    // Returns [startDate, endDate] as Y-m-d strings.
    $periodType = strtolower(trim($periodType));
    $periodKey = trim($periodKey);

    if ($periodType === 'yearly') {
        $y = (int) $periodKey;
        if ($y <= 0) $y = (int) date('Y');
        return [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)];
    }

    if ($periodType === 'quarterly') {
        // Expect "YYYY-Qn"
        if (preg_match('/^(\d{4})-Q([1-4])$/', $periodKey, $m)) {
            $y = (int) $m[1];
            $q = (int) $m[2];
        } else {
            $y = (int) date('Y');
            $q = (int) ceil(((int) date('n')) / 3);
        }
        $startMonth = 1 + (($q - 1) * 3);
        $start = sprintf('%04d-%02d-01', $y, $startMonth);
        $endMonth = $startMonth + 2;
        $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $endMonth)));
        return [$start, $end];
    }

    // monthly: expect YYYY-MM
    if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
        $periodKey = date('Y-m');
    }
    $start = $periodKey . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function budget_money(float $v): string
{
    return number_format($v, 2);
}

function budget_safe_json_decode(?string $json): array
{
    if ($json === null || trim($json) === '') return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

function budget_get_item_sources(int $itemId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM budget_item_sources WHERE budget_item_id = ? ORDER BY id ASC');
    $st->execute([$itemId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['rule'] = budget_safe_json_decode($r['rule_json'] ?? null);
    }
    return $rows;
}

function budget_compute_actual_for_source(string $sourceType, array $rule, string $startDate, string $endDate): float
{
    global $pdo;
    $sourceType = strtolower(trim($sourceType));

    if ($sourceType === 'purchase_orders') {
        // PO actuals from receipt transactions (period = receipt date). Fallback: PO date + qty_received lines.
        // Draft/Cancelled excluded.
        $table = 'stocks_purchase_orders';
        $tableExists = false;
        try { $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_orders'")->fetchColumn(); } catch (Throwable $e) { $tableExists = false; }
        if (!$tableExists) return 0.0;

        $purchaseType = strtolower(trim((string)($rule['purchase_type'] ?? '')));
        $ptCol = resolveExistingColumn($table, 'purchase_type', []);
        $statusCol = resolveExistingColumn($table, 'status', []);

        $hasTx = false;
        try { $hasTx = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_transactions'")->fetchColumn(); } catch (Throwable $e) { $hasTx = false; }

        $txDateCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'transaction_date', ['created_at']) : null;
        $refTypeCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'reference_type', []) : null;
        $refIdCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'reference_id', []) : null;
        $qtyCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'quantity', []) : null;
        $costCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'unit_cost', []) : null;
        $typeCol = $hasTx ? resolveExistingColumn('stocks_transactions', 'type', []) : null;

        if ($hasTx && $txDateCol && $refTypeCol && $refIdCol && $qtyCol) {
            $params = [$startDate, $endDate];
            $where = "DATE(t.`$txDateCol`) BETWEEN ? AND ?";
            $where .= " AND LOWER(TRIM(COALESCE(t.`$refTypeCol`, ''))) = 'purchase_order'";
            $where .= " AND t.`$refIdCol` IS NOT NULL";
            if ($typeCol) {
                $where .= " AND LOWER(TRIM(COALESCE(t.`$typeCol`, ''))) = 'in'";
            }

            $join = "INNER JOIN `$table` po ON po.id = t.`$refIdCol`";

            if ($statusCol) {
                $where .= " AND LOWER(TRIM(COALESCE(po.`$statusCol`, ''))) NOT IN ('draft', 'cancelled')";
            }
            if ($ptCol && ($purchaseType === 'domestic' || $purchaseType === 'import')) {
                $where .= " AND po.`$ptCol` = ?";
                $params[] = $purchaseType;
            }

            $costExpr = $costCol
                ? "CAST(COALESCE(t.`$costCol`, 0) AS DECIMAL(18,6))"
                : 'CAST(0 AS DECIMAL(18,6))';

            $sql = "SELECT COALESCE(SUM(CAST(t.`$qtyCol` AS DECIMAL(18,4)) * $costExpr), 0)
                    FROM stocks_transactions t
                    $join
                    WHERE $where";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (float) $st->fetchColumn();
        }

        // Fallback: no stocks_transactions - use received lines, attributed to PO order date.
        $hasPoItems = false;
        try { $hasPoItems = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_po_items'")->fetchColumn(); } catch (Throwable $e) { $hasPoItems = false; }

        $amountCol = resolveExistingColumn($table, 'total_amount', ['grand_total', 'total', 'amount', 'subtotal']);
        $receivedLineSumSql = "(SELECT COALESCE(SUM(CAST(pi.qty_received AS DECIMAL(18,4)) * CAST(pi.unit_cost AS DECIMAL(18,6))), 0) FROM stocks_po_items pi WHERE pi.po_id = po.id)";
        if ($hasPoItems) {
            $perPoAmount = $receivedLineSumSql;
        } elseif ($amountCol) {
            $statusColFb = resolveExistingColumn($table, 'status', []);
            if ($statusColFb) {
                $perPoAmount = "(CASE WHEN LOWER(TRIM(COALESCE(po.`$statusColFb`, ''))) = 'received' OR LOWER(TRIM(COALESCE(po.`$statusColFb`, ''))) LIKE '%received%' THEN CAST(po.`$amountCol` AS DECIMAL(18,2)) ELSE 0 END)";
            } else {
                return 0.0;
            }
        } else {
            return 0.0;
        }

        $dateCol = resolveExistingColumn($table, 'created_at', ['order_date', 'po_date', 'purchase_date', 'date', 'updated_at']);
        if (!$dateCol) $dateCol = 'created_at';

        $params = [$startDate, $endDate];
        $where = "DATE(po.`$dateCol`) BETWEEN ? AND ?";

        if ($ptCol && ($purchaseType === 'domestic' || $purchaseType === 'import')) {
            $where .= " AND po.`$ptCol` = ?";
            $params[] = $purchaseType;
        }

        if ($statusCol) {
            $where .= " AND LOWER(TRIM(COALESCE(po.`$statusCol`, ''))) NOT IN ('draft', 'cancelled')";
        }

        $sql = "SELECT COALESCE(SUM($perPoAmount), 0) FROM `$table` po WHERE $where";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (float) $st->fetchColumn();
    }

    if ($sourceType === 'payroll') {
        // Sum payslips net_salary for runs in period; only approved/paid.
        $hasRuns = false;
        $hasSlips = false;
        try { $hasRuns = (bool) $pdo->query("SHOW TABLES LIKE 'payroll_runs'")->fetchColumn(); } catch (Throwable $e) { $hasRuns = false; }
        try { $hasSlips = (bool) $pdo->query("SHOW TABLES LIKE 'payslips'")->fetchColumn(); } catch (Throwable $e) { $hasSlips = false; }
        if (!$hasRuns || !$hasSlips) return 0.0;

        $netCol = resolveExistingColumn('payslips', 'net_salary', ['net', 'net_pay', 'amount']);
        if (!$netCol) return 0.0;

        // payroll period based on run month/year
        $sql = "
            SELECT COALESCE(SUM(CAST(p.`$netCol` AS DECIMAL(18,2))),0)
            FROM payslips p
            JOIN payroll_runs r ON p.payroll_run_id = r.id
            WHERE DATE(CONCAT(r.year,'-',LPAD(r.month,2,'0'),'-01')) BETWEEN ? AND ?
              AND r.status IN ('approved','paid')
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$startDate, $endDate]);
        return (float) $st->fetchColumn();
    }

    return 0.0;
}

function budget_compute_item_actual(int $itemId, string $startDate, string $endDate): float
{
    $sources = budget_get_item_sources($itemId);
    $total = 0.0;
    foreach ($sources as $s) {
        $total += budget_compute_actual_for_source((string)($s['source_type'] ?? ''), (array)($s['rule'] ?? []), $startDate, $endDate);
    }
    return $total;
}

function budget_compute_variance_percent(float $budgeted, float $actual): float
{
    if ($budgeted <= 0) return 0.0;
    // Variance % as (Actual/Budget)*100 for monitoring threshold.
    return ($actual / $budgeted) * 100.0;
}

/**
 * Share of budget period elapsed by calendar (0–1), based on inclusive days from start through today/end.
 */
function budget_period_elapsed_fraction(string $periodStart, string $periodEnd, ?string $today = null): float
{
    $today = $today ?: date('Y-m-d');
    try {
        $d0 = new DateTimeImmutable($periodStart);
        $d1 = new DateTimeImmutable($periodEnd);
        $dt = new DateTimeImmutable($today);
    } catch (Exception $e) {
        return 1.0;
    }
    $totalDays = (int) $d0->diff($d1)->days + 1;
    if ($totalDays < 1) {
        return 1.0;
    }
    if ($dt < $d0) {
        return 0.0;
    }
    if ($dt > $d1) {
        return 1.0;
    }
    $elapsedDays = (int) $d0->diff($dt)->days + 1;

    return min(1.0, max(0.0, $elapsedDays / $totalDays));
}

/**
 * True when spend % exceeds “linear” time-based pace by at least $marginPercentPoints (e.g. 15 = 15 percentage points).
 * Skips very early period (before $minElapsedFraction of time has passed) and when there is no spend.
 */
function budget_pacing_ahead_of_schedule(float $budgeted, float $actual, string $periodStart, string $periodEnd, float $marginPercentPoints = 15.0, float $minElapsedFraction = 0.05): bool
{
    if ($budgeted <= 0.0 || $actual <= 0.0) {
        return false;
    }
    $elapsed = budget_period_elapsed_fraction($periodStart, $periodEnd);
    if ($elapsed < $minElapsedFraction) {
        return false;
    }
    $spentPct = budget_compute_variance_percent($budgeted, $actual);
    $expectedLinearPct = $elapsed * 100.0;

    return $spentPct >= ($expectedLinearPct + $marginPercentPoints);
}

/**
 * Currencies offered when creating a budget (ISO 4217 codes).
 *
 * @return array<string, string> code => label
 */
function budget_currency_options(): array
{
    // Plain ASCII in labels avoids encoding issues that can empty option text in the browser.
    return [
        'TZS' => 'TZS - Tanzanian Shilling',
        'USD' => 'USD - US Dollar',
        'EUR' => 'EUR - Euro',
        'GBP' => 'GBP - British Pound',
        'KES' => 'KES - Kenyan Shilling',
        'UGX' => 'UGX - Ugandan Shilling',
        'RWF' => 'RWF - Rwandan Franc',
        'ZAR' => 'ZAR - South African Rand',
        'CNY' => 'CNY - Chinese Yuan',
        'INR' => 'INR - Indian Rupee',
        'AED' => 'AED - UAE Dirham',
        'CHF' => 'CHF - Swiss Franc',
        'JPY' => 'JPY - Japanese Yen',
    ];
}

function budget_normalize_currency(string $code): string
{
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    $allowed = array_keys(budget_currency_options());
    return in_array($code, $allowed, true) ? $code : 'TZS';
}

/**
 * Deep link to open a budget in the finance module (header notifications).
 */
function budget_link_open(int $budgetId, ?string $periodType = null, ?string $periodKey = null): string
{
    $pt = $periodType ?: 'monthly';
    $pk = $periodKey;
    if ($pk === null || $pk === '') {
        $pk = ($pt === 'yearly') ? date('Y') : date('Y-m');
    }
    $q = http_build_query([
        'module' => 'finance',
        'id' => $budgetId,
        'period_type' => $pt,
        'period' => $pk,
    ]);
    return app_url('/modules/finance/budgets/budget.php?' . $q);
}

function budget_link_dashboard(int $budgetId, string $periodType, string $periodKey): string
{
    $q = http_build_query([
        'module' => 'finance',
        'id' => $budgetId,
        'period_type' => $periodType,
        'period' => $periodKey,
    ]);
    return app_url('/modules/finance/budgets/dashboard.php?' . $q);
}

/**
 * Period key for links after create (from budget start date).
 */
function budget_default_period_key(string $periodType, string $startDate): string
{
    $periodType = strtolower(trim($periodType));
    $startDate = trim($startDate);
    if ($startDate === '') {
        return $periodType === 'yearly' ? date('Y') : ($periodType === 'quarterly' ? (date('Y') . '-Q' . (int) ceil(((int) date('n')) / 3)) : date('Y-m'));
    }
    if ($periodType === 'yearly') {
        return substr($startDate, 0, 4);
    }
    if ($periodType === 'quarterly') {
        $ts = strtotime($startDate . ' 12:00:00');
        if ($ts === false) {
            return date('Y') . '-Q' . (int) ceil(((int) date('n')) / 3);
        }
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        $q = (int) ceil($m / 3);

        return sprintf('%d-Q%d', $y, $q);
    }

    return preg_match('/^\d{4}-\d{2}/', $startDate) ? substr($startDate, 0, 7) : date('Y-m');
}

/**
 * Resolve staff user id by alert email (for in-app notifications).
 */
function budget_find_user_id_by_email(string $email): ?int
{
    global $pdo;
    $email = trim(strtolower($email));
    if ($email === '') return null;
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(COALESCE(alert_email, email, ''))) = ? LIMIT 1");
        $st->execute([$email]);
        $id = (int) $st->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * User IDs who should see budget activity (finance dept + admin roles).
 *
 * @return int[]
 */
function budget_get_finance_user_ids(): array
{
    global $pdo;
    $ids = [];
    try {
        $st = $pdo->query("SELECT id FROM users WHERE is_active = 1 AND (
            LOWER(TRIM(COALESCE(department,''))) = 'finance'
            OR LOWER(TRIM(COALESCE(role,''))) IN ('admin','administrator','superadmin','super_admin')
        )");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return array_values(array_unique($ids));
}

/**
 * Create a system (bell) notification if the helper exists.
 */
function budget_notify_system(int $userId, string $title, string $message, ?string $link = null, string $type = 'info'): void
{
    if ($userId <= 0 || !function_exists('createSystemNotification')) {
        return;
    }
    $message = trim(strip_tags($message));
    $title = trim(strip_tags($title));
    if ($title === '') {
        $title = 'Budget';
    }
    try {
        createSystemNotification($userId, $title, $message, $link, $type);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Broadcast to finance team (excludes $exceptUserId when > 0).
 */
function budget_notify_finance_team(string $title, string $message, ?string $link, string $type, int $exceptUserId = 0): void
{
    foreach (budget_get_finance_user_ids() as $uid) {
        if ($exceptUserId > 0 && $uid === $exceptUserId) {
            continue;
        }
        budget_notify_system($uid, $title, $message, $link, $type);
    }
}

/**
 * Fallback when alert_email does not match a user: notify each admin via system_notifications (bell supports link).
 */
function budget_notify_admin_fallback(string $title, string $message, ?string $link): bool
{
    global $pdo;
    $message = trim(strip_tags($message));
    $title = trim(strip_tags($title));
    if ($title === '') {
        $title = 'Budget alert';
    }
    $ids = [];
    try {
        $st = $pdo->query("SELECT id FROM users WHERE is_active = 1 AND LOWER(TRIM(COALESCE(role,''))) IN ('admin','administrator','superadmin','super_admin')");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        if (!function_exists('createNotification')) {
            return false;
        }
        try {
            $msg = $message;
            if ($link !== null && $link !== '') {
                $msg .= ' ' . $link;
            }
            createNotification([
                'title' => $title,
                'message' => $msg,
                'type' => 'warning',
                'audience' => 'admin',
            ]);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    foreach ($ids as $uid) {
        budget_notify_system($uid, $title, $message, $link, 'warning');
    }

    return true;
}

/**
 * After creating a budget: notify other finance users.
 */
function budget_notify_new_budget_created(int $budgetId, string $name, string $currency, string $periodType, string $periodKey, int $actorUserId): void
{
    $link = budget_link_open($budgetId, $periodType, $periodKey);
    $msg = sprintf('Budget "%s" (%s) was created.', $name, $currency);
    budget_notify_finance_team('New budget', $msg, $link, 'success', $actorUserId);
}

/**
 * New or updated budget line (optional light ping to finance team).
 */
function budget_notify_line_changed(string $action, int $budgetId, string $budgetName, string $lineName, string $periodType, string $periodKey, int $actorUserId): void
{
    $link = budget_link_open($budgetId, $periodType, $periodKey);
    $verb = $action === 'add' ? 'added' : 'updated';
    $msg = sprintf('Line "%s" %s on budget "%s".', $lineName, $verb, $budgetName);
    budget_notify_finance_team('Budget line ' . $verb, $msg, $link, 'info', $actorUserId);
}
