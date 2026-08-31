<?php
/**
 * Petty Cash module � custodian float logic (independent of Balances / Expenses).
 */

if (!function_exists('pettyCashCanManage')) {
    function pettyCashCanManage(): bool
    {
        return function_exists('isFinanceOrAdmin') && isFinanceOrAdmin();
    }
}

if (!function_exists('ensurePettyCashSchema')) {
    function ensurePettyCashSchema(): void
    {
        global $pdo;
        static $ensured = false;
        if ($ensured) {
            return;
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_balance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                custodian_id INT NOT NULL,
                opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                current_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pc_custodian (custodian_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('ensurePettyCashSchema balance: ' . $e->getMessage());
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_vouchers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_number VARCHAR(30) DEFAULT NULL,
                date DATE NOT NULL,
                custodian_id INT NOT NULL,
                category VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                receipt_path VARCHAR(255) DEFAULT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                created_by INT NOT NULL,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                rejection_reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pc_voucher_custodian (custodian_id),
                INDEX idx_pc_voucher_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('ensurePettyCashSchema vouchers: ' . $e->getMessage());
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_replenishments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                replenishment_number VARCHAR(50) NOT NULL,
                custodian_id INT NOT NULL,
                petty_cash_account_id INT NULL,
                source_account_id INT NULL,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT NULL,
                previous_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                new_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                transfer_out_transaction_id INT NULL,
                transfer_in_transaction_id INT NULL,
                journal_entry_id INT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                created_by INT NULL,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                rejection_reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pc_rep_custodian (custodian_id),
                INDEX idx_pc_rep_status (status),
                INDEX idx_pc_rep_petty_acc (petty_cash_account_id),
                INDEX idx_pc_rep_source_acc (source_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('ensurePettyCashSchema replenishments: ' . $e->getMessage());
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(30) DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pc_category_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('ensurePettyCashSchema categories: ' . $e->getMessage());
        }

        pettyCashMigrateSchema($pdo);
        pettyCashSeedDefaultCategories($pdo);
        $ensured = true;
    }
}

if (!function_exists('pettyCashMigrateSchema')) {
    function pettyCashMigrateSchema(PDO $pdo): void
    {
        pettyCashMigrateTableColumns($pdo, 'petty_cash_balance', [
            'opening_balance' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active'",
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ]);

        pettyCashMigrateTableColumns($pdo, 'petty_cash_vouchers', [
            'approved_by' => 'INT NULL',
            'approved_at' => 'DATETIME NULL',
            'rejection_reason' => 'TEXT NULL',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ]);

        pettyCashMigrateTableColumns($pdo, 'petty_cash_replenishments', [
            'petty_cash_account_id' => 'INT NULL',
            'source_account_id' => 'INT NULL',
            'description' => 'TEXT NULL',
            'transfer_out_transaction_id' => 'INT NULL',
            'transfer_in_transaction_id' => 'INT NULL',
            'journal_entry_id' => 'INT NULL',
            'rejection_reason' => 'TEXT NULL',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ]);

        pettyCashEnsureEnumValue($pdo, 'petty_cash_vouchers', 'status', ['pending', 'approved', 'rejected', 'cancelled']);
        pettyCashEnsureEnumValue($pdo, 'petty_cash_replenishments', 'status', ['pending', 'approved', 'rejected', 'cancelled']);

        try {
            $pdo->exec("DELETE t1 FROM petty_cash_balance t1
                        INNER JOIN petty_cash_balance t2
                        ON t1.custodian_id = t2.custodian_id AND t1.id < t2.id");
        } catch (PDOException $e) {
            // ignore
        }

        pettyCashRestoreReplenishmentsFromBackup($pdo);
    }
}

if (!function_exists('pettyCashRestoreReplenishmentsFromBackup')) {
    /**
     * Recover top-up rows left in backup_petty_cash_replenishments after a schema refresh.
     */
    function pettyCashRestoreReplenishmentsFromBackup(PDO $pdo): void
    {
        static $restored = false;
        if ($restored) {
            return;
        }
        $restored = true;

        try {
            $backupExists = (bool) $pdo->query("SHOW TABLES LIKE 'backup_petty_cash_replenishments'")->fetchColumn();
            if (!$backupExists) {
                return;
            }

            $mainCount = (int) $pdo->query('SELECT COUNT(*) FROM petty_cash_replenishments')->fetchColumn();
            $backupCount = (int) $pdo->query('SELECT COUNT(*) FROM backup_petty_cash_replenishments')->fetchColumn();
            if ($backupCount <= 0 || $mainCount >= $backupCount) {
                return;
            }

            $backupCols = $pdo->query('SHOW COLUMNS FROM backup_petty_cash_replenishments')->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasNotes = in_array('notes', $backupCols, true);
            $descriptionExpr = $hasNotes
                ? "COALESCE(NULLIF(TRIM(b.description), ''), NULLIF(TRIM(b.notes), ''))"
                : 'b.description';

            $pdo->exec("INSERT INTO petty_cash_replenishments (
                id, replenishment_number, custodian_id, petty_cash_account_id, source_account_id,
                amount, description, previous_balance, new_balance,
                transfer_out_transaction_id, transfer_in_transaction_id, journal_entry_id,
                status, created_by, approved_by, approved_at, rejection_reason, created_at, updated_at
            )
            SELECT
                b.id, b.replenishment_number, b.custodian_id, b.petty_cash_account_id, b.source_account_id,
                b.amount, {$descriptionExpr}, b.previous_balance, b.new_balance,
                b.transfer_out_transaction_id, b.transfer_in_transaction_id, b.journal_entry_id,
                b.status, b.created_by, b.approved_by, b.approved_at, b.rejection_reason, b.created_at, b.updated_at
            FROM backup_petty_cash_replenishments b
            LEFT JOIN petty_cash_replenishments r ON r.id = b.id
            WHERE r.id IS NULL");

            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM petty_cash_replenishments')->fetchColumn();
            if ($maxId > 0) {
                $pdo->exec('ALTER TABLE petty_cash_replenishments AUTO_INCREMENT = ' . ($maxId + 1));
            }
        } catch (PDOException $e) {
            error_log('pettyCashRestoreReplenishmentsFromBackup: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pettyCashMigrateTableColumns')) {
    function pettyCashMigrateTableColumns(PDO $pdo, string $table, array $columns): void
    {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            return;
        }

        foreach ($columns as $col => $definition) {
            if (!in_array($col, $existing, true)) {
                try {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
                } catch (PDOException $e) {
                    error_log("pettyCashMigrateTableColumns {$table}.{$col}: " . $e->getMessage());
                }
            }
        }
    }
}

if (!function_exists('pettyCashEnsureEnumValue')) {
    function pettyCashEnsureEnumValue(PDO $pdo, string $table, string $column, array $values): void
    {
        try {
            $row = $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetch(PDO::FETCH_ASSOC);
            if (!$row || stripos((string) ($row['Type'] ?? ''), 'enum') === false) {
                return;
            }
            $type = (string) $row['Type'];
            $missing = false;
            foreach ($values as $val) {
                if (stripos($type, "'" . $val . "'") === false) {
                    $missing = true;
                    break;
                }
            }
            if (!$missing) {
                return;
            }
            $enum = "ENUM('" . implode("','", $values) . "') NOT NULL DEFAULT 'pending'";
            $pdo->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} {$enum}");
        } catch (PDOException $e) {
            error_log("pettyCashEnsureEnumValue {$table}.{$column}: " . $e->getMessage());
        }
    }
}

if (!function_exists('ensurePettyCashAccount')) {
    function ensurePettyCashAccount(int $custodian_id): void
    {
        global $pdo;
        ensurePettyCashSchema();
        $stmt = $pdo->prepare(
            "INSERT INTO petty_cash_balance (custodian_id, opening_balance, current_balance, status)
             VALUES (?, 0, 0, 'active')
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->execute([$custodian_id]);
    }
}

if (!function_exists('getPettyCashBalance')) {
    function getPettyCashBalance($custodian_id): float
    {
        global $pdo;
        ensurePettyCashSchema();
        ensurePettyCashAccount((int) $custodian_id);
        $stmt = $pdo->prepare(
            "SELECT current_balance FROM petty_cash_balance WHERE custodian_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([(int) $custodian_id]);
        $balance = $stmt->fetchColumn();

        return $balance !== false ? (float) $balance : 0.0;
    }
}

if (!function_exists('updatePettyCashBalance')) {
    function updatePettyCashBalance($custodian_id, $amount, $operation = 'subtract'): float
    {
        global $pdo;
        ensurePettyCashSchema();
        ensurePettyCashAccount((int) $custodian_id);

        $current = getPettyCashBalance($custodian_id);
        $amount = (float) $amount;

        if ($operation === 'add') {
            $newBalance = $current + $amount;
        } else {
            $newBalance = $current - $amount;
        }

        $stmt = $pdo->prepare(
            "UPDATE petty_cash_balance SET current_balance = ?, updated_at = NOW() WHERE custodian_id = ? AND status = 'active'"
        );
        $stmt->execute([$newBalance, (int) $custodian_id]);

        return $newBalance;
    }
}

if (!function_exists('generatePettyCashVoucherNumber')) {
    function generatePettyCashVoucherNumber(): string
    {
        global $pdo;
        ensurePettyCashSchema();
        $prefix = 'PC-' . date('Y-m') . '-';
        $stmt = $pdo->prepare(
            "SELECT voucher_number FROM petty_cash_vouchers WHERE voucher_number LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $num = ($last && preg_match('/(\d+)$/', (string) $last, $m)) ? ((int) $m[1] + 1) : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('generateReplenishmentNumber')) {
    function generateReplenishmentNumber(): string
    {
        global $pdo;
        ensurePettyCashSchema();
        $prefix = 'REP-' . date('Ym') . '-';
        $stmt = $pdo->prepare(
            "SELECT replenishment_number FROM petty_cash_replenishments WHERE replenishment_number LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $num = ($last && preg_match('/(\d+)$/', (string) $last, $m)) ? ((int) $m[1] + 1) : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pettyCashEscapeLikeTerm')) {
    function pettyCashEscapeLikeTerm(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return '%' . addcslashes($raw, '\\%_') . '%';
    }
}

if (!function_exists('pettyCashApplyVoucherSearchFilter')) {
    /**
     * @param array<int, string> $where
     * @param array<int, mixed> $params
     */
    function pettyCashApplyVoucherSearchFilter(array &$where, array &$params, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $like = pettyCashEscapeLikeTerm($search);
        $clauses = [
            'v.voucher_number LIKE ?',
            'v.category LIKE ?',
            'v.description LIKE ?',
            'c.full_name LIKE ?',
            'cr.full_name LIKE ?',
            'CAST(v.amount AS CHAR) LIKE ?',
        ];
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        $amountDigits = preg_replace('/[^0-9.]/', '', $search);
        if ($amountDigits !== '' && is_numeric($amountDigits)) {
            $clauses[] = 'v.amount = ?';
            $params[] = (float) $amountDigits;
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
}

if (!function_exists('getAllPettyCashVouchers')) {
    function getAllPettyCashVouchers($filters = []): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'v.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['custodian_id'])) {
            $where[] = 'v.custodian_id = ?';
            $params[] = (int) $filters['custodian_id'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'v.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'v.date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'v.date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['exclude_cancelled'])) {
            $where[] = "v.status != 'cancelled'";
        }
        if (!empty($filters['search'])) {
            pettyCashApplyVoucherSearchFilter($where, $params, (string) $filters['search']);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = '';
        if (!empty($filters['limit'])) {
            $limit = ' LIMIT ' . (int) $filters['limit'];
        }

        $custodianPhotoSelect = 'NULL AS custodian_photo';
        if (function_exists('columnExists') && columnExists('users', 'profile_photo', $pdo)) {
            $custodianPhotoSelect = 'c.profile_photo AS custodian_photo';
        }

        $sql = "SELECT v.*,
                c.full_name AS custodian_name,
                {$custodianPhotoSelect},
                a.full_name AS approved_by_name,
                cr.full_name AS created_by_name
            FROM petty_cash_vouchers v
            LEFT JOIN users c ON v.custodian_id = c.id
            LEFT JOIN users a ON v.approved_by = a.id
            LEFT JOIN users cr ON v.created_by = cr.id
            {$whereClause}
            ORDER BY v.created_at DESC, v.id DESC{$limit}";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getAllPettyCashVouchers: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPettyCashVoucher')) {
    function getPettyCashVoucher($id)
    {
        global $pdo;
        ensurePettyCashSchema();
        $stmt = $pdo->prepare(
            "SELECT v.*,
                c.full_name AS custodian_name,
                c.signature_path AS custodian_signature_path,
                a.full_name AS approved_by_name,
                a.signature_path AS approved_by_signature_path,
                cr.full_name AS created_by_name,
                cr.signature_path AS created_by_signature_path
             FROM petty_cash_vouchers v
             LEFT JOIN users c ON v.custodian_id = c.id
             LEFT JOIN users a ON v.approved_by = a.id
             LEFT JOIN users cr ON v.created_by = cr.id
             WHERE v.id = ?"
        );
        $stmt->execute([(int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('pettyCashApplyReplenishmentSearchFilter')) {
    /**
     * @param array<int, string> $where
     * @param array<int, mixed> $params
     */
    function pettyCashApplyReplenishmentSearchFilter(array &$where, array &$params, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $like = pettyCashEscapeLikeTerm($search);
        $clauses = [
            'r.replenishment_number LIKE ?',
            'u.full_name LIKE ?',
            'CAST(r.amount AS CHAR) LIKE ?',
            'r.description LIKE ?',
        ];
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        if (pettyCashHasFinancialAccounts()) {
            $clauses[] = 'pc_acc.name LIKE ?';
            $clauses[] = 'src_acc.name LIKE ?';
            $params[] = $like;
            $params[] = $like;
        }

        $amountDigits = preg_replace('/[^0-9.]/', '', $search);
        if ($amountDigits !== '' && is_numeric($amountDigits)) {
            $clauses[] = 'r.amount = ?';
            $params[] = (float) $amountDigits;
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
}

if (!function_exists('getAllPettyCashReplenishments')) {
    function getAllPettyCashReplenishments($filters = []): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['custodian_id'])) {
            $where[] = 'r.custodian_id = ?';
            $params[] = (int) $filters['custodian_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(COALESCE(r.approved_at, r.created_at)) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(COALESCE(r.approved_at, r.created_at)) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['exclude_cancelled'])) {
            $where[] = "r.status != 'cancelled'";
        }
        if (!empty($filters['search'])) {
            pettyCashApplyReplenishmentSearchFilter($where, $params, (string) $filters['search']);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = !empty($filters['limit']) ? ' LIMIT ' . (int) $filters['limit'] : '';

        $custodianPhotoSelect = 'NULL AS custodian_photo';
        if (function_exists('columnExists') && columnExists('users', 'profile_photo', $pdo)) {
            $custodianPhotoSelect = 'u.profile_photo AS custodian_photo';
        }

        $accountJoins = '';
        $accountSelect = '';
        if (pettyCashHasFinancialAccounts()) {
            $accountSelect = ',
                pc_acc.name AS petty_cash_account_name,
                src_acc.name AS source_account_name';
            $accountJoins = '
            LEFT JOIN financial_accounts pc_acc ON pc_acc.id = r.petty_cash_account_id
            LEFT JOIN financial_accounts src_acc ON src_acc.id = r.source_account_id';
        }

        $sql = "SELECT r.*,
                u.full_name AS custodian_name,
                {$custodianPhotoSelect},
                a.full_name AS approved_by_name,
                cb.full_name AS created_by_name
                {$accountSelect}
            FROM petty_cash_replenishments r
            LEFT JOIN users u ON r.custodian_id = u.id
            LEFT JOIN users a ON r.approved_by = a.id
            LEFT JOIN users cb ON r.created_by = cb.id
            {$accountJoins}
            {$whereClause}
            ORDER BY r.created_at DESC, r.id DESC{$limit}";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getAllPettyCashReplenishments: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPettyCashReplenishment')) {
    function getPettyCashReplenishment($id)
    {
        global $pdo;
        ensurePettyCashSchema();
        $accountJoins = '';
        $accountSelect = '';
        if (pettyCashHasFinancialAccounts()) {
            $accountSelect = ',
                pc_acc.name AS petty_cash_account_name,
                src_acc.name AS source_account_name';
            $accountJoins = '
             LEFT JOIN financial_accounts pc_acc ON pc_acc.id = r.petty_cash_account_id
             LEFT JOIN financial_accounts src_acc ON src_acc.id = r.source_account_id';
        }

        $stmt = $pdo->prepare(
            "SELECT r.*,
                u.full_name AS custodian_name,
                a.full_name AS approved_by_name,
                cb.full_name AS created_by_name
                {$accountSelect}
             FROM petty_cash_replenishments r
             LEFT JOIN users u ON r.custodian_id = u.id
             LEFT JOIN users a ON r.approved_by = a.id
             LEFT JOIN users cb ON r.created_by = cb.id
             {$accountJoins}
             WHERE r.id = ?"
        );
        $stmt->execute([(int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getAllPettyCashBalances')) {
    function getAllPettyCashBalances(bool $includeZero = false): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $sql = "SELECT b.*, u.full_name AS custodian_name
                FROM petty_cash_balance b
                LEFT JOIN users u ON b.custodian_id = u.id
                WHERE b.status = 'active'";
        if (!$includeZero) {
            $sql .= ' AND b.current_balance != 0';
        }
        $sql .= ' ORDER BY b.current_balance DESC, u.full_name ASC';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getPettyCashDashboardStats')) {
    function getPettyCashDashboardStats(?int $custodian_id = null): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $voucherConditions = [];
        $repConditions = [];
        $params = [];

        if ($custodian_id !== null) {
            $voucherConditions[] = 'custodian_id = ?';
            $repConditions[] = 'custodian_id = ?';
            $params = [$custodian_id];
        }

        $voucherWhere = $voucherConditions ? 'WHERE ' . implode(' AND ', $voucherConditions) : '';
        $repWhere = $repConditions ? 'WHERE ' . implode(' AND ', $repConditions) : '';

        $balanceSql = "SELECT COALESCE(SUM(current_balance), 0) FROM petty_cash_balance WHERE status = 'active'";
        if ($custodian_id !== null) {
            $balanceSql .= ' AND custodian_id = ?';
        }
        $stmt = $pdo->prepare($balanceSql);
        $stmt->execute($custodian_id !== null ? [$custodian_id] : []);
        $totalBalance = (float) $stmt->fetchColumn();

        $spentWhere = $voucherWhere
            ? $voucherWhere . " AND status = 'approved'"
            : "WHERE status = 'approved'";
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM petty_cash_vouchers {$spentWhere}");
        $stmt->execute($params);
        $totalSpent = (float) $stmt->fetchColumn();

        $pendingVoucherWhere = $voucherWhere
            ? $voucherWhere . " AND status = 'pending'"
            : "WHERE status = 'pending'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_vouchers {$pendingVoucherWhere}");
        $stmt->execute($params);
        $pendingVouchers = (int) $stmt->fetchColumn();

        $approvedVoucherWhere = $voucherWhere
            ? $voucherWhere . " AND status = 'approved'"
            : "WHERE status = 'approved'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_vouchers {$approvedVoucherWhere}");
        $stmt->execute($params);
        $approvedVouchers = (int) $stmt->fetchColumn();

        $pendingRepWhere = $repWhere
            ? $repWhere . " AND status = 'pending'"
            : "WHERE status = 'pending'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_replenishments {$pendingRepWhere}");
        $stmt->execute($params);
        $pendingReplenishments = (int) $stmt->fetchColumn();

        return [
            'total_balance' => $totalBalance,
            'total_spent' => $totalSpent,
            'pending_vouchers' => $pendingVouchers,
            'approved_vouchers' => $approvedVouchers,
            'pending_replenishments' => $pendingReplenishments,
        ];
    }
}

if (!function_exists('getPettyCashFlowTrend')) {
    /**
     * Monthly approved top-ups (inflow) and vouchers (outflow) for dashboard chart.
     *
     * @return array{labels: string[], inflow: float[], outflow: float[]}
     */
    function getPettyCashFlowTrend(int $months = 6, ?int $custodian_id = null): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $months = max(2, min(12, $months));
        $labels = [];
        $inflow = [];
        $outflow = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-{$i} months");
            $start = date('Y-m-01', $ts);
            $end = date('Y-m-t', $ts);
            $labels[] = date("M 'y", $ts);

            $custClause = $custodian_id !== null ? ' AND custodian_id = ?' : '';
            $params = $custodian_id !== null ? [$start, $end, $custodian_id] : [$start, $end];

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM petty_cash_vouchers
                 WHERE status = 'approved' AND date BETWEEN ? AND ?{$custClause}"
            );
            $stmt->execute($params);
            $outflow[] = (float) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM petty_cash_replenishments
                 WHERE status = 'approved'
                 AND DATE(COALESCE(approved_at, created_at)) BETWEEN ? AND ?{$custClause}"
            );
            $stmt->execute($params);
            $inflow[] = (float) $stmt->fetchColumn();
        }

        return ['labels' => $labels, 'inflow' => $inflow, 'outflow' => $outflow];
    }
}

if (!function_exists('createPettyCashVoucher')) {
    function createPettyCashVoucher($data)
    {
        global $pdo;
        ensurePettyCashSchema();
        ensurePettyCashAccount((int) $data['custodian_id']);

        $voucherNo = generatePettyCashVoucherNumber();
        $stmt = $pdo->prepare(
            "INSERT INTO petty_cash_vouchers
            (voucher_number, date, custodian_id, category, description, amount, receipt_path, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );

        try {
            $stmt->execute([
                $voucherNo,
                $data['date'],
                (int) $data['custodian_id'],
                $data['category'],
                $data['description'],
                (float) $data['amount'],
                $data['receipt_path'] ?? null,
                (int) $data['created_by'],
            ]);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('createPettyCashVoucher: ' . $e->getMessage());

            return false;
        }
    }
}

if (!function_exists('approvePettyCashVoucher')) {
    function approvePettyCashVoucher($voucher_id, $approved_by)
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM petty_cash_vouchers WHERE id = ? FOR UPDATE");
            $stmt->execute([(int) $voucher_id]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                throw new RuntimeException('Voucher not found.');
            }
            if (strtolower((string) $voucher['status']) !== 'pending') {
                throw new RuntimeException('Only pending vouchers can be approved.');
            }

            ensurePettyCashAccount((int) $voucher['custodian_id']);

            $balStmt = $pdo->prepare(
                "SELECT current_balance FROM petty_cash_balance WHERE custodian_id = ? AND status = 'active' FOR UPDATE"
            );
            $balStmt->execute([(int) $voucher['custodian_id']]);
            $currentBalance = (float) $balStmt->fetchColumn();
            $amount = (float) $voucher['amount'];

            if ($currentBalance < $amount) {
                throw new RuntimeException('Insufficient petty cash balance for this custodian.');
            }

            $newBalance = $currentBalance - $amount;
            $updBal = $pdo->prepare(
                "UPDATE petty_cash_balance SET current_balance = ?, updated_at = NOW() WHERE custodian_id = ? AND status = 'active'"
            );
            $updBal->execute([$newBalance, (int) $voucher['custodian_id']]);

            $updV = $pdo->prepare(
                "UPDATE petty_cash_vouchers SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?"
            );
            $updV->execute([(int) $approved_by, (int) $voucher_id]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('approvePettyCashVoucher: ' . $e->getMessage());

            return $e->getMessage();
        }
    }
}

if (!function_exists('rejectPettyCashVoucher')) {
    function rejectPettyCashVoucher($voucher_id, $approved_by, $reason = '')
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status FROM petty_cash_vouchers WHERE id = ? FOR UPDATE");
            $stmt->execute([(int) $voucher_id]);
            $status = $stmt->fetchColumn();

            if ($status === false) {
                throw new RuntimeException('Voucher not found.');
            }
            if (strtolower((string) $status) !== 'pending') {
                throw new RuntimeException('Only pending vouchers can be rejected.');
            }

            $upd = $pdo->prepare(
                "UPDATE petty_cash_vouchers SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?, updated_at = NOW() WHERE id = ?"
            );
            $upd->execute([(int) $approved_by, trim((string) $reason), (int) $voucher_id]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $e->getMessage();
        }
    }
}

if (!function_exists('cancelPettyCashVoucher')) {
    function cancelPettyCashVoucher($voucher_id)
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM petty_cash_vouchers WHERE id = ? FOR UPDATE");
            $stmt->execute([(int) $voucher_id]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                throw new RuntimeException('Voucher not found.');
            }

            $status = strtolower((string) $voucher['status']);
            if (!in_array($status, ['pending', 'approved'], true)) {
                throw new RuntimeException('This voucher cannot be cancelled.');
            }

            if ($status === 'approved') {
                ensurePettyCashAccount((int) $voucher['custodian_id']);
                $balStmt = $pdo->prepare(
                    "SELECT current_balance FROM petty_cash_balance WHERE custodian_id = ? AND status = 'active' FOR UPDATE"
                );
                $balStmt->execute([(int) $voucher['custodian_id']]);
                $currentBalance = (float) $balStmt->fetchColumn();
                $newBalance = $currentBalance + (float) $voucher['amount'];
                $updBal = $pdo->prepare(
                    "UPDATE petty_cash_balance SET current_balance = ?, updated_at = NOW() WHERE custodian_id = ? AND status = 'active'"
                );
                $updBal->execute([$newBalance, (int) $voucher['custodian_id']]);
            }

            $upd = $pdo->prepare(
                "UPDATE petty_cash_vouchers SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
            );
            $upd->execute([(int) $voucher_id]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $e->getMessage();
        }
    }
}

if (!function_exists('pettyCashHasFinancialAccounts')) {
    function pettyCashHasFinancialAccounts(): bool
    {
        global $pdo;

        return function_exists('tableExists') && tableExists('financial_accounts', $pdo);
    }
}

if (!function_exists('pettyCashLoadBalancesModule')) {
    function pettyCashLoadBalancesModule(): bool
    {
        static $loaded = false;
        if ($loaded) {
            return function_exists('balancesRecordTransaction');
        }

        $path = dirname(__DIR__, 3) . '/modules/balances/functions.php';
        if (is_file($path)) {
            require_once $path;
            if (function_exists('ensureBalancesSchema')) {
                ensureBalancesSchema();
            }
        }

        $loaded = true;

        return function_exists('balancesRecordTransaction');
    }
}

if (!function_exists('pettyCashListFinancialAccounts')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function pettyCashListFinancialAccounts(string $role = 'all'): array
    {
        global $pdo;

        if (!pettyCashHasFinancialAccounts()) {
            return [];
        }

        pettyCashLoadBalancesModule();

        if (function_exists('balancesFetchAccountsWithLiveBalance')) {
            $accounts = balancesFetchAccountsWithLiveBalance($pdo, true);
        } else {
            $accounts = $pdo->query(
                "SELECT id, name, type, currency, current_balance, status
                 FROM financial_accounts WHERE status = 'active' ORDER BY name ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($role === 'petty') {
            return array_values(array_filter($accounts, static function (array $row): bool {
                $type = strtolower((string) ($row['type'] ?? ''));
                $name = strtolower((string) ($row['name'] ?? ''));

                return $type === 'cash' || str_contains($name, 'petty');
            }));
        }

        return $accounts;
    }
}

if (!function_exists('pettyCashGetFinancialAccount')) {
    function pettyCashGetFinancialAccount(int $id, bool $forUpdate = false): ?array
    {
        global $pdo;

        if ($id <= 0 || !pettyCashHasFinancialAccounts()) {
            return null;
        }

        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare(
            "SELECT id, name, type, currency, current_balance, opening_balance, status
             FROM financial_accounts WHERE id = ? AND status = 'active'{$lock}"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('pettyCashResolveGlAccountId')) {
    function pettyCashResolveGlAccountId(int $financialAccountId): ?int
    {
        global $pdo;

        if ($financialAccountId <= 0 || !function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return null;
        }

        $fa = pettyCashGetFinancialAccount($financialAccountId);
        if (!$fa) {
            return null;
        }

        if (function_exists('columnExists') && columnExists('financial_accounts', 'gl_account_id', $pdo)) {
            $stmt = $pdo->prepare('SELECT gl_account_id FROM financial_accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$financialAccountId]);
            $glId = (int) $stmt->fetchColumn();
            if ($glId > 0) {
                return $glId;
            }
        }

        $name = trim((string) ($fa['name'] ?? ''));
        if ($name !== '') {
            $stmt = $pdo->prepare(
                "SELECT id FROM erp_accounts
                 WHERE status = 'active' AND (LOWER(name) = LOWER(?) OR name LIKE ?)
                 ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute([$name, '%' . $name . '%']);
            $match = (int) $stmt->fetchColumn();
            if ($match > 0) {
                return $match;
            }
        }

        $type = strtolower((string) ($fa['type'] ?? ''));
        if (str_contains($type, 'cash') || str_contains(strtolower($name), 'petty')) {
            foreach (['1010', '1000', '1100'] as $code) {
                $stmt = $pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? LIMIT 1');
                $stmt->execute([$code]);
                $id = (int) $stmt->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            }
            $stmt = $pdo->query(
                "SELECT id FROM erp_accounts
                 WHERE type = 'asset' AND (name LIKE '%Petty%' OR name LIKE '%Cash%')
                 ORDER BY id ASC LIMIT 1"
            );
            $id = (int) ($stmt->fetchColumn() ?: 0);

            return $id > 0 ? $id : null;
        }

        $stmt = $pdo->query(
            "SELECT id FROM erp_accounts
             WHERE type = 'asset' AND (name LIKE '%Bank%' OR name LIKE '%Cash%')
             ORDER BY id ASC LIMIT 1"
        );
        $id = (int) ($stmt->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }
}

if (!function_exists('pettyCashNextJournalNumber')) {
    function pettyCashNextJournalNumber(PDO $pdo): string
    {
        try {
            if (function_exists('columnExists') && columnExists('erp_journal_entries', 'entry_number', $pdo)) {
                $stmt = $pdo->query(
                    "SELECT entry_number FROM erp_journal_entries
                     WHERE entry_number LIKE 'JE-%' ORDER BY id DESC LIMIT 1"
                );
                $last = (string) ($stmt->fetchColumn() ?: '');
                if (preg_match('/JE-(\d+)/', $last, $m)) {
                    return 'JE-' . str_pad((string) ((int) $m[1] + 1), 6, '0', STR_PAD_LEFT);
                }
            }
        } catch (Throwable $e) {
            error_log('pettyCashNextJournalNumber: ' . $e->getMessage());
        }

        return 'JE-' . date('ymdHis');
    }
}

if (!function_exists('pettyCashPostBalancedJournal')) {
    /**
     * @param array<int, array{account_id:int,debit:float,credit:float}> $items
     */
    function pettyCashPostBalancedJournal(
        PDO $pdo,
        string $date,
        string $reference,
        string $description,
        array $items,
        int $createdBy
    ): int {
        if (!function_exists('tableExists')
            || !tableExists('erp_journal_entries', $pdo)
            || !tableExists('erp_journal_items', $pdo)
            || !tableExists('erp_accounts', $pdo)
        ) {
            throw new RuntimeException('General ledger tables are not available.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($items as $item) {
            $totalDebit += (float) ($item['debit'] ?? 0);
            $totalCredit += (float) ($item['credit'] ?? 0);
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new RuntimeException('Journal entry is not balanced.');
        }

        $journalAccountCol = function_exists('resolveExistingColumn')
            ? resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account'])
            : 'account_id';
        if (!$journalAccountCol) {
            throw new RuntimeException('Journal items account column not found.');
        }

        $entryNumber = pettyCashNextJournalNumber($pdo);
        $finalDesc = trim($description . ' (Ref: ' . $reference . ')');

        $headerCols = [];
        $headerVals = [];
        $placeholders = [];

        if (function_exists('columnExists') && columnExists('erp_journal_entries', 'entry_number', $pdo)) {
            $headerCols[] = 'entry_number';
            $headerVals[] = $entryNumber;
            $placeholders[] = '?';
        }
        if (function_exists('columnExists') && columnExists('erp_journal_entries', 'date', $pdo)) {
            $headerCols[] = 'date';
            $headerVals[] = $date;
            $placeholders[] = '?';
        }
        if (function_exists('columnExists') && columnExists('erp_journal_entries', 'description', $pdo)) {
            $headerCols[] = 'description';
            $headerVals[] = $finalDesc;
            $placeholders[] = '?';
        }
        if (function_exists('columnExists') && columnExists('erp_journal_entries', 'reference', $pdo)) {
            $headerCols[] = 'reference';
            $headerVals[] = $reference;
            $placeholders[] = '?';
        }
        if (function_exists('columnExists') && columnExists('erp_journal_entries', 'created_by', $pdo)) {
            $headerCols[] = 'created_by';
            $headerVals[] = $createdBy;
            $placeholders[] = '?';
        }

        if ($headerCols === []) {
            throw new RuntimeException('Cannot insert journal entry (no compatible columns).');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO erp_journal_entries (' . implode(', ', $headerCols) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($headerVals);
        $journalId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            "INSERT INTO erp_journal_items (journal_id, {$journalAccountCol}, debit, credit) VALUES (?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $accountId = (int) ($item['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new RuntimeException('Journal line is missing a G/L account.');
            }
            $itemStmt->execute([
                $journalId,
                $accountId,
                (float) ($item['debit'] ?? 0),
                (float) ($item['credit'] ?? 0),
            ]);
        }

        return $journalId;
    }
}

if (!function_exists('pettyCashExecuteInternalTransfer')) {
    /**
     * @return array{out_tx_id:int,in_tx_id:int}
     */
    function pettyCashExecuteInternalTransfer(
        PDO $pdo,
        int $sourceAccountId,
        int $pettyAccountId,
        float $amount,
        int $replenishmentId,
        string $repNumber,
        string $description
    ): array {
        if (!pettyCashLoadBalancesModule()) {
            throw new RuntimeException('Financial accounts module is not available.');
        }

        $from = pettyCashGetFinancialAccount($sourceAccountId, true);
        $to = pettyCashGetFinancialAccount($pettyAccountId, true);
        if (!$from || !$to) {
            throw new RuntimeException('Selected accounts are invalid or inactive.');
        }
        if ($sourceAccountId === $pettyAccountId) {
            throw new RuntimeException('Source and petty cash accounts must be different.');
        }

        if (function_exists('balancesRecalculateAccount')) {
            balancesRecalculateAccount($pdo, $sourceAccountId);
            balancesRecalculateAccount($pdo, $pettyAccountId);
        }

        $sourceBalance = (float) ($from['current_balance'] ?? 0);
        $balStmt = $pdo->prepare('SELECT current_balance FROM financial_accounts WHERE id = ?');
        $balStmt->execute([$sourceAccountId]);
        $sourceBalance = (float) ($balStmt->fetchColumn() ?: $sourceBalance);

        if ($sourceBalance + 0.0001 < $amount) {
            throw new RuntimeException(
                'Insufficient balance in source account "' . ($from['name'] ?? '') . '". Available: '
                . number_format($sourceBalance, 2) . ', required: ' . number_format($amount, 2) . '.'
            );
        }

        $date = date('Y-m-d H:i:s');
        $narration = trim('Petty cash top-up [' . $repNumber . '] ' . $description);
        $refType = 'petty_cash_topup';

        if (!balancesRecordTransaction(
            $pdo,
            $sourceAccountId,
            'debit',
            $amount,
            'Transfer to ' . ($to['name'] ?? 'petty cash') . ' - ' . $narration,
            $refType,
            $replenishmentId,
            $date
        )) {
            throw new RuntimeException('Failed to record source account transfer.');
        }
        $outTxId = (int) $pdo->lastInsertId();

        if (!balancesRecordTransaction(
            $pdo,
            $pettyAccountId,
            'credit',
            $amount,
            'Transfer from ' . ($from['name'] ?? 'source') . ' - ' . $narration,
            $refType,
            $replenishmentId,
            $date
        )) {
            throw new RuntimeException('Failed to record petty cash account transfer.');
        }
        $inTxId = (int) $pdo->lastInsertId();

        balancesRecalculateAccount($pdo, $sourceAccountId);
        balancesRecalculateAccount($pdo, $pettyAccountId);

        return ['out_tx_id' => $outTxId, 'in_tx_id' => $inTxId];
    }
}

if (!function_exists('pettyCashReverseInternalTransfer')) {
    function pettyCashReverseInternalTransfer(PDO $pdo, array $rep): void
    {
        if (!pettyCashLoadBalancesModule()) {
            throw new RuntimeException('Financial accounts module is not available.');
        }

        $sourceId = (int) ($rep['source_account_id'] ?? 0);
        $pettyId = (int) ($rep['petty_cash_account_id'] ?? 0);
        $amount = (float) ($rep['amount'] ?? 0);
        $repId = (int) ($rep['id'] ?? 0);
        $repNumber = (string) ($rep['replenishment_number'] ?? ('REP-' . $repId));

        if ($sourceId <= 0 || $pettyId <= 0 || $amount <= 0) {
            return;
        }

        $from = pettyCashGetFinancialAccount($sourceId, true);
        $to = pettyCashGetFinancialAccount($pettyId, true);
        if (!$from || !$to) {
            throw new RuntimeException('Cannot reverse transfer: account not found.');
        }

        $date = date('Y-m-d H:i:s');
        $narration = 'Reversal of petty cash top-up [' . $repNumber . ']';
        $refType = 'petty_cash_topup_reversal';

        balancesRecordTransaction(
            $pdo,
            $pettyId,
            'debit',
            $amount,
            'Reversal to ' . ($from['name'] ?? 'source') . ' - ' . $narration,
            $refType,
            $repId,
            $date
        );
        balancesRecordTransaction(
            $pdo,
            $sourceId,
            'credit',
            $amount,
            'Reversal from ' . ($to['name'] ?? 'petty cash') . ' - ' . $narration,
            $refType,
            $repId,
            $date
        );

        balancesRecalculateAccount($pdo, $sourceId);
        balancesRecalculateAccount($pdo, $pettyId);
    }
}

if (!function_exists('createPettyCashReplenishment')) {
    function createPettyCashReplenishment($data)
    {
        global $pdo;
        ensurePettyCashSchema();

        if (!pettyCashHasFinancialAccounts()) {
            error_log('createPettyCashReplenishment: financial_accounts table missing');

            return false;
        }

        $pettyAccountId = (int) ($data['petty_cash_account_id'] ?? 0);
        $sourceAccountId = (int) ($data['source_account_id'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $createdBy = (int) ($data['created_by'] ?? $data['custodian_id'] ?? 0);

        if ($pettyAccountId <= 0 || $sourceAccountId <= 0) {
            error_log('createPettyCashReplenishment: missing account ids');

            return false;
        }
        if ($pettyAccountId === $sourceAccountId) {
            error_log('createPettyCashReplenishment: same source and petty account');

            return false;
        }
        if ($amount <= 0) {
            return false;
        }
        if ($description === '') {
            return false;
        }

        if (!pettyCashGetFinancialAccount($pettyAccountId) || !pettyCashGetFinancialAccount($sourceAccountId)) {
            return false;
        }

        $repNumber = generateReplenishmentNumber();

        $stmt = $pdo->prepare(
            "INSERT INTO petty_cash_replenishments
            (replenishment_number, custodian_id, petty_cash_account_id, source_account_id, amount, description,
             previous_balance, new_balance, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'pending', ?)"
        );

        try {
            $stmt->execute([
                $repNumber,
                (int) ($data['custodian_id'] ?? $createdBy),
                $pettyAccountId,
                $sourceAccountId,
                $amount,
                $description,
                $createdBy,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('createPettyCashReplenishment: ' . $e->getMessage());

            return false;
        }
    }
}

if (!function_exists('getPettyCashVoucherApprovalPreview')) {
    /**
     * Pending voucher summary with custodian balance for admin confirmation.
     *
     * @return array<string, mixed>|null
     */
    function getPettyCashVoucherApprovalPreview(int $id): ?array
    {
        global $pdo;

        $voucher = getPettyCashVoucher($id);
        if (!$voucher || strtolower((string) ($voucher['status'] ?? '')) !== 'pending') {
            return null;
        }

        $custodianId = (int) ($voucher['custodian_id'] ?? 0);
        $amount = (float) ($voucher['amount'] ?? 0);
        if ($custodianId <= 0 || $amount <= 0) {
            return null;
        }

        ensurePettyCashAccount($custodianId);
        $balStmt = $pdo->prepare(
            "SELECT current_balance FROM petty_cash_balance WHERE custodian_id = ? AND status = 'active' LIMIT 1"
        );
        $balStmt->execute([$custodianId]);
        $balance = (float) $balStmt->fetchColumn();
        $balanceAfter = $balance - $amount;

        $canApprove = $balance + 0.0001 >= $amount;
        $insufficientMessage = '';
        if (!$canApprove) {
            $insufficientMessage = 'Insufficient petty cash balance for '
                . ($voucher['custodian_name'] ?? 'this custodian')
                . '. Available TZS ' . number_format($balance, 2)
                . ', required TZS ' . number_format($amount, 2) . '.';
        }

        return array_merge($voucher, [
            'petty_balance' => $balance,
            'petty_balance_after' => $balanceAfter,
            'can_approve' => $canApprove,
            'insufficient_message' => $insufficientMessage,
        ]);
    }
}

if (!function_exists('getPettyCashReplenishmentViewData')) {
    /**
     * Read-only top-up details for viewing an approved (or other) request.
     *
     * @return array<string, mixed>|null
     */
    function getPettyCashReplenishmentViewData(int $id): ?array
    {
        $rep = getPettyCashReplenishment($id);
        if (!$rep) {
            return null;
        }

        $status = strtolower((string) ($rep['status'] ?? ''));
        if ($status === 'pending') {
            return getPettyCashReplenishmentApprovalPreview($id);
        }

        $amount = (float) ($rep['amount'] ?? 0);
        $pettyBefore = (float) ($rep['previous_balance'] ?? 0);
        $pettyAfter = (float) ($rep['new_balance'] ?? 0);

        $sourceId = (int) ($rep['source_account_id'] ?? 0);
        $sourceBalance = 0.0;
        $sourceAfter = 0.0;
        if ($sourceId > 0 && pettyCashHasFinancialAccounts()) {
            $src = pettyCashGetFinancialAccount($sourceId);
            $sourceAfter = (float) ($src['current_balance'] ?? 0);
            if ($status === 'approved' && $amount > 0) {
                $sourceBalance = $sourceAfter + $amount;
            } else {
                $sourceBalance = $sourceAfter;
            }
        }

        return array_merge($rep, [
            'source_account_name' => (string) ($rep['source_account_name'] ?? '—'),
            'petty_cash_account_name' => (string) ($rep['petty_cash_account_name'] ?? '—'),
            'source_balance' => $sourceBalance,
            'petty_balance' => $pettyBefore,
            'source_balance_after' => $sourceAfter,
            'petty_balance_after' => $pettyAfter,
            'can_approve' => false,
            'view_only' => true,
            'insufficient_message' => '',
        ]);
    }
}

if (!function_exists('getPettyCashReplenishmentApprovalPreview')) {
    /**
     * Pending top-up summary with live balances for admin confirmation screen.
     *
     * @return array<string, mixed>|null
     */
    function getPettyCashReplenishmentApprovalPreview(int $id): ?array
    {
        global $pdo;

        $rep = getPettyCashReplenishment($id);
        if (!$rep || strtolower((string) ($rep['status'] ?? '')) !== 'pending') {
            return null;
        }

        $sourceId = (int) ($rep['source_account_id'] ?? 0);
        $pettyId = (int) ($rep['petty_cash_account_id'] ?? 0);
        $amount = (float) ($rep['amount'] ?? 0);

        if ($sourceId <= 0 || $pettyId <= 0 || $amount <= 0) {
            return null;
        }

        if (pettyCashLoadBalancesModule()) {
            balancesRecalculateAccount($pdo, $sourceId);
            balancesRecalculateAccount($pdo, $pettyId);
        }

        $source = pettyCashGetFinancialAccount($sourceId);
        $petty = pettyCashGetFinancialAccount($pettyId);
        if (!$source || !$petty) {
            return null;
        }

        $sourceBalance = (float) ($source['current_balance'] ?? 0);
        $pettyBalance = (float) ($petty['current_balance'] ?? 0);
        $sourceAfter = $sourceBalance - $amount;
        $pettyAfter = $pettyBalance + $amount;

        $canApprove = $sourceBalance + 0.0001 >= $amount;
        $insufficientMessage = '';
        if (!$canApprove) {
            $insufficientMessage = 'Insufficient balance in source account "' . ($source['name'] ?? '')
                . '". Available TZS ' . number_format($sourceBalance, 2)
                . ', required TZS ' . number_format($amount, 2) . '.';
        }

        return array_merge($rep, [
            'source_account_name' => (string) ($source['name'] ?? ''),
            'petty_cash_account_name' => (string) ($petty['name'] ?? ''),
            'source_balance' => $sourceBalance,
            'petty_balance' => $pettyBalance,
            'source_balance_after' => $sourceAfter,
            'petty_balance_after' => $pettyAfter,
            'can_approve' => $canApprove,
            'insufficient_message' => $insufficientMessage,
        ]);
    }
}

if (!function_exists('approvePettyCashReplenishment')) {
    function approvePettyCashReplenishment($id, $approved_by)
    {
        global $pdo;
        ensurePettyCashSchema();

        if (!pettyCashHasFinancialAccounts()) {
            return 'Financial accounts are not configured.';
        }
        if (!pettyCashLoadBalancesModule()) {
            return 'Balances module is not available.';
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM petty_cash_replenishments WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $id]);
            $rep = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rep) {
                throw new RuntimeException('Replenishment not found.');
            }
            if (strtolower((string) $rep['status']) !== 'pending') {
                throw new RuntimeException('Only pending top-up requests can be approved.');
            }

            $pettyAccountId = (int) ($rep['petty_cash_account_id'] ?? 0);
            $sourceAccountId = (int) ($rep['source_account_id'] ?? 0);
            $amount = (float) ($rep['amount'] ?? 0);

            if ($pettyAccountId <= 0 || $sourceAccountId <= 0) {
                throw new RuntimeException('This request is missing account details and cannot be approved.');
            }

            $pettyBefore = pettyCashGetFinancialAccount($pettyAccountId, true);
            if (!$pettyBefore) {
                throw new RuntimeException('Petty cash account is invalid or inactive.');
            }
            $previousBalance = (float) ($pettyBefore['current_balance'] ?? 0);

            $transfer = pettyCashExecuteInternalTransfer(
                $pdo,
                $sourceAccountId,
                $pettyAccountId,
                $amount,
                (int) $id,
                (string) $rep['replenishment_number'],
                (string) ($rep['description'] ?? '')
            );

            $pettyAfter = pettyCashGetFinancialAccount($pettyAccountId);
            $newBalance = (float) ($pettyAfter['current_balance'] ?? ($previousBalance + $amount));

            $journalId = null;
            $pettyGlId = pettyCashResolveGlAccountId($pettyAccountId);
            $sourceGlId = pettyCashResolveGlAccountId($sourceAccountId);
            if ($pettyGlId && $sourceGlId) {
                $journalId = pettyCashPostBalancedJournal(
                    $pdo,
                    date('Y-m-d'),
                    (string) $rep['replenishment_number'],
                    'Petty cash top-up: ' . (string) ($rep['description'] ?? ''),
                    [
                        ['account_id' => $pettyGlId, 'debit' => $amount, 'credit' => 0],
                        ['account_id' => $sourceGlId, 'debit' => 0, 'credit' => $amount],
                    ],
                    (int) $approved_by
                );
            } elseif (function_exists('tableExists') && tableExists('erp_accounts', $pdo)) {
                throw new RuntimeException('Could not map accounts to the chart of accounts for journal posting.');
            }

            $upd = $pdo->prepare(
                "UPDATE petty_cash_replenishments SET
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    previous_balance = ?,
                    new_balance = ?,
                    transfer_out_transaction_id = ?,
                    transfer_in_transaction_id = ?,
                    journal_entry_id = ?,
                    updated_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->execute([
                (int) $approved_by,
                $previousBalance,
                $newBalance,
                (int) $transfer['out_tx_id'],
                (int) $transfer['in_tx_id'],
                $journalId,
                (int) $id,
            ]);

            if ($upd->rowCount() === 0) {
                throw new RuntimeException('This top-up was already processed.');
            }

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $e->getMessage();
        }
    }
}

if (!function_exists('rejectPettyCashReplenishment')) {
    function rejectPettyCashReplenishment($id, $approved_by, $reason = '')
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status FROM petty_cash_replenishments WHERE id = ? FOR UPDATE");
            $stmt->execute([(int) $id]);
            $status = $stmt->fetchColumn();

            if ($status === false) {
                throw new RuntimeException('Replenishment not found.');
            }
            if (strtolower((string) $status) !== 'pending') {
                throw new RuntimeException('Only pending replenishments can be rejected.');
            }

            $upd = $pdo->prepare(
                "UPDATE petty_cash_replenishments SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?, updated_at = NOW() WHERE id = ?"
            );
            $upd->execute([(int) $approved_by, trim((string) $reason), (int) $id]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $e->getMessage();
        }
    }
}

if (!function_exists('cancelPettyCashReplenishment')) {
    function cancelPettyCashReplenishment($id)
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM petty_cash_replenishments WHERE id = ? FOR UPDATE");
            $stmt->execute([(int) $id]);
            $rep = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rep) {
                throw new RuntimeException('Replenishment not found.');
            }

            $status = strtolower((string) $rep['status']);
            if (!in_array($status, ['pending', 'approved'], true)) {
                throw new RuntimeException('This replenishment cannot be cancelled.');
            }

            if ($status === 'approved') {
                pettyCashReverseInternalTransfer($pdo, $rep);
            }

            $upd = $pdo->prepare(
                "UPDATE petty_cash_replenishments SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
            );
            $upd->execute([(int) $id]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $e->getMessage();
        }
    }
}

if (!function_exists('getPettyCashCategories')) {
    function getPettyCashDefaultCategoryNames(): array
    {
        return [
            'Office Supplies',
            'Transport & Travel',
            'Meals & Entertainment',
            'Maintenance & Repairs',
            'Fuel',
            'Internet & Communication',
            'Miscellaneous',
        ];
    }

    function pettyCashSeedDefaultCategories(PDO $pdo): void
    {
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM petty_cash_categories')->fetchColumn();
            if ($count > 0) {
                return;
            }
            $year = date('Y');
            $num = 1;
            $insert = $pdo->prepare(
                "INSERT INTO petty_cash_categories (code, name, status) VALUES (?, ?, 'active')"
            );
            foreach (getPettyCashDefaultCategoryNames() as $name) {
                $code = 'PC-CAT-' . $year . '-' . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
                $insert->execute([$code, $name]);
                $num++;
            }
        } catch (PDOException $e) {
            error_log('pettyCashSeedDefaultCategories: ' . $e->getMessage());
        }
    }

    function pettyCashNextCategoryCode(PDO $pdo): string
    {
        $year = date('Y');
        $prefix = "PC-CAT-{$year}-";
        $stmt = $pdo->prepare(
            "SELECT code FROM petty_cash_categories WHERE code LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/(\d+)$/', (string) $last, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        do {
            $candidate = $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
            $check = $pdo->prepare('SELECT COUNT(*) FROM petty_cash_categories WHERE code = ?');
            $check->execute([$candidate]);
            if ((int) $check->fetchColumn() === 0) {
                return $candidate;
            }
            $nextNum++;
        } while (true);
    }

    function getAllPettyCashCategories(array $filters = []): array
    {
        global $pdo;
        ensurePettyCashSchema();

        $where = [];
        $params = [];
        if (empty($filters['show_inactive'])) {
            $where[] = "status = 'active'";
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE ? OR code LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM petty_cash_vouchers v WHERE v.category = c.name) AS voucher_count
                FROM petty_cash_categories c
                {$whereClause}
                ORDER BY c.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function createPettyCashCategory(string $name)
    {
        global $pdo;
        ensurePettyCashSchema();
        $name = trim($name);
        if ($name === '') {
            return 'Category name is required.';
        }

        $dup = $pdo->prepare('SELECT id FROM petty_cash_categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $dup->execute([$name]);
        if ($dup->fetchColumn()) {
            return 'A category with this name already exists.';
        }

        $code = pettyCashNextCategoryCode($pdo);
        $stmt = $pdo->prepare('INSERT INTO petty_cash_categories (code, name, status) VALUES (?, ?, ?)');
        $stmt->execute([$code, $name, 'active']);

        return (int) $pdo->lastInsertId();
    }

    function updatePettyCashCategory(int $id, string $name)
    {
        global $pdo;
        ensurePettyCashSchema();
        $name = trim($name);
        if ($id <= 0 || $name === '') {
            return 'Category name is required.';
        }

        $dup = $pdo->prepare('SELECT id FROM petty_cash_categories WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1');
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) {
            return 'A category with this name already exists.';
        }

        $stmt = $pdo->prepare('UPDATE petty_cash_categories SET name = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$name, $id]);

        return true;
    }

    function setPettyCashCategoryStatus(int $id, string $status)
    {
        global $pdo;
        ensurePettyCashSchema();
        if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            return 'Invalid category.';
        }
        $stmt = $pdo->prepare('UPDATE petty_cash_categories SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $id]);

        return true;
    }

    function getPettyCashCategories(): array
    {
        global $pdo;
        ensurePettyCashSchema();

        try {
            $stmt = $pdo->query(
                "SELECT name FROM petty_cash_categories WHERE status = 'active' ORDER BY name ASC"
            );
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($names)) {
                return array_map('strval', $names);
            }
        } catch (PDOException $e) {
            error_log('getPettyCashCategories: ' . $e->getMessage());
        }

        return getPettyCashDefaultCategoryNames();
    }
}

if (!function_exists('pettyCashRenderCustodianCell')) {
    /**
     * Custodian name with profile avatar (photo or initials).
     */
    function pettyCashRenderCustodianCell(array $row, int $size = 32): string
    {
        if (!function_exists('render_approval_flow_avatar')) {
            $avatarFile = dirname(__DIR__, 3) . '/includes/user-avatar.php';
            if (is_file($avatarFile)) {
                require_once $avatarFile;
            }
        }

        $name = trim((string) ($row['custodian_name'] ?? ''));
        $photo = function_exists('user_avatar_photo_url')
            ? user_avatar_photo_url($row['custodian_photo'] ?? '')
            : '';
        $avatar = function_exists('render_approval_flow_avatar')
            ? render_approval_flow_avatar($name, $photo, $size)
            : '';

        $sizeClass = $size <= 32 ? ' pc-name-av--sm' : '';
        $html = '<div class="pc-custodian-cell pc-name-av' . $sizeClass . '">';
        $html .= $avatar;
        $html .= '<span class="pc-custodian-name">' . htmlspecialchars($name !== '' ? $name : '—') . '</span>';
        $html .= '</div>';

        return $html;
    }
}
