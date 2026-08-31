<?php
require_once '../../includes/functions.php';

global $pdo;
$id = $_GET['id'] ?? 0;

// Get customer details
$stmt = $pdo->prepare("SELECT * FROM erp_customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    die("Customer not found");
}

// Get customer invoices - Handle missing table/table errors gracefully
$invoices = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM erp_invoices WHERE customer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $invoices = $stmt->fetchAll();
} catch (Throwable $e) {
    // Invoices table might not exist or has schema mismatch
}

// Get customer notes - Fallback to the 'notes' column from erp_customers if the separate table doesn't exist
$notes = [];
// Check if erp_customer_notes exists - unlikely based on list.php schema
$useNotesTable = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'erp_customer_notes'");
    if ($check->rowCount() > 0) {
        $useNotesTable = true;
    }
} catch (Throwable $e) {
}

if ($useNotesTable) {
    try {
        $stmt = $pdo->prepare("SELECT n.*, u.full_name as user_name FROM erp_customer_notes n JOIN users u ON n.user_id = u.id WHERE customer_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $notes = $stmt->fetchAll();
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($customer['name']) ?> - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --accent-color: #1a73e8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            color: #374151;
        }

        .page-wrapper {
            margin-left: 220px !important;
            width: calc(100% - 220px) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .custom-header {
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .custom-container {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
            align-items: start;
        }

        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
        }

        .card-title {
            font-weight: 600;
            font-size: 1rem;
            color: #111827;
        }

        .card-body {
            padding: 20px;
        }

        .info-group {
            margin-bottom: 16px;
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: 12px;
        }

        .info-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 6px;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .info-value {
            font-size: 0.95rem;
            color: #111827;
            font-weight: 500;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
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

        .badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #e0f2fe;
            color: #0369a1;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            text-transform: uppercase;
        }

        .table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            font-size: 0.9rem;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .note-item {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            background: #f9fafb;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .note-meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
        }

        @media (max-width: 900px) {
            .custom-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="page-wrapper">
        <div class="custom-header">
            <div class="header-title">
                <h1><?= htmlspecialchars($customer['name']) ?></h1>
            </div>
            <div>
                <a href="list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="edit.php?id=<?= $customer['id'] ?>" class="btn btn-primary"><i class="fas fa-edit"></i>
                    Edit</a>
            </div>
        </div>

        <div class="custom-container">
            <!-- Sidebar: Customer Info -->
            <div class="sidebar-col">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Customer Details</div>
                    </div>
                    <div class="card-body">
                        <div class="info-group">
                            <div class="info-label">Customer Code</div>
                            <div class="info-value"
                                style="font-family: monospace; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; display: inline-block;">
                                <?= htmlspecialchars($customer['customer_code']) ?>
                            </div>
                        </div>

                        <div class="info-group">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span
                                    class="badge <?= ($customer['status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= ucfirst($customer['status'] ?? 'active') ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-group">
                            <div class="info-label">Contact Info</div>
                            <div class="info-value">
                                <?php if (!empty($customer['email'])): ?>
                                    <div style="margin-bottom: 4px;"><i class="far fa-envelope"
                                            style="width: 20px; color: #9ca3af;"></i>
                                        <?= htmlspecialchars($customer['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($customer['phone'])): ?>
                                    <div><i class="fas fa-phone" style="width: 20px; color: #9ca3af;"></i>
                                        <?= htmlspecialchars($customer['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 16px 0;">

                        <div class="info-group">
                            <div class="info-label">Address</div>
                            <div class="info-value">
                                <?php if (!empty($customer['address'])): ?>
                                    <?= nl2br(htmlspecialchars($customer['address'])) ?><br>
                                <?php endif; ?>
                                <?php if (!empty($customer['city'])): ?>
                                    <?= htmlspecialchars($customer['city']) ?>,
                                <?php endif; ?>
                                <?= htmlspecialchars($customer['country'] ?? 'Tanzania') ?>
                            </div>
                        </div>

                        <div class="info-group">
                            <div class="info-label">Tax / TIN</div>
                            <div class="info-value"><?= htmlspecialchars($customer['tax_id'] ?? '-') ?></div>
                        </div>

                        <div class="info-group">
                            <div class="info-label">Current Balance</div>
                            <div class="info-value"
                                style="font-size: 1.5rem; font-weight: 700; color: <?= ($customer['balance'] ?? 0) > 0 ? '#dc2626' : '#059669' ?>">
                                TSh <?= number_format($customer['balance'] ?? 0, 2) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Internal Notes -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Notes</div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($customer['notes'])): ?>
                            <div style="white-space: pre-wrap; color: #4b5563; font-size: 0.95rem;">
                                <?= htmlspecialchars($customer['notes']) ?></div>
                        <?php else: ?>
                            <div style="color: #9ca3af; font-size: 0.875rem; font-style: italic;">No notes for this
                                customer.</div>
                        <?php endif; ?>

                        <?php if (!empty($notes)): ?>
                            <hr style="margin: 16px 0;">
                            <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 8px;">HISTORY
                            </div>
                            <?php foreach ($notes as $note): ?>
                                <div class="note-item">
                                    <div class="note-meta"><?= htmlspecialchars($note['user_name'] ?? 'System') ?> •
                                        <?= date('M d, Y', strtotime($note['created_at'])) ?></div>
                                    <div><?= nl2br(htmlspecialchars($note['note'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Content: Invoices & Activity -->
            <div class="main-col">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Recent Invoices</div>
                        <a href="../sales/create-invoice.php?customer_id=<?= $customer['id'] ?>"
                            class="btn btn-primary"><i class="fas fa-plus"></i> New Invoice</a>
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <?php if (empty($invoices)): ?>
                            <div style="padding: 48px; text-align: center; color: #6b7280;">
                                <i class="far fa-file-alt"
                                    style="font-size: 3rem; margin-bottom: 16px; color: #e5e7eb;"></i>
                                <p>No invoices found for this customer.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Date</th>
                                            <th>Due</th>
                                            <th style="text-align: right;">Amount</th>
                                            <th style="text-align: right;">Balance</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoices as $invoice): ?>
                                            <tr>
                                                <td><a href="../sales/view-invoice.php?id=<?= $invoice['id'] ?>"
                                                        style="color: var(--accent-color); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($invoice['invoice_number']) ?></a>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td><?= $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '-' ?>
                                                </td>
                                                <td style="text-align: right;"><?= number_format($invoice['total'], 2) ?></td>
                                                <td style="text-align: right; font-weight: 500; color: #dc2626;">
                                                    <?= number_format($invoice['balance'], 2) ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = [
                                                        'draft' => 'badge-info',
                                                        'sent' => 'badge-warning',
                                                        'paid' => 'badge-success',
                                                        'partial' => 'badge-warning',
                                                        'overdue' => 'badge-danger',
                                                        'cancelled' => 'badge-danger'
                                                    ];
                                                    ?>
                                                    <span
                                                        class="badge <?= $statusClass[$invoice['status'] ?? 'draft'] ?? 'badge-info' ?>">
                                                        <?= ucfirst($invoice['status'] ?? 'draft') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="../sales/view-invoice.php?id=<?= $invoice['id'] ?>"
                                                        class="btn btn-secondary"
                                                        style="padding: 4px 8px; font-size: 0.75rem;">View</a>
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
        </div>
    </div>
</body>

</html>