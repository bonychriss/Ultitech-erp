<?php
// diagnose_access.php
// Place in: staff/stock/modules/products/diagnose_access.php

echo "<style>body{font-family:sans-serif;}</style>";
echo "<h1>Deep Access Check</h1>";

// Target a known existing file based on previous run
// ID: 6, File: 695bd49948848.jpg
$targetId = 6;
$targetImg = '695bd49948848.jpg';

$baseDir = realpath(__DIR__ . '/../../uploads/products');
$targetFile = $baseDir . "/$targetId/thumbnail/$targetImg";

echo "<h3>Targeting File:</h3>";
echo "<code>$targetFile</code><br>";

if (!file_exists($targetFile)) {
    die("<h2 style='color:red;'>Target file not found. Please pick a valid ID/Image from the previous script.</h2>");
}

echo "<h3>1. Directory Permissions Scan</h3>";
echo "<table border='1' cellpadding='5'><tr><th>Path</th><th>Perms</th><th>Readable?</th><th>Executable?</th></tr>";

$parts = explode('/', str_replace('\\', '/', $targetFile));
$currentPath = '';
foreach ($parts as $part) {
    // Reconstruct path for Linux compliance
    if (empty($part)) { $currentPath = '/'; continue; } 
    $currentPath .= $part . '/';
    
    // Only check from the 'public_html' or relevant depth downwards to avoid spamming root
    if (strpos($currentPath, 'staff') === false) continue;
    
    $cleanPath = rtrim($currentPath, '/');
    if (!file_exists($cleanPath)) continue;
    
    $perms = substr(sprintf('%o', fileperms($cleanPath)), -4);
    $r = is_readable($cleanPath) ? 'YES' : 'NO';
    $x = is_executable($cleanPath) ? 'YES' : 'NO'; // Directory needs +x to be traversable
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($part) . "</td>";
    echo "<td>$perms</td>";
    echo "<td>$r</td>";
    echo "<td>$x</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>2. .htaccess Check</h3>";
// Check for .htaccess in the chain
$checkPath = $targetFile;
while(dirname($checkPath) != dirname(dirname(dirname(__DIR__)))) { // Stop before root
    $checkPath = dirname($checkPath);
    $ht = $checkPath . '/.htaccess';
    if (file_exists($ht)) {
        echo "<p style='color:orange;'>Found .htaccess in: <strong>$checkPath</strong></p>";
        echo "<pre style='background:#f4f4f4;padding:10px;'>" . htmlspecialchars(file_get_contents($ht)) . "</pre>";
    }
}

echo "<h3>3. Direct Read Test</h3>";
echo "<p>Attempting to read file via PHP <code>readfile()</code>. If you see the image code below, permissions are OK for PHP, meaning Apache/Nginx is blocking it.</p>";

$mime = mime_content_type($targetFile);
echo "<p>Detected MIME: $mime</p>";
echo "<p><a href='?serve=1'>Click here to attempt forced download/view via PHP</a></p>";

if (isset($_GET['serve'])) {
    header('Content-Type: ' . $mime);
    readfile($targetFile);
    exit;
}
?>
