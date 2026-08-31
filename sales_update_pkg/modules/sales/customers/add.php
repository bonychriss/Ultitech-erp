<?php
require_once '../../../includes/config.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$_SESSION['active_module'] = 'sales';

$error = null;
$nextCode = '';
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;


// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = trim($_POST['company_name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $customer_code = trim($_POST['customer_code']);
    
    // Basic validation
    // Validation (All fields mandatory)
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $tin = trim($_POST['tin'] ?? '');
    $vrn = trim($_POST['vrn'] ?? '');
    $customer_type = trim($_POST['customer_type'] ?? '');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $currency = trim($_POST['currency'] ?? 'TZS');
    $notes = trim($_POST['notes'] ?? '');

    // Validation (All fields mandatory)
    if (empty($company_name) || empty($customer_code) || empty($contact_person) || empty($email) || empty($phone) || empty($address) || empty($city) || empty($country)) {
        $error = "All fields are required (Address, City, Country).";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $error = "Invalid email format.";
    } else {
        try {
            // Ensure columns exist
            ensureCustomerColumnsExist();

            // Regenerate customer code to prevent concurrency duplicate key errors
            $currentYear = date('Y');
            $prefix = "CUST-$currentYear-";
            $codeStmt = $salesDb->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY id DESC LIMIT 1");
            $codeStmt->execute([$prefix . '%']);
            $lastCode = $codeStmt->fetchColumn();

            if ($lastCode && preg_match('/\((\d+)\)$/', $lastCode, $matches)) {
                $nextNum = intval($matches[1]) + 1;
            } else {
                $nextNum = 1;
            }

            // Robust check to ensure uniqueness
            $isUnique = false;
            while (!$isUnique) {
                $customer_code = $prefix . '(' . str_pad($nextNum, 3, '0', STR_PAD_LEFT) . ')';
                $checkStmt = $salesDb->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
                $checkStmt->execute([$customer_code]);
                if ($checkStmt->fetchColumn() == 0) {
                    $isUnique = true;
                } else {
                    $nextNum++;
                }
            }

            $stmt = $salesDb->prepare("
                INSERT INTO customers (
                    customer_code, company_name, contact_person, email, phone, 
                    address, city, country, tax_number, tin, vrn, customer_type, 
                    payment_terms, currency, credit_limit, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $customer_code,
                $company_name,
                $contact_person,
                $email,
                $phone,
                $address,
                $city,
                $country,
                $tin . ($vrn ? " / $vrn" : ""), 
                $tin,
                $vrn,
                $customer_type,
                $payment_terms,
                $currency,
                floatval($_POST['credit_limit']),
                $notes,
                $_SESSION['user_id'] ?? 1
            ]);
            
            $redirectUrl = function_exists('sales_module_url')
                ? sales_module_url('customers/index.php', ['msg' => 'created'])
                : 'index.php?msg=created&module=sales';
            header("Location: $redirectUrl");
            exit;

            
        } catch (PDOException $e) {
            $error = "Error adding customer: " . $e->getMessage();
        }
    }
}

// Auto-generate Customer Code logic
$currentYear = date('Y');
$prefix = "CUST-$currentYear-";
try {
    $stmt = $salesDb->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $lastCode = $stmt->fetchColumn();

    if ($lastCode && preg_match('/\((\d+)\)$/', $lastCode, $matches)) {
        $nextNum = intval($matches[1]) + 1;
    } else {
        $nextNum = 1;
    }

    // Find the first available code
    $isUnique = false;
    while (!$isUnique) {
        $nextCode = $prefix . '(' . str_pad($nextNum, 3, '0', STR_PAD_LEFT) . ')';
        $checkStmt = $salesDb->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        $checkStmt->execute([$nextCode]);
        if ($checkStmt->fetchColumn() == 0) {
            $isUnique = true;
        } else {
            $nextNum++;
        }
    }
} catch (PDOException $e) {
    error_log('customers/add.php failed to pre-load next code: ' . $e->getMessage());
    if ($error === null) {
        $error = 'Unable to load the next customer code right now. Please try again in a moment.';
    }
    $nextCode = $prefix . '(001)';
}

