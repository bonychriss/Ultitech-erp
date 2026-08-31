<?php
// Mock PHP environment for testing ai_assistant.php redirect with admin role
session_start();

$_SESSION['user_id'] = 1; // System Admin
$_SESSION['role'] = 'admin';
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';
$_SESSION['company_name'] = 'Ultimate General Trading';
$_SESSION['full_name'] = 'System Admin';
$_SESSION['department'] = 'Management';

$_GET = [
    'company_slug' => 'ultimate',
    'module' => 'voucher'
];

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/ultimate/employee/ai_assistant.php?module=voucher';
$_SERVER['SCRIPT_NAME'] = '/employee/ai_assistant.php';
$_SERVER['PHP_SELF'] = '/employee/ai_assistant.php';
$_SERVER['HTTP_HOST'] = 'localhost';

ob_start();

try {
    chdir(__DIR__ . '/employee');
    include 'ai_assistant.php';
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    $libraries = [
        'FPDF' => 'FPDF',
        'TCPDF' => 'TCPDF',
        'Dompdf' => 'Dompdf\Dompdf',
        'mPDF' => 'Mpdf\Mpdf'
    ];

    foreach ($libraries as $name => $class) {
        if (class_exists($class)) {
            echo "Library $name is AVAILABLE!\n";
        } else {
            echo "Library $name is NOT available.\n";
        }
    }
    echo $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();
$headers = headers_list();

echo "=== HEADERS SENT ===\n";
print_r($headers);
echo "\n=== OUTPUT (FIRST 200 CHARS) ===\n";
echo substr($output, 0, 200) . "...\n";
