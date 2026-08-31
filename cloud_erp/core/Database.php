<?php
namespace Core;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        // Load credentials from root env or config if available
        // Assuming we are in /cloud_erp/core, root is ../../
        $rootParams = [];
        if (file_exists(__DIR__ . '/../../env.php')) {
            include __DIR__ . '/../../env.php';
            // env.php likely sets $DB_HOST, etc.
            $rootParams = [
                'host' => $DB_HOST ?? 'localhost',
                'name' => $DB_NAME ?? 'ultimate_trading_voucher',
                'user' => $DB_USER ?? 'root',
                'pass' => $DB_PASS ?? '',
            ];
        } else {
             // Fallback or separate config
             $rootParams = [
                'host' => 'localhost',
                'name' => 'ultimate_trading_voucher',
                'user' => 'root',
                'pass' => '',
            ];
        }

        try {
            $dsn = "mysql:host={$rootParams['host']};dbname={$rootParams['name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $rootParams['user'], $rootParams['pass']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}
