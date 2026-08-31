<?php
// Database setup script
// Run this script once to create the database and tables

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'ultimate_trading_voucher';

try {
    // Connect to MySQL server (without database)
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Setting up Ultimate General Trading Payment Voucher System</h2>";
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    echo "<p>✓ Database '$dbname' created successfully</p>";
    
    // Use the database
    $pdo->exec("USE $dbname");
    
    // Read and execute SQL file
    $sql = file_get_contents('database_setup.sql');
    
    // Split SQL file into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "<p>✓ Database tables created successfully</p>";
    echo "<p>✓ Default users created:</p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username: admin, password: admin123</li>";
    echo "<li><strong>Employee:</strong> username: maureen, password: password123</li>";
    echo "<li><strong>Employee:</strong> username: saida, password: password123</li>";
    echo "<li><strong>Admin:</strong> username: rajab, password: password123</li>";
    echo "<li><strong>Employee:</strong> username: mase, password: password123</li>";
    echo "</ul>";
    
    echo "<h3>Setup Complete!</h3>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please make sure MySQL is running and the credentials are correct.</p>";
}
?>