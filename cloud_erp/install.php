<?php
require_once __DIR__ . '/core/Database.php';

use Core\Database;

echo "<h1>Ultimate ERP Installation</h1>";

try {
    $pdo = Database::getInstance();
    
    // 1. Companies (Tenants)
    $sql = "CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        tax_id VARCHAR(50),
        currency_code VARCHAR(3) DEFAULT 'USD',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created 'companies' table.</li>";

    // 2. Branches
    $sql = "CREATE TABLE IF NOT EXISTS branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        address TEXT,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created 'branches' table.</li>";

    // 3. Roles
    $sql = "CREATE TABLE IF NOT EXISTS erp_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        permissions JSON,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    $pdo->exec($sql); // Prefixing to avoid clash with existing 'roles' if any
    echo "<li>Created 'erp_roles' table.</li>";

    // 4. Users
    // Check if 'erp_users' exists to avoid clash with main 'users' table or decide to use main users
    // For this 'Ultimate ERP', let's create a dedicated table or link to existing
    // We will use 'erp_users' to contain ERP specific logic or link to main users
    $sql = "CREATE TABLE IF NOT EXISTS erp_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        branch_id INT,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100),
        role_id INT,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        FOREIGN KEY (role_id) REFERENCES erp_roles(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created 'erp_users' table.</li>";

    // Seed Default Company & Admin
    $stmt = $pdo->query("SELECT COUNT(*) FROM companies");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO companies (name, currency_code) VALUES ('Default Company', 'USD')");
        $companyId = $pdo->lastInsertId();
        
        $pdo->exec("INSERT INTO branches (company_id, name) VALUES ($companyId, 'Main Branch')");
        $branchId = $pdo->lastInsertId();

        $pdo->exec("INSERT INTO erp_roles (company_id, name, permissions) VALUES ($companyId, 'Admin', '{\"all\":true}')");
        $roleId = $pdo->lastInsertId();

        $pass = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO erp_users (company_id, branch_id, email, password_hash, full_name, role_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$companyId, $branchId, 'admin@clouderp.com', $pass, 'System Admin', $roleId]);
            
        echo "<li style='color:green'>Seeded Default Data: User 'admin@clouderp.com' / 'admin123'</li>";
    }

    echo "<h3>Installation Complete!</h3>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
