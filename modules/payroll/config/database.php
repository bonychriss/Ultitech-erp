<?php
/**
 * Bootstrap for modules/payroll (staff/modules/payroll/config/).
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($pdo)) {
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
    function payroll_resolved_schema(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $conn = payroll_meta_pdo();
        if (!($conn instanceof PDO)) {
            $resolved = '';
            return $resolved;
        }

        $candidates = [];
        try {
            $currentDb = trim((string) $conn->query('SELECT DATABASE()')->fetchColumn());
            if ($currentDb !== '') {
                $candidates[] = $currentDb;
            }
        } catch (Throwable $e) {
            $currentDb = '';
        }

        $cid = 0;
        try {
            $cid = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
        } catch (Throwable $e) {
            $cid = 0;
        }
        if ($cid <= 0 && !empty($_SESSION['company_id'])) {
            $cid = (int) $_SESSION['company_id'];
        }

        if ($cid > 0 && function_exists('tableExists') && tableExists('companies', $conn)) {
            try {
                $stmt = $conn->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $stmt->execute([$cid]);
                $companyDb = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($companyDb !== '') {
                    $candidates[] = $companyDb;
                }
            } catch (Throwable $e) {
            }
        }

        if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
            $candidates[] = trim((string) DATA_DB_NAME);
        }
        if (defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '') {
            $candidates[] = trim((string) SALES_DB_NAME);
        }

        $candidates = array_values(array_unique(array_filter($candidates, static function ($dbName) {
            return trim((string) $dbName) !== '';
        })));

        $bestSchema = '';
        $bestScore = -1;
        $tables = ['employee_salary', 'payroll_runs', 'payslips', 'payroll_settings', 'payroll_tax_bands'];
        foreach ($candidates as $dbName) {
            $score = 0;
            foreach ($tables as $tableName) {
                try {
                    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
                    $stmt->execute([$dbName, $tableName]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        $score++;
                    }
                } catch (Throwable $e) {
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSchema = $dbName;
            }
        }

        if ($bestSchema === '' && !empty($currentDb)) {
            $bestSchema = $currentDb;
        }

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
