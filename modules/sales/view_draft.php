<?php
require_once '../../../includes/config.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

$id = $_GET['id'] ?? 0;

// Fetch Invoice with Customer
$sql = "SELECT i.*, c.company_name as customer_name, c.address as customer_address, 
        c.email as customer_email, c.phone as customer_phone, c.tax_number as customer_tax_id 
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die("Invoice not found.");
}

// Fetch Items with Images
$sqlItems = "SELECT ii.*, p.name as product_name, p.product_code, p.main_image 
             FROM invoice_items ii 
             LEFT JOIN products p ON ii.product_id = p.id 
             WHERE ii.invoice_id = ?";
// Fallback if invoice_items doesn't exist yet but logic is sound, 
// OR checking if it's sales_order_items logic reused? 
// The schema showed `invoices` table but didn't show `invoice_items` explicitly in my last check?
// Wait, let's double check setup_sales_module.sql for invoice_items...
// Ah, the setup file I read earlier (Step 677) does NOT show `invoice_items`.
// It shows `invoices`, but where are items?
// Line 78 is `sales_order_items`. 
// Line 183 is `delivery_items`. 
// I DON'T SEE `invoice_items` in setup_sales_module.sql!
// The user might be expecting invoices to just link to orders?
// BUT real invoices need line items.
// Let's assume for now invoices might pull from sales_order_items via order_id if they don't have their own items table?
// OR I should check if `invoice_items` exists in the DB.

// Let's pause writing and check DB first.
?>
