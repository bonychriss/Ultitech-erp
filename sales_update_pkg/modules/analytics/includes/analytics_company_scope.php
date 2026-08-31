<?php
/**
 * Multi-company data isolation for analytics queries.
 */

if (!function_exists('analytics_ensure_sales_scope_helpers')) {
    function analytics_ensure_sales_scope_helpers(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $path = __DIR__ . '/../../sales/functions.php';
        if (is_file($path)) {
            require_once $path;
        }
        $done = true;
    }
}

if (!function_exists('analytics_current_company_id')) {
    function analytics_current_company_id(): ?int
    {
        if (function_exists('currentCompanyId')) {
            $cid = currentCompanyId();
            return $cid ? (int) $cid : null;
        }

        return !empty($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null;
    }
}

if (!function_exists('analytics_append_company_scope')) {
    /**
     * @param array<int, mixed> $params
     */
    function analytics_append_company_scope(string &$sql, array &$params, string $table, string $alias = '', ?PDO $pdo = null): void
    {
        analytics_ensure_sales_scope_helpers();
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, $table, $alias);
        }
    }
}

if (!function_exists('analytics_scoped_tables')) {
    /**
     * @param array<int, mixed> $params
     * @param array<string, string> $tables alias => table name
     */
    function analytics_scoped_tables(string &$sql, array &$params, array $tables, ?PDO $pdo = null): void
    {
        foreach ($tables as $alias => $table) {
            analytics_append_company_scope($sql, $params, $table, $alias, $pdo);
        }
    }
}

if (!function_exists('analytics_company_display_context')) {
    /**
     * @return array{company_id: ?int, company_name: string, multicompany_store: bool, scoping_active: bool}
     */
    function analytics_company_display_context(PDO $pdo): array
    {
        $cid = analytics_current_company_id();
        $name = '';

        if (function_exists('getCurrentCompany')) {
            $co = getCurrentCompany();
            if (!empty($co['company_name'])) {
                $name = (string) $co['company_name'];
            }
        }
        if ($name === '' && !empty($_SESSION['company_name'])) {
            $name = (string) $_SESSION['company_name'];
        }
        if ($name === '' && $cid !== null && $cid > 0) {
            $name = 'Company #' . $cid;
        }

        $multicompany = tableExists('invoices', $pdo) && columnExists('invoices', 'company_id', $pdo);
        $scopingActive = false;
        if ($multicompany && $cid !== null && $cid > 0) {
            analytics_ensure_sales_scope_helpers();
            if (function_exists('salesCompanyScopeSql')) {
                $scope = salesCompanyScopeSql('invoices');
                $scopingActive = $scope[0] !== '';
            }
        }

        return [
            'company_id' => $cid,
            'company_name' => $name,
            'multicompany_store' => $multicompany,
            'scoping_active' => $scopingActive,
        ];
    }
}

if (!function_exists('analytics_should_verify_company_isolation')) {
    function analytics_should_verify_company_isolation(PDO $pdo): bool
    {
        if (!tableExists('invoices', $pdo) || !columnExists('invoices', 'company_id', $pdo)) {
            return false;
        }
        if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
            analytics_ensure_sales_scope_helpers();
            if (!function_exists('sales_uses_control_database') || !sales_uses_control_database()) {
                return false;
            }
        }

        return (analytics_current_company_id() ?? 0) > 0;
    }
}

if (!function_exists('analytics_url_company_mismatch')) {
  /**
   * When the request path includes a company slug, ensure session company matches it.
   *
   * @return array{id: int, name: string}|null Expected company when mismatched
   */
    function analytics_url_company_mismatch(PDO $pdo): ?array
    {
        if (!function_exists('getRequestedCompanySlug') || !function_exists('findCompanyBySlug')) {
            return null;
        }

        $slug = trim((string) getRequestedCompanySlug());
        if ($slug === '') {
            return null;
        }

        $expected = findCompanyBySlug($slug);
        if (!$expected || empty($expected['id'])) {
            return null;
        }

        $expectedId = (int) $expected['id'];
        $sessionId = (int) (analytics_current_company_id() ?? 0);
        if ($sessionId <= 0 || $sessionId === $expectedId) {
            return null;
        }

        return [
            'id' => $expectedId,
            'name' => (string) ($expected['company_name'] ?? $slug),
        ];
    }
}

if (!function_exists('analytics_user_in_company')) {
    function analytics_user_in_company(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }
        if (!tableExists('users', $pdo)) {
            return false;
        }

        $cid = analytics_current_company_id();
        if ($cid === null || $cid <= 0) {
            return true;
        }
        if (!columnExists('users', 'company_id', $pdo)) {
            return true;
        }

        try {
            $st = $pdo->prepare('SELECT company_id FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $userCompany = $st->fetchColumn();
            if ($userCompany === false) {
                return false;
            }
            $userCompany = (int) $userCompany;

            return $userCompany === $cid || $userCompany === 0;
        } catch (Throwable $e) {
            error_log('analytics_user_in_company: ' . $e->getMessage());

            return false;
        }
    }
}
