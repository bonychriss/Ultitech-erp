<?php
/**
 * Script to remove requireLogin() from all ERP PHP files
 * Run this script once by accessing it in your browser
 */

// Set execution time limit
set_time_limit(300);

// Directory to scan
$erpDir = __DIR__;

// Counter for updated files
$updatedCount = 0;
$errors = [];

// Function to recursively scan directory
function scanDirectory($dir, &$updatedCount, &$errors) {
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        // If it's a directory, scan recursively
        if (is_dir($path)) {
            scanDirectory($path, $updatedCount, $errors);
            continue;
        }
        
        // Only process PHP files
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;
        
        // Skip this script itself
        if (basename($path) === 'remove-login.php') continue;
        
        try {
            // Read file content
            $content = file_get_contents($path);
            
            // Check if it contains requireLogin()
            if (strpos($content, 'requireLogin();') !== false) {
                // Remove requireLogin(); and any trailing newline
                $newContent = preg_replace('/requireLogin\(\);\r?\n?/', '', $content);
                
                // Write back to file
                if (file_put_contents($path, $newContent) !== false) {
                    $updatedCount++;
                    echo "✓ Updated: " . str_replace(__DIR__, '', $path) . "<br>\n";
                } else {
                    $errors[] = "Failed to write: " . $path;
                }
            }
        } catch (Exception $e) {
            $errors[] = "Error processing $path: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remove Login Requirement - ERP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #202124;
            margin-bottom: 20px;
            font-size: 1.75rem;
        }
        .info {
            background: #e8f0fe;
            border-left: 4px solid #1a73e8;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .success {
            background: #e6f4ea;
            border-left: 4px solid #137333;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error {
            background: #fce8e6;
            border-left: 4px solid #c5221f;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .results {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .btn {
            background: #1a73e8;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #1557b0;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }
        .stat {
            flex: 1;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #1a73e8;
        }
        .stat-label {
            color: #5f6368;
            font-size: 0.875rem;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔓 Remove Login Requirement from ERP</h1>
        
        <?php if (!isset($_GET['run'])): ?>
            <div class="info">
                <strong>⚠️ Warning:</strong> This script will remove all <code>requireLogin();</code> calls from PHP files in the ERP directory, making the system accessible without authentication.
            </div>
            
            <p style="margin: 20px 0;">Click the button below to proceed:</p>
            
            <a href="?run=1" class="btn">Remove Login Requirement</a>
            
        <?php else: ?>
            <div class="info">
                <strong>Processing...</strong> Scanning and updating files...
            </div>
            
            <div class="results">
                <?php
                // Start processing
                $startTime = microtime(true);
                scanDirectory($erpDir, $updatedCount, $errors);
                $endTime = microtime(true);
                $executionTime = round($endTime - $startTime, 2);
                ?>
            </div>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= $updatedCount ?></div>
                    <div class="stat-label">Files Updated</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= count($errors) ?></div>
                    <div class="stat-label">Errors</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $executionTime ?>s</div>
                    <div class="stat-label">Execution Time</div>
                </div>
            </div>
            
            <?php if ($updatedCount > 0): ?>
                <div class="success">
                    <strong>✓ Success!</strong> Successfully removed login requirement from <?= $updatedCount ?> file(s).
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <strong>⚠️ Errors:</strong>
                    <ul style="margin-top: 10px; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <a href="../index.php" class="btn">Go to ERP Dashboard</a>
            </div>
            
            <div class="info" style="margin-top: 20px;">
                <strong>💡 Tip:</strong> You can now delete this script file (<code>remove-login.php</code>) as it's no longer needed.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
