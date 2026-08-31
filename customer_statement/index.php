<?php
require_once __DIR__ . '/includes/statement-lib.php';

customerStatementDeskRequireAccess();

$filters = customer_statement_parse_filters($_GET);
if (customer_statement_handle_download($filters)) {
    exit;
}

customerStatementRenderReactShell();
