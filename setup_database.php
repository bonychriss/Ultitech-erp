<?php
// Database setup script
try {
    // First connect without database to create it
    $pdo = new PDO("mysql:host=localhost", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL server successfully.<br>";
    
    // Read and execute the SQL file
    $sql = file_get_contents('database_setup.sql');
    
    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "<h2>Database setup completed successfully!</h2>";
    echo "<p>Database 'ultimate_trading_voucher' has been created with all tables and sample data.</p>";
    echo "<p><strong>Admin Login:</strong> username: admin, password: admin123</p>";
    echo "<p><a href='index.php'>Go to Main Page</a></p>";
    
} catch(PDOException $e) {
    echo "Database setup failed: " . $e->getMessage();
}
?>