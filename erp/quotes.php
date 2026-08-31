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
    if ($status !== 'all') { $sql .= " AND q.status = ?"; $params[] = $status; }
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
<head><meta charset="UTF-8"><title>Quotation - ERP</title><link rel="stylesheet" href="../../assets/css/style.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><style>* { margin:0; padding:0; box-sizing:border-box; } body { background:#f5f5f5; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } .page-wrapper { margin-left:220px; min-height:100vh; } @media (max-width:768px){ .page-wrapper { margin-left:0; } } .header { background:#fff; border-bottom:1px solid #e0e0e0; padding:16px 24px; display:flex; justify-content:space-between; align-items:center; } .header h1 { font-size:1.5rem; font-weight:500; } .container { max-width:1400px; margin:0 auto; padding:24px; } .card { background:white; border-radius:8px; border:1px solid #e0e0e0; overflow:hidden; } .card-header { padding:20px 24px; border-bottom:1px solid #e0e0e0; } .filters { display:flex; gap:12px; } .filter-select { padding:10px 16px; border:1px solid #dadce0; border-radius:4px; font-size:0.875rem; background:white; } .table { width:100%; border-collapse:collapse; } .table th { text-align:left; padding:12px 16px; font-size:0.75rem; font-weight:500; color:#5f6368; text-transform:uppercase; border-bottom:1px solid #e0e0e0; background:#f8f9fa; } .table td { padding:16px; border-bottom:1px solid #f1f3f4; } .table tr:hover { background:#f8f9fa; } .btn { padding:8px 16px; border-radius:4px; text-decoration:none; font-size:0.875rem; font-weight:500; cursor:pointer; border:none; display:inline-block; } .btn-primary { background:#1a73e8; color:white; } .btn-secondary { background:#fff; color:#202124; border:1px solid #dadce0; } .btn-success { background:#137333; color:white; padding:4px 12px; font-size:0.75rem; } .badge { display:inline-block; padding:4px 12px; border-radius:12px; font-size:0.75rem; font-weight:500; } .badge-warning { background:#fef7e0; color:#b06000; } .badge-success { background:#e6f4ea; color:#137333; } .badge-info { background:#e8f0fe; color:#1967d2; } .badge-danger { background:#fce8e6; color:#c5221f; }</style></head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
<div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions"><a href="../index.php" class="btn btn-secondary">← Back</a><button id="bulkInvoiceBtn" class="btn btn-success" style="display:none;" onclick="bulkConvertToInvoice()"><i class="fas fa-file-invoice"></i> Generate Invoices (<span id="selectedCount">0</span>)</button><a href="create-quote.php" class="btn btn-primary">+ New Quotation</a></div></div><div class="container">
<?php if ($error_message): ?>
    <div class="card" style="padding: 20px; background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; margin-bottom: 20px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>
<div class="card"><div class="card-header"><form method="GET" class="filters"><select name="status" class="filter-select" onchange="this.form.submit()"><option value="all">All Status</option><option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option><option value="sent" <?= $status == 'sent' ? 'selected' : '' ?>>Sent</option><option value="accepted" <?= $status == 'accepted' ? 'selected' : '' ?>>Accepted</option><option value="rejected" <?= $status == 'rejected' ? 'selected' : '' ?>>Rejected</option><option value="converted" <?= $status == 'converted' ? 'selected' : '' ?>>Converted</option></select></form></div><table class="table"><thead><tr><th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th><th>Quote #</th><th>Customer</th><th>Date</th><th>Expiry</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if (empty($quotes)): ?><tr><td colspan="8" style="text-align: center; padding: 48px; color: #5f6368;"><div style="font-size: 4rem; margin-bottom: 16px;"><i class="fas fa-file-alt"></i></div><h3>No quotations found</h3><p>Create quotations to send estimates to customers.</p><a href="create-quote.php" class="btn btn-primary" style="margin-top: 16px;">+ New Quotation</a></td></tr><?php else: ?><?php foreach ($quotes as $q): $canConvert = ($q['status'] === 'accepted' || $q['status'] === 'sent'); ?><tr><td><input type="checkbox" class="quote-checkbox" value="<?= $q['id'] ?>" data-status="<?= $q['status'] ?>" onchange="updateBulkButton()" <?= $canConvert ? '' : 'disabled' ?>></td><td><?= htmlspecialchars($q['quote_number']) ?></td><td><?= htmlspecialchars($q['customer_name']) ?></td><td><?= date('M d, Y', strtotime($q['quote_date'] ?? $q['created_at'])) ?></td><td><?= isset($q['expiry_date']) && $q['expiry_date'] ? date('M d, Y', strtotime($q['expiry_date'])) : '-' ?></td><td style="font-weight: 600;">TSh <?= number_format($q['total_amount'], 2) ?></td><td><?php $statusClass = ['draft' => 'badge-warning', 'sent' => 'badge-info', 'accepted' => 'badge-success', 'rejected' => 'badge-danger', 'converted' => 'badge-success']; ?><span class="badge <?= $statusClass[$q['status']] ?? 'badge-info' ?>"><?= ucfirst($q['status']) ?></span></td><td><a href="view-quote.php?id=<?= $q['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a> <?php if ($canConvert): ?><button onclick="convertToInvoice(<?= $q['id'] ?>)" class="btn btn-success">Convert</button><?php endif; ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div><script>
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

async function convertToInvoice(id) { if (!confirm('Convert this quotation to an invoice?')) return; try { const formData = new FormData(); formData.append('action', 'convert_to_invoice'); formData.append('id', id); const response = await fetch('../api/quotes.php', { method: 'POST', body: formData }); const result = await response.json(); if (result.success) { alert('Quotation converted to invoice successfully!'); window.location.href = 'view-invoice.php?id=' + result.invoice_id; } else { alert('Failed: ' + result.message); } } catch (error) { alert('Error: ' + error.message); } }</script></body>
</html>
