<?php
// diagnose_images.php
require_once '../../config/database.php';
require_once '../../config/functions.php';

echo "<style>body{font-family:sans-serif;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ccc;padding:8px;} .ok{background:#dfffdf;color:green;} .err{background:#ffeaea;color:red;}</style>";
echo "<h1>Image Diagnosis Tool - Web Check</h1>";

$uploadBaseRelative = '../../uploads/products';
$uploadBaseAbsolute = realpath(__DIR__ . '/../../uploads/products');

// Construct Base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['PHP_SELF']); // /staff/stock/modules/products
// resolve relative '..' in URL path is tricky in string, but browser handles it.
// Let's rely on the browser's resolving for the link, but for curl we need exact.
// Simple cleaner:
$baseParams = explode('/', trim($scriptDir, '/'));
array_pop($baseParams); // products
array_pop($baseParams); // modules
$stockBase = implode('/', $baseParams); // staff/stock
$urlBase = $protocol . $domainName . '/' . $stockBase . '/uploads/products';

echo "<h3>Path Info</h3>";
echo "<ul>";
echo "<li><strong>Physical Path:</strong> $uploadBaseAbsolute</li>";
echo "<li><strong>Calculated URL Base:</strong> $urlBase</li>";
echo "</ul>";

echo "<h3>Scanning Products</h3>";
try {
    $stmt = $pdo->query("SELECT id, name, main_image FROM products WHERE main_image IS NOT NULL AND main_image != '' LIMIT 10");
    $products = $stmt->fetchAll();
} catch (Exception $e) { 
    die("<p class='err'>Database Error: " . $e->getMessage() . "</p>"); 
}

echo "<table>";
echo "<thead><tr>
        <th>ID</th>
        <th>Image (DB)</th>
        <th>Physical File</th>
        <th>Web Link</th>
        <th>Test Image</th>
      </tr></thead><tbody>";

foreach ($products as $p) {
    $id = $p['id'];
    $img = $p['main_image'];
    
    $absPath = "{$uploadBaseAbsolute}/{$id}/thumbnail/{$img}";
    $webUrl = "{$urlBase}/{$id}/thumbnail/{$img}";
    $relPath = "{$uploadBaseRelative}/{$id}/thumbnail/{$img}"; // For img tag
    
    $exists = file_exists($absPath);
    $status = $exists ? 'YES' : 'NO';
    $color = $exists ? 'ok' : 'err';
    
    echo "<tr class='$color'>";
    echo "<td>$id</td>";
    echo "<td>$img</td>";
    echo "<td>" . ($exists ? '✅ Found' : '❌ Missing') . "<br><small>$absPath</small></td>";
    echo "<td><a href='$webUrl' target='_blank'>Click to Open</a><br><small>$webUrl</small></td>";
    echo "<td>";
    echo "<img src='$relPath' style='width:50px; height:50px; object-fit:cover; border:1px solid #000;' alt='img test'>";
    echo "</td>";
    echo "</tr>";
}
echo "</tbody></table>";

echo "<h3>Check for .htaccess block</h3>";
$htaccess = $uploadBaseAbsolute . '/.htaccess';
if (file_exists($htaccess)) {
    echo "<p class='err'><strong>WARNING:</strong> Found .htaccess in uploads directory: $htaccess</p>";
    echo "<pre>" . htmlspecialchars(file_get_contents($htaccess)) . "</pre>";
} else {
    echo "<p class='ok'>No .htaccess validation file found in uploads root.</p>";
}
?>
