<?php
/**
 * Legacy CRM customer view � redirects to My Customers view.
 */
require_once __DIR__ . '/includes/crm-lib.php';

crmDeskRequireAccess();

$contactId = crmDeskParseContactId($_GET);
if ($contactId <= 0) {
    header('Location: ' . crmDeskDashboardUrl());
    exit;
}

header('Location: ' . crmDeskContactViewUrl($contactId));
exit;
