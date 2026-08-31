<?php
$searchDir = __DIR__;
$patterns = ['4001', '4002', 'Sales Revenue', 'Product Sales'];

function searchFiles($dir, $patterns) {
    $it = new RecursiveDirectoryIterator($dir);
    foreach (new RecursiveIteratorIterator($it) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        foreach ($patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                echo "Found '{$pattern}' in: " . str_replace('\\', '/', $file->getPathname()) . "\n";
            }
        }
    }
}

searchFiles($searchDir, $patterns);

// End of search
