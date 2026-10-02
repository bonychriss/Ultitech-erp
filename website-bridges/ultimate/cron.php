<?php
/**
 * Shop cron. Does not replace Laravel's scheduler.
 *
 * cPanel ? Cron Jobs, every 15 minutes, no output redirect:
 *   /usr/local/bin/ea-php82 /home/ultimate/public_html/ultitech/cron.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/sync.php';
