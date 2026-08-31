<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';


header('Content-Type: application/json');

if (session_status() == PHP_SESSION_NONE)
    session_start();

$company_id = (int) (currentCompanyId() ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

// Ensure schema is up-to-date (Migration)
function syncSchema($pdo)
{
    try {
        $stmt = $pdo->query("DESCRIBE sales_settings");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = [];
        if (!in_array('company_id', $columns))
            $missing[] = "ADD COLUMN company_id INT DEFAULT 0";
        if (!in_array('spare_part_layout', $columns))
            $missing[] = "ADD COLUMN spare_part_layout INT DEFAULT 1";
        if (!in_array('truck_layout', $columns))
            $missing[] = "ADD COLUMN truck_layout INT DEFAULT 1";
        if (!in_array('truck_footer', $columns))
            $missing[] = "ADD COLUMN truck_footer TEXT";
        if (!in_array('spare_footer', $columns))
            $missing[] = "ADD COLUMN spare_footer TEXT";

        // Categorized Truck Footer
        if (!in_array('truck_payment_details', $columns))
            $missing[] = "ADD COLUMN truck_payment_details TEXT";
        if (!in_array('truck_terms', $columns))
            $missing[] = "ADD COLUMN truck_terms TEXT";
        if (!in_array('truck_validity', $columns))
            $missing[] = "ADD COLUMN truck_validity TEXT";
        if (!in_array('truck_thanks_note', $columns))
            $missing[] = "ADD COLUMN truck_thanks_note TEXT";
        if (!in_array('truck_return_policy', $columns))
            $missing[] = "ADD COLUMN truck_return_policy TEXT";

        // Categorized Spare Footer
        if (!in_array('spare_payment_details', $columns))
            $missing[] = "ADD COLUMN spare_payment_details TEXT";
        if (!in_array('spare_terms', $columns))
            $missing[] = "ADD COLUMN spare_terms TEXT";
        if (!in_array('spare_validity', $columns))
            $missing[] = "ADD COLUMN spare_validity TEXT";
        if (!in_array('spare_thanks_note', $columns))
            $missing[] = "ADD COLUMN spare_thanks_note TEXT";
        if (!in_array('spare_return_policy', $columns))
            $missing[] = "ADD COLUMN spare_return_policy TEXT";
        if (!in_array('document_footer_message', $columns))
            $missing[] = "ADD COLUMN document_footer_message TEXT NULL";
        if (!in_array('truck_remarks', $columns))
            $missing[] = "ADD COLUMN truck_remarks TEXT NULL";
        if (!in_array('enable_tax_inclusive', $columns))
            $missing[] = "ADD COLUMN enable_tax_inclusive TINYINT(1) DEFAULT 0";
        if (!in_array('enable_tax_exclusive', $columns))
            $missing[] = "ADD COLUMN enable_tax_exclusive TINYINT(1) DEFAULT 1";

        if (!empty($missing)) {
            $sql = "ALTER TABLE sales_settings " . implode(', ', $missing);
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        // Silently fail or log if table doesn't exist yet
    }
}

function syncProductsSchema($pdo)
{
    try {
        $stmt = $pdo->query("DESCRIBE products");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = [];
        $needed = [
            'vin' => "ADD COLUMN vin VARCHAR(255) NULL",
            'chassis_number' => "ADD COLUMN chassis_number VARCHAR(255) NULL",
            'engine_number' => "ADD COLUMN engine_number VARCHAR(255) NULL",
            'truck_type' => "ADD COLUMN truck_type VARCHAR(255) NULL",
            'model_number' => "ADD COLUMN model_number VARCHAR(255) NULL",
            'model_year' => "ADD COLUMN model_year VARCHAR(10) NULL",
            'engine_model' => "ADD COLUMN engine_model VARCHAR(255) NULL",
            'transmission_model' => "ADD COLUMN transmission_model VARCHAR(255) NULL",
            'item_type' => "ADD COLUMN item_type VARCHAR(50) DEFAULT 'spare'"
        ];

        foreach ($needed as $col => $sql) {
            if (!in_array($col, $columns)) {
                $missing[] = $sql;
            }
        }

        if (!empty($missing)) {
            $sql = "ALTER TABLE products " . implode(', ', $missing);
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        // Silently fail
    }
}

syncSchema($pdo);
syncProductsSchema($pdo);


if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$company_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Fallback defaults
        $settings = [
            'company_name' => 'Ultimate General Trading Company',
            'company_address' => 'Mikocheni B, Dar es salaam Tanzania',
            'company_logo' => 'Untitled.jpg',
            'company_phone' => '',
            'company_email' => '',
            'company_website' => '',
            'company_tin' => '',
            'company_vat' => '',
            'bank_details' => '',
            'default_currency' => 'TZS',
            'invoice_remarks' => '',
            'document_footer_message' => '',
            'spare_part_layout' => 1,
            'truck_layout' => 1,
            'truck_payment_details' => 'Payment details',
            'truck_terms' => 'Terms and Conditions..',
            'truck_validity' => 'Invoice is valid for 10 days',
            'truck_thanks_note' => 'Thank you for your business',
            'truck_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
            'spare_payment_details' => 'Payment details',
            'spare_terms' => 'Terms and Conditions..',
            'spare_validity' => 'Invoice is valid for 10 days',
            'spare_thanks_note' => 'Thank you for your business',
            'spare_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
            'enable_tax_inclusive' => 0,
            'enable_tax_exclusive' => 1
        ];
    }
    echo json_encode($settings);
    exit();
}

if ($method === 'POST') {
    // Define allowed fields to map between POST and DB columns
    $field_map = [
        'company_name' => 'company_name',
        'company_address' => 'company_address',
        'company_tin' => 'company_tin',
        'company_vat' => 'company_vat',
        'company_phone' => 'company_phone',
        'company_email' => 'company_email',
        'company_website' => 'company_website',
        'bank_details' => 'bank_details',
        'default_currency' => 'default_currency',
        'invoice_remarks' => 'invoice_remarks',
        'document_footer_message' => 'document_footer_message',
        'spare_part_layout' => 'spare_part_layout',
        'truck_layout' => 'truck_layout',
        'truck_payment_details' => 'truck_payment_details',
        'truck_terms' => 'truck_terms',
        'truck_validity' => 'truck_validity',
        'truck_thanks_note' => 'truck_thanks_note',
        'truck_return_policy' => 'truck_return_policy',
        'spare_payment_details' => 'spare_payment_details',
        'spare_terms' => 'spare_terms',
        'spare_validity' => 'spare_validity',
        'spare_thanks_note' => 'spare_thanks_note',
        'spare_return_policy' => 'spare_return_policy',
        'truck_remarks' => 'truck_remarks',
        'enable_tax_inclusive' => 'enable_tax_inclusive',
        'enable_tax_exclusive' => 'enable_tax_exclusive'
    ];

    $update_parts = [];
    $params = [];

    // Build dynamic update parts
    foreach ($field_map as $post_key => $db_col) {
        if (isset($_POST[$post_key])) {
            $update_parts[] = "$db_col = ?";
            $params[] = $_POST[$post_key];
        }
    }

    // Handle Logo Upload separately
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['company_logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $new_filename = 'company_logo_' . time() . '.' . $ext;
            $upload_path = '../../../assets/images/' . $new_filename;

            if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_path)) {
                $update_parts[] = "company_logo = ?";
                $params[] = $new_filename;
            }
        }
    }

    if (empty($update_parts)) {
        echo json_encode(['success' => true, 'message' => 'No changes provided']);
        exit();
    }

    try {
        // Check if settings exist for this company
        $check = $pdo->prepare("SELECT id FROM sales_settings WHERE company_id = ?");
        $check->execute([$company_id]);
        $existing = $check->fetch();

        if ($existing) {
            $sql = "UPDATE sales_settings SET " . implode(', ', $update_parts) . " WHERE company_id = ?";
            $params[] = $company_id;
            $stmt = $pdo->prepare($sql);
        } else {
            // INSERT first with defaults
            $cols = array_values($field_map);
            $cols[] = 'company_id';
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            
            // Re-build params for INSERT
            $insert_params = [];
            foreach ($field_map as $pk => $dc) {
                $insert_params[] = $_POST[$pk] ?? null;
            }
            $insert_params[] = $company_id;

            $sql = "INSERT INTO sales_settings (" . implode(', ', $cols) . ") VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $params = $insert_params;
        }

        if ($stmt->execute($params)) {
            echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
        } else {
            $err = $stmt->errorInfo();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . ($err[2] ?? 'Unknown error')]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>
