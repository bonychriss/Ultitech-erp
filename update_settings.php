<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

try {
    $pdo->beginTransaction();

    // Disable override
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_time_override_enabled', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
    $stmt->execute();

    // Ensure timezone is set explicitly to EAT if missing
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_timezone', 'Africa/Dar_es_Salaam') ON DUPLICATE KEY UPDATE setting_value = 'Africa/Dar_es_Salaam'");
    $stmt->execute();

    $pdo->commit();
    echo "Configuration updated successfully.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error updating settings: " . $e->getMessage() . "\n";
}
?>
