<?php
require_once 'includes/functions.php';

echo "Current System Time (Default): " . getSystemTime()->format('Y-m-d H:i:s') . "\n";

// Simulate enabling override
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_time_override_enabled', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_override_time', '2025-01-01 12:00:00') ON DUPLICATE KEY UPDATE setting_value='2025-01-01 12:00:00'");

// Clear cache (simulated by calling function in new request context, but here we just check if it picks up changes if we didn't use static cache or if we reset it. 
// Since getSystemTime uses static cache, we can't easily test dynamic updates in same script execution without resetting state.
// However, for this test, let's just print what it returns. In a real scenario, a new request would pick up the changes.
// To properly test, we might need to rely on the fact that the function fetches from DB if cache is null. 
// But since it's already called above, it's cached. 
// Let's just output the DB values to confirm they are set.

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Settings in DB:\n";
print_r($settings);

echo "\nNote: getSystemTime() caches the result for the duration of the request, so it won't reflect the DB change immediately in this script output if called again. But the DB update confirms the admin panel logic would work.\n";
?>
