<?php
// stock/modules/products/read_log.php
$file = 'debug_upload_edit.log';
if (file_exists($file)) {
    echo "<h1>Debug Log Content</h1>";
    echo "<pre>" . htmlspecialchars(file_get_contents($file)) . "</pre>";
} else {
    echo "Log file $file does not exist.";
}
?>
