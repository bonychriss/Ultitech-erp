<?php
/**
 * One-shot: set WhatsApp display/business line for payment vouchers.
 * Usage: php scripts/set-whatsapp-display-phone.php
 */
require_once dirname(__DIR__) . '/includes/functions.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

ensureSystemSettingsSchema();

$phone = '+255 618166670'; // from 0618166670
$pairs = [
    'whatsapp_display_phone' => $phone,
    'whatsapp_provider' => 'kapso',
    'whatsapp_auto_send_vouchers' => '1',
];

$stmt = $pdo->prepare('
    INSERT INTO system_settings (setting_key, setting_value)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
');

foreach ($pairs as $key => $value) {
    $stmt->execute([$key, $value]);
    echo "Set {$key} = {$value}\n";
}

echo "Done.\n";
