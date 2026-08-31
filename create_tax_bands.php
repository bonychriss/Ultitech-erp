<?php
require_once 'includes/config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_tax_bands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        min_salary DECIMAL(15,2) NOT NULL,
        max_salary DECIMAL(15,2) NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        offset_amount DECIMAL(15,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default Tanzania Tax Bands
    $count = $pdo->query("SELECT COUNT(*) FROM payroll_tax_bands")->fetchColumn();
    if ($count == 0) {
        $stmt = $pdo->prepare("INSERT INTO payroll_tax_bands (min_salary, max_salary, tax_rate, offset_amount) VALUES (?, ?, ?, ?)");
        $bands = [
            [0, 270000, 0, 0],
            [270001, 520000, 8, 0],
            [520001, 760000, 20, 20000],
            [760001, 1000000, 25, 68000],
            [1000001, NULL, 30, 128000]
        ];
        foreach ($bands as $b) $stmt->execute($b);
        echo "Table created and seeded with default TZ bands.";
    } else {
        echo "Table exists and has data.";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
