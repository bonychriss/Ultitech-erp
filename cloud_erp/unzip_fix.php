<?php
// unzip_fix.php
echo "<h1>PHP Unzip Utility</h1>";

$zipFile = __DIR__ . '/../cloud_erp_v1.zip';
$extractTo = __DIR__; // Extract into cloud_erp/

if (!file_exists($zipFile)) {
    die("Error: ZIP file not found at: $zipFile<br>Please ensure you uploaded cloud_erp_v1.zip to the parent 'staff' folder.");
}

$zip = new ZipArchive;
$res = $zip->open($zipFile);

if ($res === TRUE) {
    if (!is_writable($extractTo)) {
        die("Error: Cannot write to directory '$extractTo'.<br>Please set permissions of 'cloud_erp' folder to 777.");
    }
    
    $zip->extractTo($extractTo);
    $zip->close();
    echo "<h3 style='color:green'>Success! Files extracted.</h3>";
    echo "Check the 'modules/CRM' folder.";
} else {
    echo "Error: Failed to open ZIP file. Code: $res";
}
