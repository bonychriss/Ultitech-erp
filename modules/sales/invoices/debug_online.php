<?php
/**
 * Online Debugging Tool for Invoice Page
 * Access via: https://ultitech.io/modules/sales/invoices/debug_online.php?company_slug=ultimate
 */

header('Content-Type: text/plain; charset=utf-8');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "=== System Diagnostic Helper ===\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "Query String: " . ($_SERVER['QUERY_STRING'] ?? '') . "\n";

// 1. Check file existence
echo "\n--- File Checking ---\n";
$files = [
    'create.php' => __DIR__ . '/create.php',
    'invoices-lib.php' => __DIR__ . '/includes/invoices-lib.php',
    'config.php' => dirname(__DIR__, 3) . '/includes/config.php',
    'functions.php' => dirname(__DIR__, 3) . '/includes/functions.php'
];
foreach ($files as $name => $path) {
    echo "$name: " . (is_file($path) ? "EXISTS" : "MISSING") . " ($path)\n";
}

// 2. Start session and load config
echo "\n--- Loading Config ---\n";
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "Session State BEFORE config: " . json_encode($_SESSION) . "\n";

    // Set mock if requested
    if (isset($_GET['mock'])) {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';
        $_SESSION['company_id'] = 1;
        $_SESSION['company_slug'] = 'ultimate';
        echo "Mock session injected.\n";
    }

    require_once dirname(__DIR__, 3) . '/includes/config.php';
    echo "config.php included successfully!\n";
} catch (Throwable $t) {
    echo "FATAL ERROR loading config.php: " . $t->getMessage() . "\n";
    echo "File: " . $t->getFile() . " Line: " . $t->getLine() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit;
}

// 3. Print company details
echo "\n--- Tenant Configuration ---\n";
try {
    $slug = getRequestedCompanySlug();
    echo "Requested company slug (getRequestedCompanySlug()): '$slug'\n";

    $company_id = currentCompanyId();
    echo "currentCompanyId(): " . var_export($company_id, true) . "\n";

    if ($company_id) {
        $info = getCompanyInfo($company_id);
        echo "Company Info: " . json_encode($info) . "\n";
    }
} catch (Throwable $t) {
    echo "Error resolving tenant: " . $t->getMessage() . "\n";
}

// 4. Test Database Connection
echo "\n--- DB Connection Test ---\n";
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        echo "Main database PDO connection established.\n";
        $stmt = $pdo->query("SELECT id, name, slug, db_name, db_host, db_user FROM companies");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Registered Companies:\n";
        foreach ($companies as $c) {
            echo " - ID: {$c['id']}, Name: {$c['name']}, Slug: {$c['slug']}, DB: {$c['db_name']}, Host: {$c['db_host']}\n";
        }
    } else {
        echo "Main \$pdo is NOT a PDO instance.\n";
    }
} catch (Throwable $t) {
    echo "Error querying main database: " . $t->getMessage() . "\n";
}

try {
    if (function_exists('sales_resolve_company_tenant_pdo')) {
        echo "Attempting to resolve tenant database connection...\n";
        $tenantPdo = sales_resolve_company_tenant_pdo();
        if ($tenantPdo instanceof PDO) {
            echo "Tenant database connection SUCCESS!\n";
        } else {
            echo "Tenant database connection FAILED (returned helper, not PDO)\n";
        }
    }
} catch (Throwable $t) {
    echo "Error connecting to tenant database: " . $t->getMessage() . "\n";
    echo "File: " . $t->getFile() . " Line: " . $t->getLine() . "\n";
}

// 5. Test invoices-lib inclusion
echo "\n--- Including invoices-lib.php ---\n";
try {
    require_once __DIR__ . '/includes/invoices-lib.php';
    echo "invoices-lib.php included successfully!\n";
    if (function_exists('invoicesDeskWebBasePath')) {
        echo "invoicesDeskWebBasePath() = " . invoicesDeskWebBasePath() . "\n";
        if (function_exists('app_url')) {
            echo "APP_BASE_PATH = " . (defined('APP_BASE_PATH') ? APP_BASE_PATH : 'UNDEFINED') . "\n";
            echo "app_url('/modules/sales/invoices') = " . app_url('/modules/sales/invoices') . "\n";
        }
    }
} catch (Throwable $t) {
    echo "Error in invoices-lib.php: " . $t->getMessage() . "\n";
}

echo "\nDiagnostic completed successfully.\n";
