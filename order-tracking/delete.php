<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
$userDept = $_SESSION['department'] ?? '';
if ($userRole !== 'procurement' && $userRole !== 'admin' && strtolower($userDept) !== 'procurement') {
    die("Access Denied");
}

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM order_tracking WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: index.php");
        exit;
    } catch (PDOException $e) {
        die("Error deleting record: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
    exit;
}
?>
