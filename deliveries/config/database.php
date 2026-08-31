<?php
/**
 * Shared bootstrap for deliveries module (same pattern as stock/config/database.php).
 */
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($pdo)) {
    die('Database connection failed. Check includes/config.php');
}

ensureDeliveriesSchema();
