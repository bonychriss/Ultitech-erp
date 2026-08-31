<?php

require_once __DIR__ . '/includes/functions.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$params = $_GET;
$params['module'] = 'revenue';

header('Location: revenue_entries.php?' . http_build_query($params), true, 302);
exit;
