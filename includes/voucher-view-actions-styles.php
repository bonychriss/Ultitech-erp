<?php
/**
 * Inline Actions dropdown CSS (works when external voucher-view-page.css 404s under /ultimate/).
 */
if (!defined('VV_ACTIONS_STYLES_PRINTED')) {
    define('VV_ACTIONS_STYLES_PRINTED', true);
    $vvActionsCssPath = dirname(__DIR__) . '/assets/css/voucher-view-actions.css';
    echo '<style id="vv-actions-styles">' . "\n";
    if (is_readable($vvActionsCssPath)) {
        readfile($vvActionsCssPath);
    }
    echo "\n" . '</style>' . "\n";
}
