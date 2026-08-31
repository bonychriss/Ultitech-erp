<?php
/**
 * Client Market entry inside the ERP � opens CRM Market (embedded), not a separate browser app URL.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/crm-lib.php';

crmDeskRequireAccess();

$url = crmDeskMarketUrl();
$sep = strpos($url, '?') !== false ? '&' : '?';
header('Location: ' . $url . $sep . 'module=crm', true, 302);
exit;
