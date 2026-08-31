<?php
require_once '../../includes/functions.php';

global $pdo;

// Ensure customers table exists to avoid fatal errors on fresh installs
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(50) NOT NULL,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_customer_code (customer_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Auto-fix: Update any legacy or broken records with missing status
    $pdo->exec("UPDATE erp_customers SET status='active' WHERE status IS NULL OR status = ''");
} catch (Throwable $e) { /* ignore: fallback to empty state if creation fails */
}

// Best-effort: add columns expected by API if missing
try {
    $pdo->query("SELECT city FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN city VARCHAR(100) NULL AFTER address");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT country FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN country VARCHAR(100) NULL AFTER city");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT tax_id FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN tax_id VARCHAR(100) NULL AFTER country");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT credit_limit FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tax_id");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT notes FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN notes TEXT NULL AFTER credit_limit");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT created_by FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN created_by INT NULL AFTER notes");
    } catch (Throwable $e2) {
    }
}
try {
    $pdo->query("SELECT created_at FROM erp_customers LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    } catch (Throwable $e2) {
    }
}

// Get all customers
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

$sql = "SELECT * FROM erp_customers WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR customer_code LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam, $searchParam, $searchParam];
}

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY created_at DESC";

$dbError = null;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (Throwable $e) {
    // If the table doesn't exist or query fails on production, render empty state gracefully
    $customers = [];
    $dbError = $e->getMessage();
}

// Calculate balance for each customer from invoices
if (!empty($customers)) {
    foreach ($customers as &$customer) {
        try {
            $balanceStmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) as total_balance FROM erp_invoices WHERE customer_id = ?");
            $balanceStmt->execute([$customer['id']]);
            $customer['balance'] = $balanceStmt->fetch()['total_balance'] ?? 0;
        } catch (Throwable $e) {
            // If invoices table/column missing, default to 0
            $customer['balance'] = 0;
        }
    }
    unset($customer); // Break reference
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <style>
        :root {
            --primary-color: #2c3e50;
            /* Dark used for titles */
            --accent-color: #1a73e8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: #f5f5f5;
            color: #374151;
        }

        /* Layout Fix: Ensure page wrapper takes full available width override sidebar !important */
        .page-wrapper {
            margin-left: 220px !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: calc(100% - 220px) !important;
        }

        .header {
            background: #fff;
            padding: 16px 20px !important;
            /* Reduced padding */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 0;
            line-height: 1.2;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border: 1px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
        }

        .btn-primary:hover {
            background: #1557b0;
        }

        .btn-secondary {
            background: #fff;
            color: #374151;
            border-color: #d1d5db;
        }

        .btn-secondary:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }

        .container {
            width: 100% !important;
            padding: 20px !important;
            /* Reduced gap/padding */
            flex: 1;
            display: flex;
            flex-direction: column;
            margin: 0 !important;
            max-width: none !important;
        }

        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            flex: 1;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            width: 100%;
        }

        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fff;
        }

        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            gap: 16px;
        }

        .search-box-wrapper {
            position: relative;
            width: 300px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .form-input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            /* Space for icon */
            border: 1px solid #d1d5db;
            border-radius: 6px;
            /* Soft radius */
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-select {
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #374151;
            background: white;
            outline: none;
            cursor: pointer;
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 14px 24px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid #f3f4f6;
            color: #1f2937;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background: #f9fafb;
        }

        .customer-code {
            font-family: "SF Mono", "Monaco", "Inconsolata", "Fira Mono", "Droid Sans Mono", "Source Code Pro", monospace;
            color: #4b5563;
            font-size: 0.85rem;
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Fix 4: Empty State Improvements */
        .empty-state {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 80px 24px;
            text-align: center;
            min-height: 400px;
            /* Ensure logical height */
        }

        .empty-state-icon {
            font-size: 3.5rem;
            color: #e5e7eb;
            /* Light gray */
            margin-bottom: 20px;
        }

        .empty-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            margin-bottom: 8px;
        }

        .empty-subtitle {
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0;
                width: 100%;
            }

            .header,
            .container {
                padding: 16px;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box-wrapper {
                width: 100%;
            }
        }
    </style>
    </head>

    <body>
        <div class="page-wrapper">
            <!-- Header -->
            <div class="header">
                <!-- Fix 1: Dark Title -->
                <h1>Customers</h1>
                <div class="header-actions">
                    <a href="../index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Customer
                    </a>
                </div>
            </div>

            <div class="container">
                <div class="card">
                    <!-- Fix 3: Structured Toolbar -->
                    <div class="card-header">
                        <form method="GET" class="filters">
                            <button type="submit" style="display: none;"></button> <!-- Implicit submit -->

                            <!-- Search Left -->
                            <div class="search-box-wrapper">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" class="form-input" placeholder="Search customers..."
                                    value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
                            </div>

                            <!-- Filter Right -->
                            <div style="margin-left: auto;">
                                <select name="status" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive
                                    </option>
                                </select>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($dbError)): ?>
                        <div
                            style="padding: 15px; margin: 20px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px;">
                            <strong>System Error:</strong> <?= htmlspecialchars($dbError) ?><br>
                            <small>Please contact the administrator or check the database connection.</small>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($customers) && empty($dbError)): ?>
                        <!-- Fix 4: Improved Empty State -->
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <!-- Cleaner Icon (User Outline/Simple) -->
                                <i class="far fa-user"></i>
                            </div>
                            <h3 class="empty-title">No customers found</h3>
                            <p class="empty-subtitle">Get started by adding your first customer to the system.</p>
                            <!-- Identical Button Style -->
                            <a href="create.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Customer
                            </a>
                        </div>
                    <?php elseif (!empty($customers)): ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="10%">Code</th>
                                        <th width="25%">Name</th>
                                        <th width="20%">Email</th>
                                        <th width="15%">Phone</th>
                                        <th width="15%" style="text-align: right;">Balance</th>
                                        <th width="10%">Status</th>
                                        <th width="5%" style="text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td><span
                                                    class="customer-code"><?= htmlspecialchars($customer['customer_code']) ?></span>
                                            </td>
                                            <td style="font-weight: 500; color: #111827;">
                                                <?= htmlspecialchars($customer['name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($customer['email'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                                            <td style="font-weight: 600; text-align: right;">TSh
                                                <?= number_format($customer['balance'], 2) ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge <?= ($customer['status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                                    <?= ucfirst($customer['status'] ?? 'active') ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <div style="display: inline-flex; gap: 4px;">
                                                    <a href="view.php?id=<?= $customer['id'] ?>" class="btn-icon"
                                                        style="color: #6b7280; padding: 6px; border-radius: 4px; transition: background 0.2s;"
                                                        title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </body>

</html>