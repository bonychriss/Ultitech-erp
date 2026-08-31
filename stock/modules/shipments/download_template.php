<?php
// download_template.php
// Force download of a CSV template for shipments import

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="shipments_import_template.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// 1. Headers
$headers = [
    'Supplier', 
    'Contact', 
    'Invoice Number', 
    'Track', 
    'Pkgs', 
    'CBM', 
    'Value', 
    'Desc', 
    'Shipment Date', 
    'Shipper', 
    'ECC', 
    'ETD', 
    'ETA', 
    'Status'
];
fputcsv($output, $headers);

// 2. Sample Data (Optional, but helpful)
$sample_row = [
    'Global Suppliers Ltd',     // Supplier
    '0086123456789',            // Contact
    'INV-2025-001',             // Invoice
    'TRK123456789',             // Track
    '50',                       // Pkgs
    '2.500',                    // CBM
    '15000.00',                 // Value
    'FACE MASKS N95',           // Desc
    '2025-01-15',               // Date
    'DHL Global',               // Shipper
    'ECC-2025-ABCD',            // ECC
    '2025-01-20',               // ETD
    '2025-02-15',               // ETA
    'In Transit'                // Status
];
fputcsv($output, $sample_row);

fclose($output);
exit;
