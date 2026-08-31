<?php

declare(strict_types=1);

/**
 * Dispatch create uses the deliveries form (same fields).
 * Without an invoice, submit also creates a dispatch note automatically.
 */
require_once '../includes/functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'dispatch';
}

$slug = function_exists('getRequestedCompanySlug') ? strtolower(trim(getRequestedCompanySlug())) : '';
if ($slug === '' && !empty($_SESSION['company_slug'])) {
    $slug = strtolower(trim((string) $_SESSION['company_slug']));
}

$params = 'module=deliveries&create_dispatch=1';
if ($slug !== '' && function_exists('company_url')) {
    $url = company_url('deliveries/create_delivery.php', $slug) . '?' . $params;
} elseif (function_exists('app_url')) {
    $url = app_url('/deliveries/create_delivery.php') . '?' . $params;
} else {
    $url = '/deliveries/create_delivery.php?' . $params;
}

header('Location: ' . $url);
exit;
