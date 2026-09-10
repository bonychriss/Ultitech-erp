<?php
// modules/payroll/setup.php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/payroll-lib.php';

// We don't require login for the setup itself to allow initial deployment,
// but we should check if payroll tables already exist or if we should restrict this.
// For now, let's make it easy to run.

$status = [];

function run_query($pdo, $sql, $description, &$status) {
    try {
        $pdo->exec($sql);
        $status[] = ['desc' => $description, 'status' => 'success', 'msg' => 'Table created or already exists.'];
    } catch (PDOException $e) {
        $status[] = ['desc' => $description, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// 1. payroll_settings (matches app: setting_key / setting_value)
$sql1 = "CREATE TABLE IF NOT EXISTS " . payroll_table('payroll_settings') . " (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
run_query($pdo, $sql1, "Payroll Settings Table", $status);

// Insert default settings
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO " . payroll_table('payroll_settings') . " (`setting_key`, `setting_value`, `description`) VALUES
    ('currency', 'TZS', 'Default currency'),
    ('pay_day', '30', 'Day of month to run payroll'),
    ('tax_rate', '0', 'Flat tax rate (if applicable), else 0 for graduated'),
    ('nssf_rate', '0.10', 'NSSF percentage (employee)'),
    ('social_security_rate', '10', 'NSSF percentage (employee)'),
    ('employer_social_security_rate', '10', 'NSSF percentage (employer)'),
    ('sdl_rate', '3.5', 'Skills Development Levy % of gross'),
    ('wcf_rate', '0.5', 'Workers Compensation Fund % of gross')");
    $stmt->execute();
    $status[] = ['desc' => 'Default Settings', 'status' => 'success', 'msg' => 'Default NSSF, Tax, SDL, WCF, and Pay Day values initialized.'];
} catch (PDOException $e) {
    $status[] = ['desc' => 'Default Settings', 'status' => 'error', 'msg' => $e->getMessage()];
}

// 2. employee_salary
$sql2 = "CREATE TABLE IF NOT EXISTS " . payroll_table('employee_salary') . " (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `basic_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `house_allowance` decimal(15,2) DEFAULT 0.00,
  `transport_allowance` decimal(15,2) DEFAULT 0.00,
  `overtime_allowances` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus_commission` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `nssf_number` varchar(50) DEFAULT NULL,
  `other_deductions` decimal(15,2) DEFAULT 0.00,
  `monthly_adjustment` decimal(15,2) DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
run_query($pdo, $sql2, "Employee Salary Table", $status);

// 3. payroll_runs
$sql3 = "CREATE TABLE IF NOT EXISTS " . payroll_table('payroll_runs') . " (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `month` int(2) NOT NULL,
  `year` int(4) NOT NULL,
  `run_date` date NOT NULL,
  `run_by` int(11) NOT NULL,
  `status` enum('draft','confirmed','paid') DEFAULT 'draft',
  `total_payout` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
run_query($pdo, $sql3, "Payroll Runs Table", $status);

// 4. payslips
$sql4 = "CREATE TABLE IF NOT EXISTS " . payroll_table('payslips') . " (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_run_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `basic_salary` decimal(15,2) NOT NULL,
  `total_allowances` decimal(15,2) DEFAULT 0.00,
  `overtime_allowances` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus_commission` decimal(15,2) NOT NULL DEFAULT 0.00,
  `monthly_adjustment` decimal(15,2) DEFAULT 0.00,
  `gross_salary` decimal(15,2) NOT NULL,
  `taxable_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_deduction` decimal(15,2) DEFAULT 0.00,
  `nssf_deduction` decimal(15,2) DEFAULT 0.00,
  `employer_nssf` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sdl_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `wcf_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `employer_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(15,2) DEFAULT 0.00,
  `net_salary` decimal(15,2) NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payroll_run_id` (`payroll_run_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
run_query($pdo, $sql4, "Payslips Table", $status);

if (function_exists('payrollDeskEnsureExcelPayrollSchema')) {
    try {
        payrollDeskEnsureExcelPayrollSchema($pdo);
        $status[] = ['desc' => 'Excel payroll columns', 'status' => 'success', 'msg' => 'OT/bonus and employer statutory columns ensured.'];
    } catch (Throwable $e) {
        $status[] = ['desc' => 'Excel payroll columns', 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// 5. payroll_tax_bands
$sql5 = "CREATE TABLE IF NOT EXISTS " . payroll_table('payroll_tax_bands') . " (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `max_salary` decimal(15,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `offset_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
run_query($pdo, $sql5, "Payroll Tax Bands Table", $status);

try {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . payroll_table('payroll_tax_bands'))->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO " . payroll_table('payroll_tax_bands') . " (`min_salary`, `max_salary`, `tax_rate`, `offset_amount`, `description`, `is_active`) VALUES
            (0, 270000, 0, 0, '0% (No tax)', 1),
            (270000, 520000, 8, 0, '8% of amount above 270,000', 1),
            (520000, 760000, 20, 20000, '20,000 + 20% of amount above 520,000', 1),
            (760000, 1000000, 25, 68000, '68,000 + 25% of amount above 760,000', 1),
            (1000000, NULL, 30, 128000, '128,000 + 30% of amount above 1,000,000', 1)");
        $status[] = ['desc' => 'Default Tax Bands', 'status' => 'success', 'msg' => 'Tanzania PAYE bands initialized.'];
    } else {
        $status[] = ['desc' => 'Default Tax Bands', 'status' => 'success', 'msg' => 'Tax bands already present.'];
    }
} catch (PDOException $e) {
    $status[] = ['desc' => 'Default Tax Bands', 'status' => 'error', 'msg' => $e->getMessage()];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Module Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .setup-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 30px; margin-top: 50px; }
        .status-item { padding: 15px; border-bottom: 1px solid #f1f5f9; }
        .status-item:last-child { border-bottom: none; }
        .icon-success { color: #10b981; }
        .icon-error { color: #ef4444; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7">
                <div class="setup-card">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3">
                            <i class="bi bi-gear-fill fs-1"></i>
                        </div>
                        <h2 class="fw-bold">Payroll Setup</h2>
                        <p class="text-muted">Initializing database components for the live site.</p>
                        <?php
                        $setupSchema = function_exists('payroll_resolved_schema') ? payroll_resolved_schema() : '';
                        $setupConnDb = '';
                        try {
                            $setupConnDb = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
                        } catch (Throwable $e) {
                            $setupConnDb = '';
                        }
                        ?>
                        <p class="small text-muted mb-0">
                            Target DB: <code><?= htmlspecialchars($setupSchema !== '' ? $setupSchema : '(unqualified)', ENT_QUOTES, 'UTF-8') ?></code>
                            <?php if ($setupConnDb !== ''): ?>
                                · Connected as: <code><?= htmlspecialchars($setupConnDb, ENT_QUOTES, 'UTF-8') ?></code>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="list-group">
                        <?php foreach($status as $item): ?>
                        <div class="status-item d-flex align-items-center">
                            <i class="bi <?= $item['status'] === 'success' ? 'bi-check-circle-fill icon-success' : 'bi-x-circle-fill icon-error' ?> fs-4 me-3"></i>
                            <div class="flex-grow-1">
                                <div class="fw-bold"><?= $item['desc'] ?></div>
                                <div class="small text-muted"><?= $item['msg'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 text-center">
                        <?php
                        $errors = array_filter($status, function($i) { return $i['status'] === 'error'; });
                        if (empty($errors)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-stars me-2"></i> Setup completed successfully! You can now use the module.
                            </div>
                            <a href="index.php" class="btn btn-primary w-100 py-3 fw-bold">Open Payroll Dashboard</a>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Some components failed to initialize. Please check your DB permissions.
                            </div>
                            <button onclick="window.location.reload()" class="btn btn-outline-primary w-100 py-3 fw-bold">Retry Setup</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center mt-3 text-muted small">
                    &copy; Staff ERP - Payroll Module
                </div>
            </div>
        </div>
    </div>
</body>
</html>
