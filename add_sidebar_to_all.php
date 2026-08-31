<?php
// Script to add sidebar to all ERP pages
$erpDir = 'c:/xampp/htdocs/erp';
$excludeDirs = ['includes', 'api'];

function addSidebarToFile($filePath) {
    $content = file_get_contents($filePath);
    
    // Check if sidebar is already included
    if (strpos($content, "include '../includes/sidebar.php'") !== false || 
        strpos($content, 'include "../includes/sidebar.php"') !== false ||
        strpos($content, "include 'includes/sidebar.php'") !== false) {
        return "Already has sidebar";
    }
    
    // Determine the correct path based on directory depth
    $relativePath = str_replace($GLOBALS['erpDir'], '', dirname($filePath));
    $depth = substr_count($relativePath, '/') + substr_count($relativePath, '\\');
    
    if ($depth == 0) {
        $includePath = "includes/sidebar.php";
    } else {
        $includePath = "../includes/sidebar.php";
    }
    
    // Find <body> tag and add sidebar include after it
    $pattern = '/(<body[^>]*>)/i';
    if (preg_match($pattern, $content, $matches)) {
        $replacement = $matches[1] . "\n<?php include '$includePath'; ?>\n";
        $newContent = preg_replace($pattern, $replacement, $content, 1);
        
        file_put_contents($filePath, $newContent);
        return "Added sidebar";
    }
    
    return "No <body> tag found";
}

// Get all PHP files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($erpDir)
);

$results = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        
        // Skip excluded directories
        $skip = false;
        foreach ($excludeDirs as $excludeDir) {
            if (strpos($path, "/$excludeDir/") !== false || strpos($path, "\\$excludeDir\\") !== false) {
                $skip = true;
                break;
            }
        }
        
        if (!$skip) {
            $result = addSidebarToFile($path);
            $results[] = [
                'file' => str_replace($erpDir . '/', '', $path),
                'status' => $result
            ];
        }
    }
}

// Output results
echo "<h1>Sidebar Addition Results</h1>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>File</th><th>Status</th></tr>";
foreach ($results as $result) {
    $color = $result['status'] == 'Added sidebar' ? 'green' : ($result['status'] == 'Already has sidebar' ? 'blue' : 'orange');
    echo "<tr><td>{$result['file']}</td><td style='color: $color'>{$result['status']}</td></tr>";
}
echo "</table>";
echo "<p><strong>Total files processed:</strong> " . count($results) . "</p>";
?>
