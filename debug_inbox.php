<?php
/**
 * Root diagnostic entry (rewrites from /ultimate/debug_inbox.php).
 * Prefer this URL on live after upload:
 *   https://ultitech.io/ultimate/debug_inbox.php
 */
if (empty($_GET['company_slug']) && empty($_REQUEST['company_slug'])) {
    // Fallback when opened without company rewrite
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if (strpos($uri, '/ultimate/') !== false || strpos($uri, '/ultimate?') !== false) {
        $_GET['company_slug'] = 'ultimate';
    }
}

require __DIR__ . '/modules/email/debug_inbox.php';
