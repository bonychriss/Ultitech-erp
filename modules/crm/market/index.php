<?php
/**
 * CRM Market — Client Market lead desk.
 * modules/crm/market/index.php
 */
require_once __DIR__ . '/../includes/crm-lib.php';

crmDeskRequireAccess();

crmDeskRenderReactShell(
    crmDeskMarketPayload(),
    'Client Market',
    'Client Market'
);
