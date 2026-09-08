<?php
// config/database.php
// Use the main system's configuration and connection
require_once __DIR__ . '/../../includes/functions.php';

// When this file is require()'d from a class/method (Laravel bridge),
// top-level $pdo from functions.php lives in $GLOBALS, not local scope.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } elseif (isset($GLOBALS['control_pdo']) && $GLOBALS['control_pdo'] instanceof PDO) {
        $pdo = $GLOBALS['control_pdo'];
        $GLOBALS['pdo'] = $pdo;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Database connection failed. checks includes/config.php");
}

require_once __DIR__ . '/functions.php';

// Per-company tenant DB (Roadmaster must not share Ultimate's DATA_DB for stock/uploads)
if (function_exists('stock_company_pdo')) {
    $companyPdo = stock_company_pdo();
    if ($companyPdo instanceof PDO) {
        $pdo = $companyPdo;
        $GLOBALS['pdo'] = $companyPdo;
    }
} elseif (function_exists('erp_data_pdo')) {
    $erpPdo = erp_data_pdo();
    if ($erpPdo instanceof PDO) {
        $pdo = $erpPdo;
        $GLOBALS['pdo'] = $erpPdo;
    }
}
ensureStockMovementsTable($pdo);
ensureStoreWarehouseReceiptsTable($pdo);
ensureStoreReleaseDocumentsTable($pdo);
ensureBrandsTable($pdo);
ensureWarehousesSchema($pdo);
?>