$backUrl = function_exists('sales_module_url') ? sales_module_url('customers/index.php') : 'index.php';
$formValues = [
    'customer_code' => trim((string) ($_POST['customer_code'] ?? $nextCode)),
    'company_name' => trim((string) ($_POST['company_name'] ?? '')),
    'contact_person' => trim((string) ($_POST['contact_person'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'tin' => trim((string) ($_POST['tin'] ?? '')),
    'vrn' => trim((string) ($_POST['vrn'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'city' => trim((string) ($_POST['city'] ?? '')),
    'country' => trim((string) ($_POST['country'] ?? 'Tanzania')),
    'customer_type' => trim((string) ($_POST['customer_type'] ?? 'retail')),
    'payment_terms' => trim((string) ($_POST['payment_terms'] ?? 'Net 30')),
    'currency' => trim((string) ($_POST['currency'] ?? 'TZS')),
    'credit_limit' => trim((string) ($_POST['credit_limit'] ?? '0.00')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer | Sales Module</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .main-content-wrapper {
            padding: 2rem;
        }
        .page-shell {
            padding-left: 4rem;
        }
        .editor-shell {
            max-width: 1140px;
            margin: 0 auto;
        }
        .editor-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-layout {
            display: grid;
            grid-template-columns: 180px minmax(0, 1fr);
            gap: 2rem;
            align-items: start;
        }
        .section-nav {
            position: sticky;
            top: 96px;
            align-self: start;
        }
        .section-nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .section-nav li + li {
            margin-top: 0.5rem;
        }
        .section-nav a {
            display: block;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .section-nav a:hover {
            background: #eff6ff;
            color: #2563eb;
        }
        .section-nav a.is-active {
            background: #f3e8ff;
            color: #7c3aed;
            font-weight: 600;
        }
        .editor-main {
            min-width: 0;
        }
        .editor-section {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-section:last-of-type {
            margin-bottom: 1.5rem;
        }
        .section-header {
            margin-bottom: 1.25rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: 210px 1fr;
            align-items: start;
            margin-bottom: 24px;
        }
        .form-row:last-child {
            margin-bottom: 0;
        }
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            padding-top: 12px;
        }
        .form-label span {
            color: #ef4444;
            margin-left: 2px;
        }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: all 0.2s;
            background: #fff;
        }
        .form-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .form-input-readonly {
            background: #f8fafc;
            font-family: monospace;
            font-weight: 700;
            color: #2563eb;
            border-style: dashed;
        }
        .form-input-price {
            color: #16a34a !important;
            font-weight: 600;
        }
        .form-input-price::placeholder {
            color: #86efac;
        }
        .help-text {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
            line-height: 1.5;
            font-weight: 400;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .section-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin: 0;
        }
        .btn-save {
            background: #7c3aed;
            color: white;
            padding: 14px 48px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        }
        .btn-save:hover {
            background: #6d28d9;
        }
        .btn-cancel {
            border: 1px solid #d8b4fe;
            color: #7c3aed;
            background: #faf5ff;
            transition: all 0.2s;
        }
        .btn-cancel:hover {
            background: #f3e8ff;
            color: #6d28d9;
        }
        @media (max-width: 992px) {
            .main-content-wrapper {
                padding: 1rem !important;
            }
            .page-shell {
                padding-left: 0;
            }
            .editor-topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .editor-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .section-nav {
                position: static;
            }
            .section-nav ul {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            .section-nav li + li {
                margin-top: 0;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 20px;
            }
            .form-label {
                padding-top: 0;
                font-size: 13px;
            }
            .btn-save {
                width: 100%;
                padding: 14px 24px;
            }
        }
        @media (max-width: 992px) {
            html body .main-content,
            html body .content-wrapper,
            html body main,
            html body.dashboard .main-content,
            html body .header,
            html body .admin-header,
            html body .employee-header {
                margin-left: 0 !important;
                width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    <div class="main-content-wrapper">
        <div class="page-shell editor-shell">
            <div class="editor-topbar">
                <div>
                    <h1 class="text-xl font-semibold text-slate-800">Add New Customer</h1>
                </div>
                <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left text-xs"></i> Back to Customers
                </a>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 max-w-[1000px]">
                <?= $esc($error) ?>
            </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="editor-layout">
                    <aside class="section-nav">
                        <ul>
                            <li><a href="#general-info" class="is-active">General</a></li>
                        </ul>
                    </aside>

                    <div class="editor-main">
                        <section class="editor-section" id="general-info">
                            <div class="section-header">
                                <h2 class="section-title">General Information</h2>
                                <p class="section-subtitle">Core customer setup and account defaults.</p>
                            </div>
                        <div class="form-row">
                            <label class="form-label">Customer Code <span>*</span></label>
                            <div>
                                <input type="text" name="customer_code" value="<?= $esc($formValues['customer_code']) ?>" readonly class="form-input form-input-readonly">
                                <div class="help-text">This code is generated automatically from the latest available customer number.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Company Name <span>*</span></label>
                            <div>
                                <input type="text" name="company_name" required placeholder="e.g. Ultimate Trading Company" value="<?= $esc($formValues['company_name']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Customer Type <span>*</span></label>
                            <div>
                                <select name="customer_type" required class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                                    <option value="retail" <?= $formValues['customer_type'] === 'retail' ? 'selected' : '' ?>>Retail</option>
                                    <option value="wholesale" <?= $formValues['customer_type'] === 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                                    <option value="corporate" <?= $formValues['customer_type'] === 'corporate' ? 'selected' : '' ?>>Corporate</option>
                                    <option value="government" <?= $formValues['customer_type'] === 'government' ? 'selected' : '' ?>>Government</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Payment Terms</label>
                            <div>
                                <select name="payment_terms" class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                                    <option value="Immediate" <?= $formValues['payment_terms'] === 'Immediate' ? 'selected' : '' ?>>Immediate</option>
                                    <option value="Net 15" <?= $formValues['payment_terms'] === 'Net 15' ? 'selected' : '' ?>>Net 15</option>
                                    <option value="Net 30" <?= $formValues['payment_terms'] === 'Net 30' ? 'selected' : '' ?>>Net 30</option>
                                    <option value="Net 60" <?= $formValues['payment_terms'] === 'Net 60' ? 'selected' : '' ?>>Net 60</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Currency</label>
                            <div>
                                <select name="currency" class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                                    <option value="TZS" <?= $formValues['currency'] === 'TZS' ? 'selected' : '' ?>>TZS</option>
                                    <option value="USD" <?= $formValues['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Credit Limit</label>
                            <div>
                                <input type="number" step="0.01" min="0" name="credit_limit" value="<?= $esc($formValues['credit_limit']) ?>" class="form-input form-input-price" placeholder="0.00">
                            </div>
                        </div>
                        </section>

                        <section class="editor-section" id="contact-address">
                            <div class="section-header">
                                <h2 class="section-title">Contact &amp; Address</h2>
                                <p class="section-subtitle">Primary contact details and billing location.</p>
                            </div>
                        <div class="form-row">
                            <label class="form-label">Contact Person <span>*</span></label>
                            <div>
                                <input type="text" name="contact_person" required placeholder="e.g. John Doe" value="<?= $esc($formValues['contact_person']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Email <span>*</span></label>
                            <div>
                                <input type="email" name="email" required placeholder="e.g. john.doe@example.com" value="<?= $esc($formValues['email']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Phone <span>*</span></label>
                            <div>
                                <input type="text" name="phone" required placeholder="e.g. +255 123 456 789" value="<?= $esc($formValues['phone']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Address <span>*</span></label>
                            <div>
                                <textarea name="address" required rows="3" placeholder="e.g. Plot 12, Nyerere Road, P.O. Box 75, Dar es Salaam" class="form-input min-h-[100px]"><?= $esc($formValues['address']) ?></textarea>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">City <span>*</span></label>
                            <div>
                                <input type="text" name="city" required placeholder="e.g. Dar es Salaam" value="<?= $esc($formValues['city']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Country <span>*</span></label>
                            <div>
                                <input type="text" name="country" required value="<?= $esc($formValues['country']) ?>" class="form-input">
                            </div>
                        </div>
                        </section>

                        <section class="editor-section" id="tax-notes">
                            <div class="section-header">
                                <h2 class="section-title">Tax &amp; Notes</h2>
                                <p class="section-subtitle">Optional tax identifiers and internal notes.</p>
                            </div>
                        <div class="form-row">
                            <label class="form-label">TIN Number</label>
                            <div>
                                <input type="text" name="tin" placeholder="e.g. 123-456-789" value="<?= $esc($formValues['tin']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">VRN Number</label>
                            <div>
                                <input type="text" name="vrn" placeholder="e.g. 10-123456-X" value="<?= $esc($formValues['vrn']) ?>" class="form-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Notes</label>
                            <div>
                                <textarea name="notes" rows="4" placeholder="Any additional notes or specific requirements..." class="form-input min-h-[120px]"><?= $esc($formValues['notes']) ?></textarea>
                            </div>
                        </div>
                        </section>

                        <div class="flex justify-start gap-4 mb-20">
                            <button type="button" onclick="location.href='<?= $esc($backUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                            <button type="submit" class="btn-save">Save Customer</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
