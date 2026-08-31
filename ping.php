<?php
/**
 * Upload check — no bootstrap, no .htaccess slug rules.
 * https://ultitech.io/ping.php
 * DELETE after use.
 */
header('Content-Type: text/plain; charset=UTF-8');
echo 'OK ' . ($_SERVER['HTTP_HOST'] ?? 'host') . ' ' . date('c') . "\n";
echo 'Use: https://' . ($_SERVER['HTTP_HOST'] ?? 'ultitech.io') . '/debug_system_full.php?key=ultitech-debug' . "\n";
