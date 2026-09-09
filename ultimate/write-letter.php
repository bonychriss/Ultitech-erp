<?php
/**
 * Legacy Write Letter URL ? redirect to React Letter compose desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$qs = 'module=letter';
if (!empty($_SERVER['QUERY_STRING'])) {
    $qs = $_SERVER['QUERY_STRING'];
    if (stripos($qs, 'module=') === false) {
        $qs .= '&module=letter';
    }
}
header('Location: /ultimate/letter/compose?' . $qs, true, 302);
exit;
