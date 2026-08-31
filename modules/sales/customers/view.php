<?php
require_once __DIR__ . '/includes/catalogue-lib.php';

customerCatalogueDeskRequireAccess();

$customerId = customersDeskParseCustomerId($_GET);
if ($customerId <= 0) {
    header('Location: ' . sales_module_url('customers/index.php', ['module' => customerCatalogueModuleQuery()]));
    exit;
}

customerViewRenderReactShell();
