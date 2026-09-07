<?php
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/../includes/config.php';
echo 'control DB: ' . $control_pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'control has document_sequences: ' . (tableExists('document_sequences', $control_pdo) ? 'yes' : 'no') . "\n";

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/admin/all-vouchers.php';
// re-bootstrap not easy in same process - connect manually
$rm = $control_pdo->query("SELECT db_name FROM companies WHERE company_slug='roadmaster'")->fetchColumn();
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $rm . ';charset=utf8mb4';
$rmPdo = new PDO($dsn, DB_USER, DB_PASS);
echo "roadmaster has document_sequences: " . (tableExists('document_sequences', $rmPdo) ? 'yes' : 'no') . "\n";
if (tableExists('document_sequences', $rmPdo)) {
    foreach ($rmPdo->query('SELECT * FROM document_sequences')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo '  ' . json_encode($r) . "\n";
    }
}
$seqPdo = documentSequencesPdo($rmPdo);
echo 'documentSequencesPdo for roadmaster returns DB: ' . ($seqPdo ? $seqPdo->query('SELECT DATABASE()')->fetchColumn() : 'null') . "\n";
