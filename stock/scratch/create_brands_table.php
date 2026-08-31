<?php
/**
 * One-off: loads stock DB config, which runs ensureBrandsTable().
 */
require_once __DIR__ . '/../config/database.php';
echo "Table 'brands' is ready (created or already present with expected columns).\n";
