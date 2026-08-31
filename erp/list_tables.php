<?php
require_once '../config.php';
global $pdo;
$stmt = $pdo->query("SHOW TABLES LIKE 'erp_%'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
