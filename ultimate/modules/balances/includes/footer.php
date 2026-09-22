<?php
/**
 * Ultimate stub: reuse the shared balances footer (closes layout + scripts).
 */
$sharedFooter = dirname(__DIR__, 4) . '/modules/balances/includes/footer.php';
if (!is_file($sharedFooter)) {
    error_log('balances footer stub: shared footer not found at ' . $sharedFooter);
    echo '</div></div></body></html>';
    return;
}
require $sharedFooter;
