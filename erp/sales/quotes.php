<?php 
// Clear opcache to prevent caching issues - UPDATED 2025-11-29
if (function_exists('opcache_reset')) { opcache_reset(); }
require_once '../../includes/functions.php';
global $pdo;

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES,'UTF-8'); } }

$quotes = [];
$error_message = '';

try {
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';

    // Inspect columns for compatibility (schema drift handling)
    $cols = [];
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM erp_quotes");
        $cols = array_map(fn($r) => $r['Field'], $colStmt->fetchAll());
    } catch (Throwable $e) { /* ignore */ }

    $hasQuoteDate = in_array('quote_date', $cols, true);
    $hasCreatedAt = in_array('created_at', $cols, true);
    $hasUpdatedAt = in_array('updated_at', $cols, true);
    $orderCol = $hasQuoteDate ? 'quote_date' : ($hasCreatedAt ? 'created_at' : ($hasUpdatedAt ? 'updated_at' : 'id'));

    $hasExpiryDate = in_array('expiry_date', $cols, true);
    $hasValidUntil = in_array('valid_until', $cols, true);
    $expiryCol = $hasExpiryDate ? 'expiry_date' : ($hasValidUntil ? 'valid_until' : null);

    $amountFields = array_intersect(['total_amount','total','subtotal'], $cols);
    $amountField = reset($amountFields) ?: 'total_amount';

    $sql = "SELECT q.*, c.name AS customer_name FROM erp_quotes q JOIN erp_customers c ON q.customer_id = c.id WHERE 1=1";
    $params = [];
    
    // Status Filter
    if ($status !== 'all') { 
        $sql .= " AND q.status = ?"; 
        $params[] = $status; 
    }
    
    // Search Filter
    if (!empty($search)) {
        $sql .= " AND (q.quote_number LIKE ? OR c.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY q.$orderCol DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quotes = $stmt->fetchAll();

    // Map dynamic fields for rendering convenience
    foreach ($quotes as &$q) {
        if (!isset($q['quote_date']) && $hasQuoteDate && isset($q[$orderCol])) { $q['quote_date'] = $q[$orderCol]; }
        if ($expiryCol && !isset($q['expiry_date']) && isset($q[$expiryCol])) { $q['expiry_date'] = $q[$expiryCol]; }
        if (!isset($q['total_amount']) && isset($q[$amountField])) { $q['total_amount'] = $q[$amountField]; }
    }
    unset($q);
} catch (PDOException $e) {
    $error_message = 'Database Error: ' . $e->getMessage();
    if (defined('APP_ENV') && APP_ENV === 'production') {
        error_log($e->getMessage());
        $error_message = 'Unable to load quotes. Please ensure the database is updated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotations - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        
        /* Use !important to override styles injected by sidebar.php */
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; padding: 10px !important; width: 100% !important; } }
        
        .header { background: transparent !important; margin-bottom: 20px; padding: 0 !important; display: flex !important; justify-content: space-between; align-items: center; border: none !important; }
        .header h2 { font-size: 1.75rem; font-weight: 600; color: #1f2937; margin: 0; }
        .header-actions { display: flex; gap: 12px; margin: 0 !important; }

        .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        
        /* Card: Flat, no padding on container itself */
        .card { background: white; border-radius: 0; border: none !important; overflow: visible; box-shadow: none !important; width: 100%; max-width: 100% !important; }
        
        /* Filters: No horizontal padding so Input starts at alignment line */
        .filters-toolbar { padding: 0 0 20px 0; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: transparent; }
        
        .search-box { position: relative; width: 300px; }
        .search-box input { width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; }
        .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        
        .filter-select { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; color: #374151; min-width: 160px; }
        
        /* Table: Header padding tweak to align text roughly with input text if needed, or keep standard */
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.8rem; font-weight: 600; color: #4b5563; text-transform: uppercase; border-bottom: 2px solid #e5e7eb; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f3f4f6; color: #1f2937; vertical-align: middle; }
        .table tr:hover { background:#f8fafc; } 
        
        .btn { padding:8px 16px; border-radius:6px; text-decoration:none; font-size:0.9rem; font-weight:500; cursor:pointer; border:none; display:inline-flex; align-items: center; gap: 6px; transition: all 0.2s; } 
        .btn-primary { background:#1a73e8; color:white; } 
        .btn-primary:hover { background: #1557b0; }
        .btn-secondary { background:#fff; color:#374151; border:1px solid #d1d5db; } 
        .btn-secondary:hover { background: #f3f4f6; }
        .btn-success { background:#10b981; color:white; }
        
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: #6b7280; background: transparent; transition: background 0.2s; }
        .btn-icon:hover { background: #f3f4f6; color: #111827; }
        
        /* Dropdown Menu */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; min-width: 140px; margin-top: 4px; overflow: hidden; }
        .dropdown.active .dropdown-menu { display: block; }
        .dropdown-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: #374151; text-decoration: none; font-size: 0.85rem; width: 100%; text-align: left; cursor: pointer; transition: background 0.1s; border: none; background: none; }
        .dropdown-item:hover { background: #f3f4f6; }
        .dropdown-item i { width: 16px; text-align: center; color: #6b7280; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-warning { background:#fef3c7; color:#d97706; } 
        .badge-success { background:#d1fae5; color:#059669; } 
        .badge-info { background:#dbeafe; color:#2563eb; } 
        .badge-danger { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <!-- Header -->
    <div class="header">
        <h2>Quotations</h2>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button id="bulkInvoiceBtn" class="btn btn-success" style="display:none;" onclick="bulkConvertToInvoice()">
                <i class="fas fa-file-invoice"></i> Generate Invoices (<span id="selectedCount">0</span>)
            </button>
            <a href="create-quote.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Quotation
            </a>
        </div>
    </div>

    <!-- Main Card -->
    <div class="container">
        <?php if ($error_message): ?>
            <div class="card" style="padding: 16px; background: #fee2e2; color: #dc2626; border-color: #fecaca; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <!-- Filter Toolbar -->
            <form method="GET" class="filters-toolbar">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" placeholder="Search Number or Customer..." value="<?= h($search) ?>" onchange="this.form.submit()">
                </div>
                
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all">All Status</option>
                    <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="sent" <?= $status == 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="accepted" <?= $status == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                    <option value="rejected" <?= $status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="converted" <?= $status == 'converted' ? 'selected' : '' ?>>Converted</option>
                </select>
            </form>

            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th>Quote #</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Expiry</th>
                        <th style="text-align: right;">Amount</th>
                        <th>Status</th>
                        <th style="width: 100px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quotes)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 48px; color: #6b7280;">
                                <div style="font-size: 3rem; margin-bottom: 16px; color: #d1d5db;"><i class="fas fa-file-alt"></i></div>
                                <h3 style="margin-bottom: 8px; font-weight: 500;">No quotations found</h3>
                                <p>Create quotations to send estimates to customers.</p>
                                <a href="create-quote.php" class="btn btn-primary" style="margin-top: 16px;">
                                    <i class="fas fa-plus"></i> New Quotation
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quotes as $q): $canConvert = ($q['status'] === 'accepted' || $q['status'] === 'sent'); ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="quote-checkbox" value="<?= $q['id'] ?>" data-status="<?= $q['status'] ?>" onchange="updateBulkButton()" <?= $canConvert ? '' : 'disabled' ?>>
                                </td>
                                <td style="font-weight: 500; font-family: monospace; color: #111827;"><?= htmlspecialchars($q['quote_number']) ?></td>
                                <td><?= htmlspecialchars($q['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($q['quote_date'] ?? $q['created_at'])) ?></td>
                                <td><?= isset($q['expiry_date']) && $q['expiry_date'] ? date('M d, Y', strtotime($q['expiry_date'])) : '-' ?></td>
                                <td style="font-weight: 600; text-align: right;">TSh <?= number_format($q['total_amount'], 2) ?></td>
                                <td>
                                    <?php $statusClass = ['draft' => 'badge-warning', 'sent' => 'badge-info', 'accepted' => 'badge-success', 'rejected' => 'badge-danger', 'converted' => 'badge-success']; ?>
                                    <span class="badge <?= $statusClass[$q['status']] ?? 'badge-info' ?>"><?= ucfirst($q['status']) ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <div class="dropdown">
                                        <a href="view-quote.php?id=<?= $q['id'] ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button class="btn-icon" onclick="toggleDropdown(this)">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a href="view-quote.php?id=<?= $q['id'] ?>" class="dropdown-item">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <!-- Additional actions could go here -->
                                            <?php if ($canConvert): ?>
                                                <button onclick="convertToInvoice(<?= $q['id'] ?>)" class="dropdown-item">
                                                    <i class="fas fa-file-invoice"></i> Convert
                                                </button>
                                            <?php endif; ?>
                                            <button class="dropdown-item" style="color: #dc2626;">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function toggleDropdown(btn) {
        // Close all other dropdowns
        document.querySelectorAll('.dropdown.active').forEach(el => {
            if (el !== btn.parentElement) el.classList.remove('active');
        });
        btn.parentElement.classList.toggle('active');
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown.active').forEach(el => el.classList.remove('active'));
        }
    });

    function toggleSelectAll(checkbox) {
        const checkboxes = document.querySelectorAll('.quote-checkbox:not([disabled])');
        checkboxes.forEach(cb => cb.checked = checkbox.checked);
        updateBulkButton();
    }

    function updateBulkButton() {
        const selected = document.querySelectorAll('.quote-checkbox:checked');
        const count = selected.length;
        const bulkBtn = document.getElementById('bulkInvoiceBtn');
        const countSpan = document.getElementById('selectedCount');
        
        countSpan.textContent = count;
        bulkBtn.style.display = count > 0 ? 'inline-block' : 'none';
        
        // Update "Select All" checkbox state
        const allCheckboxes = document.querySelectorAll('.quote-checkbox:not([disabled])');
        const selectAllCheckbox = document.getElementById('selectAll');
        if (allCheckboxes.length > 0) {
            selectAllCheckbox.checked = count === allCheckboxes.length;
            selectAllCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
        }
    }

    async function bulkConvertToInvoice() {
        const selected = Array.from(document.querySelectorAll('.quote-checkbox:checked')).map(cb => cb.value);
        
        if (selected.length === 0) {
            alert('Please select at least one quotation');
            return;
        }
        
        if (!confirm(`Convert ${selected.length} quotation(s) to invoice(s)?`)) return;
        
        const bulkBtn = document.getElementById('bulkInvoiceBtn');
        const originalText = bulkBtn.innerHTML;
        bulkBtn.disabled = true;
        bulkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting...';
        
        let successCount = 0;
        let failCount = 0;
        const errors = [];
        
        for (const quoteId of selected) {
            try {
                const formData = new FormData();
                formData.append('action', 'convert_to_invoice');
                formData.append('id', quoteId);
                
                const response = await fetch('../api/quotes.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    successCount++;
                } else {
                    failCount++;
                    errors.push(`Quote #${quoteId}: ${result.message}`);
                }
            } catch (error) {
                failCount++;
                errors.push(`Quote #${quoteId}: ${error.message}`);
            }
        }
        
        bulkBtn.disabled = false;
        bulkBtn.innerHTML = originalText;
        
        let message = `Successfully converted ${successCount} quotation(s) to invoice(s).`;
        if (failCount > 0) {
            message += `\n\nFailed: ${failCount}\n${errors.join('\n')}`;
        }
        
        alert(message);
        location.reload();
    }

    async function convertToInvoice(id) { 
        if (!confirm('Convert this quotation to an invoice?')) return; 
        try { 
            const formData = new FormData(); 
            formData.append('action', 'convert_to_invoice'); 
            formData.append('id', id); 
            const response = await fetch('../api/quotes.php', { method: 'POST', body: formData }); 
            const result = await response.json(); 
            if (result.success) { 
                alert('Quotation converted to invoice successfully!'); 
                window.location.href = 'view-invoice.php?id=' + result.invoice_id; 
            } else { 
                alert('Failed: ' + result.message); 
            } 
        } catch (error) { 
            alert('Error: ' + error.message); 
        } 
    }
</script>
</body>
</html>
