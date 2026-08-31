<?php
require_once 'includes/config.php';

try {
    $username = 'wolfwigans';
    $email = 'wolfwigans@gmail.com';
    $password = password_hash('password123', PASSWORD_DEFAULT);
    $role = 'admin';
    $fullName = 'Wolf Wigan';
    $department = 'IT';
    
    // Check if exists first
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo "User with email $email already exists.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, full_name, department, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$username, $email, $password, $role, $fullName, $department]);
        echo "SUCCESS: Created admin user '$username' ($email) with password 'password123'.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
