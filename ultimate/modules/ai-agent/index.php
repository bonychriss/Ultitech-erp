<?php
/**
 * Physical alias so /ultimate/modules/ai-agent/index.php opens the app-root agent page.
 * The real /ultimate/ folder blocks the parent tenant rewrite.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__, 3) . '/modules/ai-agent/index.php';
