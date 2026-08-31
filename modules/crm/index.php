<?php
/**
 * Legacy CRM dashboard entry � redirects to My Customers desk.
 */
require_once __DIR__ . '/includes/crm-lib.php';

crmDeskRequireAccess();
header('Location: ' . crmDeskDashboardUrl());
exit;
