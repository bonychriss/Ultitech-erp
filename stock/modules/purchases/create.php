<?php
/**
 * Legacy purchase create endpoint.
 * Keep old links working by redirecting to the maintained domestic_create flow.
 * Default to import (abroad) when purchase_type is not specified.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
parse_str($qs, $params);
if (!is_array($params)) {
    $params = [];
}
if (empty($params['purchase_type'])) {
    $params['purchase_type'] = 'import';
}
$target = 'domestic_create.php?' . http_build_query($params);
header('Location: ' . $target, true, 302);
exit;
