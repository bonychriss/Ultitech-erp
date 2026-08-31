<?php
/**
 * CRM My Customers desk.
 * modules/crm/my-clients/index.php
 */
require_once __DIR__ . '/../includes/crm-lib.php';

crmDeskRequireAccess();

$tab = strtolower(trim((string) ($_GET['tab'] ?? '')));
$payload = crmDeskFetchPayload(crmDeskBootstrap());
if ($tab === 'prospects') {
    $payload['page'] = 'prospects';
    crmDeskRenderReactShell($payload, 'Prospects', 'Prospects');
}

crmDeskRenderReactShell(
    $payload,
    'My Customers',
    'My Customers'
);
