<?php
require_once '../../includes/functions.php';

global $pdo;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'update_company') {
        // Handle logo upload
        $logoPath = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../uploads/company/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '.' . $ext;
            $logoPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath)) {
                $logoPath = 'uploads/company/' . $filename;
            }
        }
        
        $settings = [
            'company_name' => $_POST['company_name'],
            'company_address' => $_POST['company_address'],
            'company_phone' => $_POST['company_phone'],
            'company_email' => $_POST['company_email'],
            'company_vrn' => $_POST['company_vrn'] ?? '',
            'company_tin' => $_POST['company_tin'] ?? ''
        ];
        
        if ($logoPath) {
            $settings['company_logo'] = $logoPath;
        }
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO erp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'update_system') {
        $settings = [
            'currency' => $_POST['currency'],
            'timezone' => $_POST['timezone'],
            'date_format' => $_POST['date_format'],
            'tax_enabled' => $_POST['tax_enabled'],
            'default_tax_rate' => $_POST['default_tax_rate']
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO erp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'update_numbering') {
        $settings = [
            'invoice_prefix' => $_POST['invoice_prefix'],
            'invoice_next_number' => $_POST['invoice_next_number'],
            'quote_prefix' => $_POST['quote_prefix'],
            'quote_next_number' => $_POST['quote_next_number'],
            'po_prefix' => $_POST['po_prefix'],
            'po_next_number' => $_POST['po_next_number']
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO erp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'add_tax_rate') {
        $stmt = $pdo->prepare("INSERT INTO erp_tax_rates (name, rate, type, is_default) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'],
            $_POST['rate'],
            $_POST['type'],
            $_POST['is_default'] ?? 0
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
    
    elseif ($action === 'update_role') {
        $stmt = $pdo->prepare("UPDATE erp_user_roles SET permissions = ? WHERE id = ?");
        $stmt->execute([
            json_encode($_POST['permissions']),
            $_POST['role_id']
        ]);
        
        echo json_encode(['success' => true]);
    }

    // --- Payroll Settings ---
    elseif ($action === 'add_payroll_rule') {
        $stmt = $pdo->prepare("INSERT INTO erp_payroll_settings (name, value, is_percentage, type, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'],
            $_POST['value'],
            $_POST['is_percentage'],
            $_POST['type'],
            1 // Default active
        ]);
        echo json_encode(['success' => true]);
    }

    elseif ($action === 'update_payroll_rule') {
        $stmt = $pdo->prepare("UPDATE erp_payroll_settings SET name = ?, value = ?, is_percentage = ?, type = ?, is_active = ? WHERE id = ?");
        $stmt->execute([
            $_POST['name'],
            $_POST['value'],
            $_POST['is_percentage'],
            $_POST['type'],
            $_POST['is_active'],
            $_POST['id']
        ]);
        echo json_encode(['success' => true]);
    }

    elseif ($action === 'delete_payroll_rule') {
        $stmt = $pdo->prepare("DELETE FROM erp_payroll_settings WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'get_payroll_rules') {
        $stmt = $pdo->query("SELECT * FROM erp_payroll_settings ORDER BY created_at DESC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // --- PAYE Tax Bands ---
    elseif ($action === 'add_tax_band') {
        $stmt = $pdo->prepare("INSERT INTO payroll_tax_bands (min_salary, max_salary, tax_rate, offset_amount, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['min_salary'],
            $_POST['max_salary'] === '' ? null : $_POST['max_salary'],
            $_POST['tax_rate'],
            $_POST['offset_amount'],
            $_POST['is_active'] ?? 1
        ]);
        echo json_encode(['success' => true]);
    }
    elseif ($action === 'update_tax_band') {
        $stmt = $pdo->prepare("UPDATE payroll_tax_bands SET min_salary = ?, max_salary = ?, tax_rate = ?, offset_amount = ?, is_active = ? WHERE id = ?");
        $stmt->execute([
            $_POST['min_salary'],
            $_POST['max_salary'] === '' ? null : $_POST['max_salary'],
            $_POST['tax_rate'],
            $_POST['offset_amount'],
            $_POST['is_active'] ?? 1,
            $_POST['id']
        ]);
        echo json_encode(['success' => true]);
    }
    elseif ($action === 'delete_tax_band') {
        $stmt = $pdo->prepare("DELETE FROM payroll_tax_bands WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
    }
    elseif ($action === 'get_tax_bands') {
        $stmt = $pdo->query("SELECT * FROM payroll_tax_bands ORDER BY min_salary ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
