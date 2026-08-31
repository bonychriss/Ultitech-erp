<?php
require_once '../config.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE erp_quotes");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
