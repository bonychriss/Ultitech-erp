<?php
// Standalone installer without app dependencies to avoid auth/redirect issues
$db_file = __DIR__ . '/../../env.local.php';
if (file_exists($db_file)) {
    include $db_file;
} else {
    // Fallback to env.php
    include __DIR__ . '/../../includes/env.php';
}

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sqlFile = __DIR__ . '/setup_payroll.sql';
    $sql = file_get_contents($sqlFile);
    
    $pdo->exec($sql);
    echo "Payroll tables created successfully!";
} catch (PDOException $e) {
    // Try XAMPP default fallback
    try {
        echo "Default credentials failed (" . $e->getMessage() . "). Trying XAMPP defaults (root/empty)...\n";
        $pdo = new PDO("mysql:host=localhost;dbname=$DB_NAME;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $sqlFile = __DIR__ . '/setup_payroll.sql';
        $sql = file_get_contents($sqlFile);
        
        $pdo->exec($sql);
        echo "Payroll tables created successfully using XAMPP defaults!";
    } catch (PDOException $e2) {
        // Try 'staff' database name as last resort
        try {
            echo "Failed with DB name $DB_NAME. Trying 'staff'...\n";
            $pdo = new PDO("mysql:host=localhost;dbname=staff;charset=utf8mb4", "root", "");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $sqlFile = __DIR__ . '/setup_payroll.sql';
            $sql = file_get_contents($sqlFile);
            
            $pdo->exec($sql);
            echo "Payroll tables created successfully using 'staff' database!";
        } catch (PDOException $e3) {
            echo "All connection attempts failed. Original error: " . $e->getMessage();
        }
    }
}
?>
