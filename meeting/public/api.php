<?php
session_start();
require_once '../database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$db = (new Database())->getConnection();

if ($action === 'register') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);

    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $password]);
        
        // Auto-login
        $_SESSION['user_id'] = $db->lastInsertId();
        $_SESSION['username'] = $username;
        
        echo json_encode(['status' => 'success', 'message' => 'User registered successfully', 'user' => ['username' => $username]]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
} elseif ($action === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        echo json_encode(['status' => 'success', 'message' => 'Login successful', 'user' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
    }
} elseif ($action === 'create_meeting') {
    $meeting_code = bin2hex(random_bytes(5)); // Simple random code
    $host_id = $_SESSION['user_id'] ?? null; // Optional host ID

    try {
        $stmt = $db->prepare("INSERT INTO meetings (meeting_code, host_id, start_time) VALUES (?, ?, NOW())");
        $stmt->execute([$meeting_code, $host_id]);
        echo json_encode(['status' => 'success', 'meeting_code' => $meeting_code]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to create meeting']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
