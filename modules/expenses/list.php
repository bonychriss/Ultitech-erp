<?php
/**
 * Legacy sidebar target — expenses list lives on index.php.
 */
$query = $_GET;
$query['module'] = $query['module'] ?? 'expenses';
header('Location: index.php?' . http_build_query($query));
exit;
