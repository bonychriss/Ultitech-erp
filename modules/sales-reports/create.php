<?php
require_once __DIR__ . '/includes/sales-reports-lib.php';
salesReportsRequireAccess('create');
header('Location: ' . salesReportsUrl('editor.php', ['new' => '1']));
exit;
