<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isSuperAdmin()) {
    http_response_code(403);
    die('Access denied.');
}

$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    header('Location: ../company/dashboard.php');
    exit;
}

if (switchActiveCompany($companyId)) {
    $_SESSION['success'] = 'Company context switched successfully.';
} else {
    $_SESSION['error'] = 'Unable to switch company.';
}

header('Location: ../company/dashboard.php');
exit;
