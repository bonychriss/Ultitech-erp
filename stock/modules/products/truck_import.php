<?php
// stock/modules/products/truck_import.php — redirect to React import desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
header('Location: bulk_import.php?mode=truck');
exit;
