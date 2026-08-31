<?php
// TEMPORARY DEBUG MODE - Add this to the TOP of erp/index.php on live server
// REMOVE AFTER FINDING THE ERROR!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo "DEBUG: Script started<br>";

require_once '../includes/functions.php';
echo "DEBUG: functions.php loaded<br>";

echo "DEBUG: Login check passed<br>";

global $pdo;
echo "DEBUG: PDO available<br>";

// Get statistics
try {
    $stats = [
        'customers' => $pdo->query("SELECT COUNT(*) FROM erp_customers")->fetchColumn(),
        'products' => $pdo->query("SELECT COUNT(*) FROM erp_products")->fetchColumn(),
        'invoices' => $pdo->query("SELECT COUNT(*) FROM erp_invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
        'revenue' => $pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
        'suppliers' => $pdo->query("SELECT COUNT(*) FROM erp_suppliers")->fetchColumn(),
        'pos' => $pdo->query("SELECT COUNT(*) FROM erp_purchase_orders WHERE MONTH(order_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
        'employees' => $pdo->query("SELECT COUNT(*) FROM erp_employees WHERE status = 'active'")->fetchColumn(),
    ];
    echo "DEBUG: Stats loaded successfully<br>";
} catch (Exception $e) {
    echo "DEBUG ERROR in stats: " . $e->getMessage() . "<br>";
    die();
}

// Recent invoices
try {
    $recentInvoices = $pdo->query("SELECT i.*, c.name as customer_name FROM erp_invoices i JOIN erp_customers c ON i.customer_id = c.id ORDER BY i.invoice_date DESC LIMIT 5")->fetchAll();
    echo "DEBUG: Recent invoices loaded<br>";
} catch (Exception $e) {
    echo "DEBUG ERROR in invoices: " . $e->getMessage() . "<br>";
    die();
}

// Recent POs
try {
    $recentPOs = $pdo->query("SELECT po.*, s.name as supplier_name FROM erp_purchase_orders po JOIN erp_suppliers s ON po.supplier_id = s.id ORDER BY po.order_date DESC LIMIT 5")->fetchAll();
    echo "DEBUG: Recent POs loaded<br>";
} catch (Exception $e) {
    echo "DEBUG ERROR in POs: " . $e->getMessage() . "<br>";
    die();
}

// Notifications (only if file exists)
if (file_exists('includes/notifications.php')) {
    echo "DEBUG: notifications.php found<br>";
    require_once 'includes/notifications.php';
    $unreadCount = get_unread_count($_SESSION['user_id'] ?? 0);
    $notifications = get_unread_notifications($_SESSION['user_id'] ?? 0);
    echo "DEBUG: Notifications loaded<br>";
} else {
    echo "DEBUG: notifications.php NOT found (this is OK)<br>";
    $unreadCount = 0;
    $notifications = [];
}

echo "DEBUG: All data loaded successfully! If you see this, the issue is in the HTML/CSS below.<br><br>";
echo "If the page stops here, check the HTML rendering.<br><br>";

// Continue with the rest of your index.php...
?>
