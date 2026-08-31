<?php
require_once 'includes/config.php';
$pdo->exec("DELETE FROM erp_payroll_settings WHERE name LIKE '%Test%' OR name = 'API Test Tax'");
echo "Cleaned up test rules.";
?>
