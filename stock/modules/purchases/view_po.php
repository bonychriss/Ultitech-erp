<?php

require_once __DIR__ . '/includes/po-view-lib.php';
require_once __DIR__ . '/includes/po-view-actions.php';

poViewDeskRequireAccess();

$id = poViewParseId($_GET);
if ($id <= 0) {
    redirect('index.php');
}

/** @var PDO $pdo */
global $pdo;
poViewHandleRequestActions($pdo, $id);

define('PO_VIEW_ROUTER', true);
$company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);

if (poViewShouldUseReact() && poViewLoadReactAssets() !== null) {
    poViewRenderReactShell($id);
}

require __DIR__ . '/view_po-legacy.php';
