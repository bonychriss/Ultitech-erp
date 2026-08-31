<?php
require_once 'includes/functions.php';
requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$revenueListUrl = function_exists('company_url')
    ? company_url('revenue_entries.php') . '?module=revenue'
    : 'revenue_entries.php?module=revenue';

// Get ID
$id = $_GET['id'] ?? 0;
if (!$id) {
    header("Location: " . $revenueListUrl . "&error=" . urlencode('Invalid ID'));
    exit;
}

// Fetch Entry
try {
    $stmt = $pdo->prepare("SELECT * FROM revenue_entries WHERE id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        header("Location: " . $revenueListUrl . "&error=" . urlencode('Entry not found'));
        exit;
    }

    if (!empty($entry['source_invoice_id']) && (int) $entry['source_invoice_id'] > 0) {
        header('Location: ' . $revenueListUrl . '&error=' . urlencode('Sales invoices cannot be edited here. Update them from Sales.'));
        exit;
    }

    // Check Access: If Ratified, only Admin can edit. If Pending, both Finance and Admin.
    $isRatified = ($entry['approval_status'] === 'Ratified' || $entry['approval_status'] === 'Approved');
    
    if ($isRatified && !isAdmin()) {
        header("Location: " . $revenueListUrl . "&error=" . urlencode('This entry is locked and can only be edited by an Admin.'));
        exit;
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$pageTitle = "Edit Revenue Entry";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Staff Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        :root {
            --primary-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --accent-color: #2563eb;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #d97706;
            --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --card-radius: 16px;
        }

        body.revenue-page { 
            background: #f8fafc; 
            font-family: 'Poppins', sans-serif !important; 
            font-weight: 300 !important;
            font-size: 0.85rem;
            color: #1e293b;
        }

        h1, h2, h3, h4, h5, h6, .fw-bold { 
            font-weight: 500 !important; 
            color: #0f172a;
        }

        .main-content {
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
        }

        .rev-card {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            border: none;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .rev-card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f1f5f9;
            background: #fff;
        }

        .rev-card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600 !important;
            color: #0f172a;
        }

        .rev-card-body {
            padding: 2rem;
        }

        /* Form Controls */
        .rev-form-group {
            margin-bottom: 1.5rem;
        }

        .rev-form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .rev-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            color: #1e293b;
            transition: all 0.2s;
            outline: none;
            background: #fcfdfe;
        }

        .rev-input:focus {
            border-color: var(--accent-color);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .rev-grid-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        /* Buttons */
        .rev-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .rev-btn-primary {
            background: #0f172a;
            color: #fff;
        }

        .rev-btn-primary:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .rev-btn-outline {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
        }

        .rev-btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Summary Box */
        .summary-box {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 2rem;
        }

        @media (max-width: 640px) {
            .rev-grid-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .main-content {
                padding: 1rem;
            }
        }
    </style>
    <?php require __DIR__ . '/includes/nav-back-script.php'; ?>
</head>
<body class="revenue-page">

<?php require_once 'includes/header_employee.php'; ?>

<main class="main-content">
    <div class="rev-header" style="margin-bottom: 2rem;">
        <h1 style="font-size:1.75rem; font-weight:700; color:#0f172a; margin:0;">
            Edit Revenue Entry
            <?php if($isRatified): ?>
                <span style="font-size:0.75rem; background:#fee2e2; color:#b91c1c; padding:0.4rem 1rem; border-radius:99px; margin-left:0.75rem; vertical-align:middle; text-transform:uppercase; font-weight:700; border:1px solid #fecaca;">
                    <i class="fas fa-lock"></i> Ratified
                </span>
            <?php endif; ?>
        </h1>
        <p style="margin:0.25rem 0 0 0; color:#64748b; font-size:0.9rem;">
            <a href="<?= htmlspecialchars($revenueListUrl) ?>" class="erp-nav-back-link" style="color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem; font-weight: 500;">
                <i class="fas fa-arrow-left" style="font-size: 0.8rem;"></i> Back to Dashboard
            </a>
        </p>
    </div>

    <?php if(isset($_GET['error'])): ?>
        <div style="background:#fee2e2; color:#b91c1c; padding:1rem; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.75rem; border:1px solid #fecaca; font-size: 0.9rem;">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <div class="rev-card">
        <div class="rev-card-header">
            <h2>Transaction Details</h2>
        </div>
        <div class="rev-card-body">
            <form action="revenue_process.php" method="POST" enctype="multipart/form-data" onsubmit="return handleFormSubmit(this);">
                <input type="hidden" name="action" value="update_entry">
                <input type="hidden" name="id" value="<?= $entry['id'] ?>">

                <div class="rev-grid-row">
                    <div class="rev-form-group">
                        <label>Transaction Date</label>
                        <input type="date" name="entry_date" value="<?= $entry['entry_date'] ?>" class="rev-input" required>
                    </div>
                    <div class="rev-form-group">
                        <label>Voucher Number</label>
                        <input type="text" name="voucher_number" value="<?= htmlspecialchars($entry['voucher_number']) ?>" class="rev-input" required>
                    </div>
                </div>

                <div class="rev-form-group">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($entry['customer_name']) ?>" class="rev-input" required placeholder="Full name of customer">
                </div>

                <div class="rev-form-group">
                    <label>Narration / Description</label>
                    <textarea name="narration" rows="3" class="rev-input" style="resize: none;" placeholder="Explain the transaction..."><?= htmlspecialchars($entry['narration']) ?></textarea>
                </div>

                <div class="rev-grid-row">
                    <div class="rev-form-group">
                        <label>Amount Exclusive (TZS)</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:1rem; top:50%; transform:translateY(-50%); color:#94a3b8; font-weight:600; font-size:0.8rem;">TZS</span>
                            <input type="number" step="0.01" name="amount_exclusive" id="amt_excl" class="rev-input" style="padding-left:3.25rem;" value="<?= $entry['amount_exclusive'] ?>" required oninput="calculateVAT()">
                        </div>
                    </div>
                    <div class="rev-form-group">
                        <label>Attachment Update</label>
                        <?php if($entry['attachment']): ?>
                            <div style="margin-bottom:0.75rem;">
                                <a href="<?= $entry['attachment'] ?>" target="_blank" style="color:#2563eb; text-decoration:none; font-size:0.8rem; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fas fa-file-alt"></i> Current File
                                </a>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="attachment" class="rev-input" style="padding: 0.6rem;" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>

                <div class="summary-box">
                    <div style="display:flex; justify-content:space-between; margin-bottom:0.75rem;">
                        <span style="font-size:0.85rem; color:#64748b; font-weight: 500;">VAT (18%)</span>
                        <span style="font-size:0.95rem; font-weight:600; color:#1e293b;" id="vat_label"><?= number_format($entry['vat_amount'], 2) ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding-top:0.75rem; border-top:1px solid #e2e8f0;">
                        <span style="font-size:0.95rem; font-weight:700; color:#0f172a;">UPDATED TOTAL</span>
                        <span style="font-size:1.1rem; font-weight:800; color:#059669;" id="total_label"><?= number_format($entry['amount_total'], 2) ?></span>
                    </div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:1rem; margin-top:2rem;">
                    <a href="<?= htmlspecialchars($revenueListUrl) ?>" class="rev-btn rev-btn-outline erp-nav-back-link" style="min-width: 120px;">Cancel</a>
                    <button type="submit" class="rev-btn rev-btn-primary" style="min-width: 160px; background: #059669;">
                        <i class="fas fa-save"></i> Update Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
function calculateVAT() {
    const excl = parseFloat(document.getElementById('amt_excl').value) || 0;
    const vat = excl * 0.18;
    const total = excl + vat;
    
    document.getElementById('vat_label').innerText = vat.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('total_label').innerText = total.toLocaleString('en-US', {minimumFractionDigits: 2});
}
</script>

</body>
</html>
