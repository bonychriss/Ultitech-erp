<?php
/**
 * Shared bootstrap for deliveries module (same pattern as stock/config/database.php).
 */
require_once __DIR__ . '/../../includes/functions.php';

// When this file is require()'d from a function (e.g. deliveryNoteViewBootstrap),
// top-level $pdo from config.php lives in $GLOBALS, not local scope.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } elseif (isset($GLOBALS['control_pdo']) && $GLOBALS['control_pdo'] instanceof PDO) {
        $pdo = $GLOBALS['control_pdo'];
        $GLOBALS['pdo'] = $pdo;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection failed. Check includes/config.php');
}

$GLOBALS['pdo'] = $pdo;

ensureDeliveriesSchema();
