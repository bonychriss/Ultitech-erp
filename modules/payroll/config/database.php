<?php
/**
 * Bootstrap for modules/payroll (staff/modules/payroll/config/).
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($pdo)) {
    if (PHP_SAPI !== 'cli' && strpos(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/modules/payroll/api/') !== false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Database connection failed.']);
        exit;
    }
    die('Database connection failed.');
}

if (!function_exists('payroll_meta_pdo')) {
    function payroll_meta_pdo()
    {
        global $control_pdo, $pdo;
        if (($control_pdo ?? null) instanceof PDO) {
            return $control_pdo;
        }
        return $pdo instanceof PDO ? $pdo : null;
    }
}

if (!function_exists('payroll_resolved_schema')) {
    /**
     * Resolve the tenant schema for payroll tables.
     * Always prefer the active company DB connection — never "steal" Ultimate's
     * DATA_DB_NAME just because it already has payroll_* tables.
     */
    function payroll_resolved_schema(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        global $pdo, $control_pdo;

        $tenantDb = '';
        if (($pdo ?? null) instanceof PDO) {
            try {
                $tenantDb = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
            } catch (Throwable $e) {
                $tenantDb = '';
            }
        }

        $companyDb = '';
        $meta = (($control_pdo ?? null) instanceof PDO)
            ? $control_pdo
            : ((($pdo ?? null) instanceof PDO) ? $pdo : null);

        $cid = 0;
        try {
            $cid = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
        } catch (Throwable $e) {
            $cid = 0;
        }
        if ($cid <= 0 && !empty($_SESSION['company_id'])) {
            $cid = (int) $_SESSION['company_id'];
        }

        if ($cid > 0 && $meta instanceof PDO && function_exists('tableExists') && tableExists('companies', $meta)) {
            try {
                $stmt = $meta->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $stmt->execute([$cid]);
                $companyDb = trim((string) ($stmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $companyDb = '';
            }
        }

        // Prefer the live tenant connection; fall back to companies.db_name.
        $bestSchema = $tenantDb !== '' ? $tenantDb : $companyDb;

        $resolved = $bestSchema;
        $GLOBALS['payroll_database_name'] = $bestSchema;
        return $resolved;
    }
}

if (!function_exists('payroll_table')) {
    function payroll_table(string $tableName): string
    {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $schema = payroll_resolved_schema();
        if ($schema === '') {
            return '`' . $safeTable . '`';
        }
        return '`' . str_replace('`', '``', $schema) . '`.`' . $safeTable . '`';
    }
}

if (!function_exists('payroll_table_exists')) {
    function payroll_table_exists(string $tableName): bool
    {
        $conn = payroll_meta_pdo();
        $schema = payroll_resolved_schema();
        if (!($conn instanceof PDO) || $schema === '') {
            return false;
        }
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $stmt->execute([$schema, preg_replace('/[^a-zA-Z0-9_]/', '', $tableName)]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
