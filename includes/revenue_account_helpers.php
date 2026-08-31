<?php
/**
 * Revenue GL account helpers (erp_accounts type = revenue, parent/sub hierarchy).
 */

if (!function_exists('revenue_ensure_account_schema')) {
    function revenue_ensure_account_schema(PDO $pdo): void
    {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!$cols) {
                return;
            }
            if (!in_array('revenue_account_id', $cols, true)) {
                $pdo->exec('ALTER TABLE revenue_entries ADD COLUMN revenue_account_id INT NULL DEFAULT NULL');
            }
            if (!in_array('revenue_category_id', $cols, true)) {
                $pdo->exec('ALTER TABLE revenue_entries ADD COLUMN revenue_category_id INT NULL DEFAULT NULL');
            }
            if (!in_array('currency', $cols, true)) {
                $pdo->exec("ALTER TABLE revenue_entries ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'TZS'");
            }
            if (!in_array('exchange_rate', $cols, true)) {
                $pdo->exec('ALTER TABLE revenue_entries ADD COLUMN exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000');
            }
        } catch (Throwable $e) {
            error_log('revenue_ensure_account_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('revenue_next_gl_code')) {
    function revenue_next_gl_code(PDO $pdo, ?int $parentId = null): string
    {
        if ($parentId) {
            $parentStmt = $pdo->prepare('SELECT code FROM erp_accounts WHERE id = ? LIMIT 1');
            $parentStmt->execute([$parentId]);
            $parentCode = trim((string) $parentStmt->fetchColumn());
            if ($parentCode !== '' && preg_match('/^(\d+)$/', $parentCode, $m)) {
                $prefix = $m[1];
                $stmt = $pdo->prepare(
                    "SELECT MAX(CAST(code AS UNSIGNED)) FROM erp_accounts
                     WHERE parent_id = ? AND code REGEXP '^[0-9]+$'"
                );
                $stmt->execute([$parentId]);
                $maxChild = (int) $stmt->fetchColumn();
                if ($maxChild > 0) {
                    return (string) ($maxChild + 1);
                }
                return $prefix . '01';
            }
        }

        $stmt = $pdo->query(
            "SELECT MAX(CAST(code AS UNSIGNED)) FROM erp_accounts
             WHERE type = 'revenue' AND code REGEXP '^[0-9]+$' AND code LIKE '4%'"
        );
        $maxCode = (int) $stmt->fetchColumn();
        if ($maxCode === 0) {
            return '4001';
        }
        return (string) ($maxCode + 1);
    }
}

if (!function_exists('revenue_fetch_main_accounts')) {
    function revenue_fetch_main_accounts(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                "SELECT id, code, name, description
                 FROM erp_accounts
                 WHERE type = 'revenue'
                   AND (parent_id IS NULL OR parent_id = 0)
                   AND (status IS NULL OR status = '' OR status = 'active')
                 ORDER BY name ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('revenue_fetch_main_accounts: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('revenue_fetch_sub_accounts')) {
    function revenue_fetch_sub_accounts(PDO $pdo, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, code, name, description
                 FROM erp_accounts
                 WHERE type = 'revenue'
                   AND parent_id = ?
                   AND (status IS NULL OR status = '' OR status = 'active')
                 ORDER BY name ASC"
            );
            $stmt->execute([$parentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('revenue_fetch_sub_accounts: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('revenue_fetch_account_options')) {
    /** Child posting accounts shown in the revenue entry form (Revenue only). */
    function revenue_fetch_account_options(PDO $pdo, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }
        revenue_ensure_child_account($pdo, $parentId, 'Revenue');
        $accounts = revenue_fetch_sub_accounts($pdo, $parentId);
        $filtered = array_values(array_filter($accounts, static function (array $acc): bool {
            return strcasecmp(trim((string) ($acc['name'] ?? '')), 'Revenue') === 0;
        }));
        return $filtered;
    }
}

if (!function_exists('revenue_validate_account_id')) {
    function revenue_validate_account_id(PDO $pdo, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, code, name, parent_id
                 FROM erp_accounts
                 WHERE id = ? AND type = 'revenue'
                   AND (status IS NULL OR status = '' OR status = 'active')
                 LIMIT 1"
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('revenue_create_gl_account')) {
    function revenue_create_gl_account(PDO $pdo, string $name, ?int $parentId = null, ?string $description = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Account name is required.');
        }

        if ($parentId) {
            $parent = revenue_validate_account_id($pdo, $parentId);
            if (!$parent || !empty($parent['parent_id'])) {
                throw new InvalidArgumentException('Invalid revenue main account.');
            }
        }

        $dupSql = $parentId
            ? "SELECT id FROM erp_accounts WHERE type = 'revenue' AND parent_id = ? AND LOWER(name) = LOWER(?) LIMIT 1"
            : "SELECT id FROM erp_accounts WHERE type = 'revenue' AND (parent_id IS NULL OR parent_id = 0) AND LOWER(name) = LOWER(?) LIMIT 1";
        $dup = $pdo->prepare($dupSql);
        $dup->execute($parentId ? [$parentId, $name] : [$name]);
        if ($dup->fetchColumn()) {
            throw new InvalidArgumentException('An account with this name already exists.');
        }

        $code = revenue_next_gl_code($pdo, $parentId);
        $stmt = $pdo->prepare(
            "INSERT INTO erp_accounts (code, name, type, description, parent_id, is_system, status)
             VALUES (?, ?, 'revenue', ?, ?, 0, 'active')"
        );
        $stmt->execute([$code, $name, $description ?: null, $parentId ?: null]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId,
        ];
    }
}

if (!function_exists('revenue_resolve_posted_account_id')) {
    function revenue_resolve_posted_account_id(PDO $pdo, int $requestedId): int
    {
        $acc = revenue_validate_account_id($pdo, $requestedId);
        if (!$acc) {
            return 0;
        }
        return (int) $acc['id'];
    }
}

if (!function_exists('revenue_resolve_posted_entry_accounts')) {
    /**
     * Validates category (parent) + account (child) pair for revenue entry posting.
     *
     * @return array{category_id:int,account_id:int}|null
     */
    function revenue_resolve_posted_entry_accounts(PDO $pdo, int $categoryId, int $accountId): ?array
    {
        if ($categoryId <= 0 || $accountId <= 0) {
            return null;
        }

        $category = revenue_validate_account_id($pdo, $categoryId);
        if (!$category || !empty($category['parent_id'])) {
            return null;
        }

        $account = revenue_validate_account_id($pdo, $accountId);
        if (!$account || empty($account['parent_id'])) {
            return null;
        }

        if ((int) $account['parent_id'] !== (int) $category['id']) {
            return null;
        }

        return [
            'category_id' => (int) $category['id'],
            'account_id' => (int) $account['id'],
        ];
    }
}

if (!function_exists('revenue_fetch_categories')) {
    function revenue_fetch_categories(PDO $pdo): array
    {
        revenue_ensure_default_accounts($pdo);
        $accounts = revenue_fetch_main_accounts($pdo);
        $preferred = [
            'sales revenue' => 1,
            'service revenue' => 2,
            'subscription revenue' => 3,
            'rental revenue' => 4,
            'other revenue' => 5,
        ];
        usort($accounts, static function (array $a, array $b) use ($preferred): int {
            $oa = $preferred[strtolower(trim((string) ($a['name'] ?? '')))] ?? 50;
            $ob = $preferred[strtolower(trim((string) ($b['name'] ?? '')))] ?? 50;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        return $accounts;
    }
}

if (!function_exists('revenue_find_or_create_category')) {
    function revenue_find_or_create_category(PDO $pdo, string $name): int
    {
        $name = trim($name);
        $check = $pdo->prepare(
            "SELECT id FROM erp_accounts
             WHERE type = 'revenue'
               AND (parent_id IS NULL OR parent_id = 0)
               AND LOWER(name) = LOWER(?)
             LIMIT 1"
        );
        $check->execute([$name]);
        $id = (int) $check->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $created = revenue_create_gl_account($pdo, $name, null, null);
        return (int) $created['id'];
    }
}

if (!function_exists('revenue_ensure_child_account')) {
    function revenue_ensure_child_account(PDO $pdo, int $parentId, string $name): void
    {
        $name = trim($name);
        if ($parentId <= 0 || $name === '') {
            return;
        }
        $check = $pdo->prepare(
            "SELECT id FROM erp_accounts
             WHERE type = 'revenue' AND parent_id = ? AND LOWER(name) = LOWER(?)
             LIMIT 1"
        );
        $check->execute([$parentId, $name]);
        if ($check->fetchColumn()) {
            return;
        }
        revenue_create_gl_account($pdo, $name, $parentId, null);
    }
}

if (!function_exists('revenue_fetch_picker_accounts')) {
    function revenue_fetch_picker_accounts(PDO $pdo): array
    {
        return revenue_fetch_categories($pdo);
    }
}

if (!function_exists('revenue_ensure_default_accounts')) {
    function revenue_ensure_default_accounts(PDO $pdo): void
    {
        $structure = [
            'Sales Revenue' => ['Revenue'],
            'Service Revenue' => ['Revenue'],
            'Subscription Revenue' => ['Revenue'],
            'Rental Revenue' => ['Revenue'],
            'Other Revenue' => ['Revenue'],
        ];

        foreach ($structure as $categoryName => $children) {
            try {
                $categoryId = revenue_find_or_create_category($pdo, $categoryName);
                foreach ($children as $childName) {
                    revenue_ensure_child_account($pdo, $categoryId, $childName);
                }
            } catch (Throwable $e) {
                error_log('revenue_ensure_default_accounts: ' . $e->getMessage());
            }
        }
    }
}
