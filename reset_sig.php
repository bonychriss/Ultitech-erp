<?php
require_once 'includes/functions.php';

// Reset signature for Delivery Note ID 2 (which corresponds to our test hash)
$stmt = $pdo->prepare("UPDATE delivery_notes SET receiver_signature_path = NULL WHERE id = 2");
$stmt->execute();

echo "Signature reset for Delivery Note ID 2.";
?>
