<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$poId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($poId <= 0) {
    echo json_encode([]);
    exit;
}

// Fetch items for this PO
$sql = "
    SELECT pi.*, i.name as item_name, i.sku, i.uom 
    FROM stocks_po_items pi
    JOIN stocks_items i ON pi.item_id = i.id
    WHERE pi.po_id = ?
";
$items = $pdo->prepare($sql);
$items->execute([$poId]);
echo json_encode($items->fetchAll(PDO::FETCH_ASSOC));
?>
