<?php
$_GET['company_slug'] = 'ultimate';
$_SERVER['REQUEST_URI'] = '/ultimate/weekly_tasks/ai_assistant.php';
$_SERVER['SCRIPT_NAME'] = '/ultimate/weekly_tasks/ai_assistant.php';
require dirname(__DIR__) . '/includes/functions.php';
global $pdo;
echo "notes with order\n";
echo $pdo->query('SELECT COUNT(*) FROM delivery_notes WHERE order_id IS NOT NULL AND order_id > 0')->fetchColumn() . PHP_EOL;
echo "day gaps note vs order\n";
$sql = "SELECT so.created_by, so.order_date, dn.delivery_date, DATEDIFF(dn.delivery_date, so.order_date) days
        FROM delivery_notes dn
        JOIN sales_orders so ON so.id = dn.order_id
        ORDER BY dn.delivery_date DESC LIMIT 15";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) echo json_encode($row) . PHP_EOL;
echo "delivery orders linked to invoices\n";
echo $pdo->query('SELECT COUNT(*) FROM delivery_orders WHERE sales_invoice_id IS NOT NULL AND sales_invoice_id > 0')->fetchColumn() . PHP_EOL;
$sql = "SELECT i.created_by, o.created_at, o.delivery_deadline, o.completion_time, o.status
        FROM delivery_orders o
        JOIN invoices i ON i.id = o.sales_invoice_id
        ORDER BY o.created_at DESC LIMIT 10";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) echo json_encode($row) . PHP_EOL;
echo "lead_time on invoiced\n";
foreach ($pdo->query("SELECT lead_time, COUNT(*) c FROM sales_orders WHERE status IN ('invoiced','paid','shipped') GROUP BY lead_time ORDER BY c DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . PHP_EOL;
}
