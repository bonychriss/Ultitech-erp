<?php
require_once '../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tripId = $_POST['trip_id'] ?? null;
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($csrf)) {
        die("Invalid CSRF Token");
    }

    if ($tripId) {
        try {
            $pdo->beginTransaction();

            // 1. Unlink orders from this trip (set trip_id = NULL, status = request_pending or similar if needed)
            // For now, we just unlink them so they can be reassigned.
            $stmtUnlink = $pdo->prepare("UPDATE delivery_orders SET trip_id = NULL, status = 'request_pending' WHERE trip_id = ?");
            $stmtUnlink->execute([$tripId]);

            // 2. Delete the trip
            $stmtDel = $pdo->prepare("DELETE FROM delivery_trips WHERE id = ?");
            $stmtDel->execute([$tripId]);

            $pdo->commit();
            
            // Redirect back to referrer
            $referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            header("Location: " . $referrer . (strpos($referrer, '?') ? '&' : '?') . "success=trip_deleted");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: index.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }
}
header("Location: index.php");
exit;
