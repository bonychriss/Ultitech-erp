<?php
require_once '../../includes/functions.php';
requireLogin();

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: index.php");
    exit;
}

global $pdo;

// For "Soft Delete", we usually set a status to 'deleted' or use a deleted_at timestamp.
// Since the user mentionederp_expenses has a status column, we'll try to use that or add a 'hidden' status.
// But first, let's ensure the 'status' column can handle 'deleted'.

try {
    // Soft Delete: Set status to 'deleted'
    $stmt = $pdo->prepare("UPDATE erp_expenses SET status = 'deleted' WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: index.php?msg=deleted");
    exit;

} catch (PDOException $e) {
    die("Error deleting record: " . $e->getMessage());
}
