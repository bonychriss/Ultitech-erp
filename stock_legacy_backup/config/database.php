<?php
// config/database.php
// Use the main system's configuration and connection
require_once __DIR__ . '/../../includes/functions.php';

// $pdo is available globally from functions.php -> config.php
if (!isset($pdo)) {
    die("Database connection failed. checks includes/config.php");
}

require_once __DIR__ . '/functions.php';
ensureStockMovementsTable($pdo);
