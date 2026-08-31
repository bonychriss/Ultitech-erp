<?php

declare(strict_types=1);

require_once __DIR__ . '/deliveries-ui/includes/delivery-note-view-lib.php';

if (isset($_GET['hash'])) {
    deliveryNoteViewRenderPublicPage();
}

deliveryNoteViewRequireAccess();

$id = deliveryNoteViewParseId($_GET);
if ($id <= 0) {
    http_response_code(400);
    die('ID Missing');
}

if (deliveryNoteViewShouldUseReact()) {
    deliveryNoteViewRenderReactShell($id);
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Delivery Note</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>Delivery Note View</h1>';
echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>deliveries/deliveries-ui/frontend/</code>.</p>';
echo '</body></html>';
