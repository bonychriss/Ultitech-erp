<?php
/**
 * Minimal health check entry (no redirect — avoids ERR_TOO_MANY_REDIRECTS).
 * DELETE after troubleshooting.
 * https://ultitech.io/hc.php?key=ultitech-debug
 */
$_GET['key'] = isset($_GET['key']) ? (string) $_GET['key'] : 'ultitech-debug';
require __DIR__ . '/debug_system_full.php';
