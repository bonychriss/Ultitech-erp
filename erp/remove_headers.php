<?php
/**
 * Script to remove header cards from all ERP pages except dashboard
 * Upload this to your live server and run it once via browser
 */

// Security: Only allow execution from localhost or specific IP
// Comment out these lines if you need to run from anywhere
// if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== $_SERVER['SERVER_ADDR']) {
//     die('Access denied');
// }

$erpRoot = __DIR__;
$filesToProcess = [];

echo "<!DOCTYPE html><html><head><title>Remove Headers</title>";
echo "<style>body{font-family:sans-serif;padding:20px;max-width:800px;margin:0 auto;} .success{color:green;} .error{color:red;} pre{background:#f4f4f4;padding:10px;}</style>";
echo "</head><body>";
echo "<h1>ERP Header Removal Script</h1>";
echo "<p>This script will remove header cards from all ERP pages except the dashboard.</p>";

// Find all PHP files with headers (excluding index.php which is the dashboard)
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($erpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        
        // Skip dashboard (main index.php in erp root)
        if (basename($path) === 'index.php' && dirname($path) === $erpRoot) {
            continue;
        }
        
        // Skip includes, api, and this script itself
        if (strpos($path, 'includes') !== false || 
            strpos($path, 'api') !== false ||
            basename($path) === 'remove_headers.php') {
            continue;
        }
        
        $content = file_get_contents($path);
        
        // Check if file has a header div
        if (preg_match('/<div class="header">/', $content)) {
            $filesToProcess[] = $path;
        }
    }
}

echo "<p><strong>Found " . count($filesToProcess) . " files to process</strong></p>";
echo "<pre>";

$processed = 0;
$failed = 0;

foreach ($filesToProcess as $file) {
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // Pattern 1: Header with h1 and single button
    $content = preg_replace(
        '/<div class="header">\s*<h1>.*?<\/h1>\s*(<a href="[^"]*" class="btn[^"]*">.*?<\/a>)\s*<\/div>/',
        '<div style="padding: 16px 24px 0; text-align: right;">$1</div>',
        $content
    );
    
    // Pattern 2: Header with h1 and header-actions div
    $content = preg_replace(
        '/<div class="header">\s*<h1>.*?<\/h1>\s*(<div class="header-actions">.*?<\/div>)\s*<\/div>/s',
        '<div style="padding: 16px 24px 0; text-align: right;">$1</div>',
        $content
    );
    
    if ($content !== $originalContent) {
        if (file_put_contents($file, $content)) {
            $processed++;
            echo "<span class='success'>✓</span> " . str_replace($erpRoot, '', $file) . "\n";
        } else {
            $failed++;
            echo "<span class='error'>✗</span> " . str_replace($erpRoot, '', $file) . " (write failed)\n";
        }
    }
}

echo "</pre>";
echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p><strong class='success'>Processed: $processed files</strong></p>";
echo "<p><strong class='error'>Failed: $failed files</strong></p>";

if ($failed === 0 && $processed > 0) {
    echo "<p style='background:#d4edda;padding:15px;border:1px solid #c3e6cb;border-radius:4px;'>";
    echo "✅ <strong>Success!</strong> All headers have been removed. You can now delete this script file.";
    echo "</p>";
}

echo "</body></html>";
?>
