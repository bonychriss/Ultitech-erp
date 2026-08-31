<?php
/**
 * CRM customer detail � quotes & invoices.
 * modules/crm/my-clients/view.php
 */
require_once __DIR__ . '/../includes/crm-lib.php';

crmDeskRequireAccess();

$contactId = crmDeskParseContactId($_GET);
if ($contactId <= 0) {
    header('Location: ' . crmDeskDashboardUrl());
    exit;
}

$pdo = crmDeskBootstrap();

try {
    $bootPayload = crmContactViewFetchPayload($pdo, $contactId);
} catch (RuntimeException $e) {
    header('Location: ' . crmDeskDashboardUrl());
    exit;
}

$contactName = trim((string) ($bootPayload['contact']['organization'] ?? ''));
if ($contactName === '') {
    $contactName = trim((string) ($bootPayload['contact']['name'] ?? 'Customer'));
}

crmDeskRenderReactShell($bootPayload, $contactName, $contactName);
