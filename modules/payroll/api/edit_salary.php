<?php
/**
 * Legacy redirect - edit salary page is not under api/.
 */
declare(strict_types=1);

$params = $_GET ?: [];
$id = (int) ($params['id'] ?? 0);
unset($params['id']);

$target = '../edit_salary.php';
if ($id > 0) {
    $params['id'] = $id;
}
$query = http_build_query($params);
header('Location: ' . $target . ($query !== '' ? '?' . $query : ''));
exit;
