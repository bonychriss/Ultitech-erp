<?php
$file = 'c:/xampp/htdocs/error_log';
if (!file_exists($file)) {
    die("Error log not found.");
}

$lines = file($file);
$count = count($lines);
$start = max(0, $count - 50);

for ($i = $start; $i < $count; $i++) {
    echo $lines[$i];
}
?>
