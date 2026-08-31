<?php
require_once '../../includes/functions.php';
requireFinanceOrAdmin(); // Only finance users and admins can access
ensurePettyCashSchema();

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// Get voucher ID
$voucher_id = (int)($_GET['id'] ?? 0);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve') {
        if (approvePettyCashVoucher($voucher_id, $user_id)) {
            header('Location: index.php?success=approved');
            exit;
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if (rejectPettyCashVoucher($voucher_id, $user_id, $reason)) {
            header('Location: index.php?success=rejected');
            exit;
        }
    }
}

// Get voucher details
$voucher = getPettyCashVoucher($voucher_id);

if (!$voucher) {
    header('Location: index.php?error=not_found');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Voucher - <?= htmlspecialchars($voucher['voucher_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header {
            background: #ffffff;
            color: #111827;
            box-shadow: 0 1px 0 rgba(17,24,39,0.06);
            position: sticky;
            top: 0;
            z-index: 999;
        }
        .header-content {
            border-bottom: 3px solid #f4b400;
            padding: 8px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .company-logo-img {
            height: 45px;
            width: auto;
        }
        .header-info h1 {
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            letter-spacing: 0.5px;
        }
        
        .main-content {
            padding: 24px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        h2 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #111827;
        }
        
        .voucher-number {
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .section {
            background: white;
            padding: 24px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .detail-row {
            display: grid;
            grid-template-columns: 180px 1fr;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }
        .detail-value {
            color: #111827;
            font-size: 14px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-rejected {
            background: #fce8e6;
            color: #c5221f;
        }
        
        .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #1a73e8;
            color: white;
        }
        .btn-success {
            background: #059669;
            color: white;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-secondary {
            background: #fff;
            color: #202124;
            border: 1px solid #dadce0;
        }
        .btn:hover {
            opacity: 0.9;
        }
        
        .icon {
            width: 18px;
            height: 18px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        .reject-form {
            display: none;
            margin-top: 16px;
            padding: 16px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 4px;
        }
        .reject-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
            margin-bottom: 12px;
            min-height: 80px;
        }
        
        .receipt-preview {
            max-width: 100%;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 8px;
        }
        
        @media (max-width: 768px) {
            .header-content { padding: 6px 12px; }
            .company-logo-img { height: 36px; }
            .header-info h1 { font-size: 12px; }
            .main-content { padding: 16px; }
            .detail-row { grid-template-columns: 1fr; gap: 4px; }
                <div class="detail-value"><?= date('F d, Y', strtotime($voucher['date'])) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Category</div>
                <div class="detail-value"><?= htmlspecialchars($voucher['category']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Amount</div>
                <div class="detail-value" style="font-size: 18px; font-weight: 600; color: #059669;">
                    TSh <?= number_format($voucher['amount'], 2) ?>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Description</div>
                <div class="detail-value"><?= nl2br(htmlspecialchars($voucher['description'])) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Custodian</div>
                <div class="detail-value"><?= htmlspecialchars($voucher['custodian_name']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created By</div>
                <div class="detail-value"><?= htmlspecialchars($voucher['created_by_name']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= date('F d, Y H:i', strtotime($voucher['created_at'])) ?></div>
            </div>
            
            <?php if ($voucher['approved_by']): ?>
                <div class="detail-row">
                    <div class="detail-label">Approved/Rejected By</div>
                    <div class="detail-value"><?= htmlspecialchars($voucher['approved_by_name']) ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Approved/Rejected At</div>
                    <div class="detail-value"><?= date('F d, Y H:i', strtotime($voucher['approved_at'])) ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($voucher['rejection_reason']): ?>
                <div class="detail-row">
                    <div class="detail-label">Rejection Reason</div>
                    <div class="detail-value" style="color: #dc2626;"><?= nl2br(htmlspecialchars($voucher['rejection_reason'])) ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($voucher['receipt_path']): ?>
                <div class="detail-row">
                    <div class="detail-label">Receipt</div>
                    <div class="detail-value">
                        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                            <a href="../../<?= htmlspecialchars($voucher['receipt_path']) ?>" target="_blank" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 16px; height: 16px;">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                View Full Size
                            </a>
                            <a href="../../<?= htmlspecialchars($voucher['receipt_path']) ?>" download class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 16px; height: 16px;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <polyline points="7 10 12 15 17 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Download
                            </a>
                        </div>
                        <div style="border: 1px solid #ddd; border-radius: 4px; padding: 8px; background: #f9f9f9;">
                            <img src="../../<?= htmlspecialchars($voucher['receipt_path']) ?>" alt="Receipt" class="receipt-preview" style="max-width: 100%; cursor: pointer;" onclick="window.open('../../<?= htmlspecialchars($voucher['receipt_path']) ?>', '_blank')">
                        </div>
                        <small style="color: #666; margin-top: 8px; display: block;">Click image to view full size</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($is_admin && $voucher['status'] === 'pending'): ?>
            <div class="action-buttons" style="display: flex; gap: 16px; margin-top: 24px;">
                <button type="button" onclick="document.getElementById('approveForm').submit()" style="background: none; border: none; color: #059669; font-weight: 600; cursor: pointer; padding: 0; text-decoration: underline; font-size: 14px; display: inline-flex; align-items: center; gap: 6px;">
                    <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Approve
                </button>
                <button type="button" onclick="showRejectForm()" style="background: none; border: none; color: #dc2626; font-weight: 600; cursor: pointer; padding: 0; text-decoration: underline; font-size: 14px; display: inline-flex; align-items: center; gap: 6px;">
                    <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Reject
                </button>
            </div>
            
            <form method="POST" id="approveForm" style="display: none;">
                <input type="hidden" name="action" value="approve">
            </form>
            
            <div id="rejectForm" class="reject-form">
                <form method="POST">
                    <input type="hidden" name="action" value="reject">
                    <label style="display: block; font-weight: 500; margin-bottom: 8px; color: #92400e;">Rejection Reason *</label>
                    <textarea name="reason" required placeholder="Explain why this voucher is being rejected..."></textarea>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                        <button type="button" class="btn btn-secondary" onclick="hideRejectForm()">Cancel</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
    
    <script>
        function showRejectForm() {
            document.getElementById('rejectForm').style.display = 'block';
        }
        function hideRejectForm() {
            document.getElementById('rejectForm').style.display = 'none';
        }
    </script>
</body>
</html>

