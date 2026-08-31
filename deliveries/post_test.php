<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $len = strlen(serialize($_POST));
    echo "<h1>POST SUCCESS</h1>";
    echo "Received data length: " . $len . " bytes<br>";
    echo "Files received: " . count($_FILES) . "<br>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<body>
    <h1>POST Diagnostic Test</h1>
    <form method="POST">
        <textarea name="test_data" rows="10" cols="50">Enter some large text here to test firewall limits...</textarea><br>
        <button type="submit">Test POST</button>
    </form>
</body>
</html>
