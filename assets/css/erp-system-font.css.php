<?php
declare(strict_types=1);

define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once dirname(__DIR__, 2) . '/includes/config.php';

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=300');

if (function_exists('erp_build_system_font_css_rules')) {
    echo erp_build_system_font_css_rules();
}
