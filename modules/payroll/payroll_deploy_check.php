<?php
/**
 * Payroll Deployment & Debug Tool
 * Run this script on your live server to ensure the database is ready.
 * IMPORTANT: Delete this file after successful deployment.
 */
require_once __DIR__ . '/config/database.php';

// Simple security check - requires admin session
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied: You must be logged in as an Admin to run this script.");
}

echo "<h2>Payroll System - Deployment Debugger</h2>";
echo "<ul style='font-family: monospace;'>";

try {
    // 1. Fix status column lengths (prevents truncation errors)
    echo "<li>Ensuring status column length... ";
    $pdo->exec('ALTER TABLE ' . payroll_table('payroll_runs') . " MODIFY COLUMN status VARCHAR(20) DEFAULT 'draft'");
    $pdo->exec('ALTER TABLE ' . payroll_table('payslips') . " MODIFY COLUMN status VARCHAR(20) DEFAULT 'generated'");
    echo "<span style='color: green;'>OK (Expanded to 20 chars)</span></li>";

    // 2. Check for is_published columns
    $tables_to_check = [
        'payroll_runs' => 'is_published',
        'payslips'     => 'is_published'
    ];

    foreach ($tables_to_check as $table => $column) {
        echo "<li>Checking table <strong>$table</strong>... ";
        
        $check = $pdo->query('SHOW COLUMNS FROM ' . payroll_table($table) . " LIKE '$column'");
        $exists = $check->fetch();

        if ($exists) {
            echo "<span style='color: green;'>OK (Column '$column' exists)</span>";
        } else {
            echo "<span style='color: orange;'>MISSING</span>. Attempting to fix... ";
            $pdo->exec('ALTER TABLE ' . payroll_table($table) . " ADD COLUMN `$column` TINYINT(1) DEFAULT 0");
            echo "<span style='color: green;'>FIXED (Column added)</span>";
        }
        echo "</li>";
    }

} catch (Exception $e) {
    echo "<li style='color: red;'>CRITICAL ERROR: " . htmlspecialchars($e->getMessage()) . "</li>";
}

echo "</ul>";
echo "<hr>";
echo "<p style='color: blue; font-weight: bold;'>Status: System is ready for the new Payroll Workflow.</p>";
echo "<p style='color: red;'><strong>SECURITY WARNING:</strong> Please delete this file (<code>payroll_deploy_check.php</code>) from your server immediately.</p>";
?>
