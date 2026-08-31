<?php
// Simple API to look up email by full name for the forgot password page
// intended for internal system usage where user directory privacy is less critical than convenience.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$fullName = trim($_POST['full_name'] ?? '');

if (empty($fullName)) {
    echo json_encode(['success' => false, 'message' => 'Full Name is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE full_name = ? LIMIT 1");
    $stmt->execute([$fullName]);
    $email = $stmt->fetchColumn();

    if ($email) {
        echo json_encode(['success' => true, 'email' => $email]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
