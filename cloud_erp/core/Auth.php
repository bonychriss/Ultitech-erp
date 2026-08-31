<?php
namespace Core;

require_once __DIR__ . '/Database.php';

class Auth {
    public static function login($email, $password) {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT u.*, c.name as company_name, r.name as role_name 
                              FROM erp_users u 
                              JOIN companies c ON u.company_id = c.id 
                              LEFT JOIN erp_roles r ON u.role_id = r.id
                              WHERE u.email = ? AND u.is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['erp_user_id'] = $user['id'];
            $_SESSION['erp_company_id'] = $user['company_id'];
            $_SESSION['erp_user_name'] = $user['full_name'];
            $_SESSION['erp_user_role'] = $user['role_name'];
            $_SESSION['erp_company_name'] = $user['company_name'];
            return true;
        }
        return false;
    }

    public static function logout() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
    }

    public static function check() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['erp_user_id'])) {
            header("Location: login.php");
            exit;
        }
    }

    public static function user() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return $_SESSION;
    }
}
