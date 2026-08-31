<?php
require_once '../includes/functions.php';
requireAdmin();

$companyId = (int) (currentCompanyId() ?? 0);
if ($companyId <= 0) {
    die("Error: No active company context.");
}

$year = 2026;
$success = $error = null;

// Get configured prefix
$configuredPrefix = getCurrentPaymentVoucherSequencePrefix($pdo, $companyId, $year);
if ($configuredPrefix === '') {
    // Determine default prefix if not configured
    $code = 'UGT';
    if ($companyId === 2) { $code = 'RMS'; }
    // Check if the company currently has any vouchers with a particular prefix to detect default
    $configuredPrefix = "PV/{$code}/{$year}/";
}

$targetPrefix = rtrim($configuredPrefix, '/') . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch all vouchers of the current company for the given year
        $yearLike = "%/{$year}/%";
        $stmt = $pdo->prepare("
            SELECT id, voucher_no, date_created 
            FROM payment_vouchers 
            WHERE (voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%') AND voucher_no LIKE ?
            ORDER BY id ASC
        ");
        $stmt->execute([$yearLike]);
        $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($vouchers)) {
            throw new Exception("No vouchers found for year {$year}.");
        }
        
        $padding = 3;
        
        // 2. Temporarily rename all of them to avoid unique key constraints
        $tempStmt = $pdo->prepare("UPDATE payment_vouchers SET voucher_no = ? WHERE id = ?");
        foreach ($vouchers as $v) {
            $tempNo = 'TEMP_MIG_' . $v['id'] . '_' . rand(1000, 9999);
            $tempStmt->execute([$tempNo, $v['id']]);
        }
        
        // 3. Rename them sequentially to the target prefix based on ID ASC (chronological order)
        $updateStmt = $pdo->prepare("UPDATE payment_vouchers SET voucher_no = ? WHERE id = ?");
        $seq = 1;
        $changes = [];
        foreach ($vouchers as $v) {
            $newNo = $targetPrefix . str_pad((string) $seq, $padding, '0', STR_PAD_LEFT);
            $updateStmt->execute([$newNo, $v['id']]);
            $changes[] = [
                'id' => $v['id'],
                'old' => $v['voucher_no'],
                'new' => $newNo
            ];
            $seq++;
        }
        
        // 4. Update or insert next sequence number in document_sequences
        $seqPdo = documentSequencesPdo($pdo);
        if ($seqPdo instanceof PDO) {
            $upSeq = $seqPdo->prepare("
                UPDATE document_sequences 
                SET next_number = ?, prefix = ?, updated_at = NOW() 
                WHERE company_id = ? AND document_type = 'payment_voucher' AND year = ?
            ");
            $upSeq->execute([$seq, $targetPrefix, $companyId, $year]);
            
            if ($upSeq->rowCount() === 0) {
                $ins = $seqPdo->prepare("
                    INSERT INTO document_sequences (company_id, document_type, prefix, next_number, padding, year) 
                    VALUES (?, 'payment_voucher', ?, ?, ?, ?)
                ");
                $ins->execute([$companyId, $targetPrefix, $seq, $padding, $year]);
            }
        }
        
        $pdo->commit();
        $success = "Successfully renumbered " . count($vouchers) . " vouchers sequentially under prefix <strong>" . htmlspecialchars($targetPrefix) . "</strong>.";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Fetch current vouchers in 2026
$yearLike = "%/{$year}/%";
$stmt = $pdo->prepare("
    SELECT id, voucher_no, payee_name, date_created, status, total_amount, currency
    FROM payment_vouchers 
    WHERE (voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%') AND voucher_no LIKE ?
    ORDER BY id ASC
");
$stmt->execute([$yearLike]);
$currentVouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chronological Voucher Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f7f7f7; font-family: 'Inter', sans-serif; padding: 40px 20px; }
        .card { border-radius: 8px; border: 1px solid #ededed; box-shadow: 0 4px 12px rgba(0,0,0,0.05); background: white; margin-bottom: 30px; }
        .card-header { background: #1e293b; color: white; border-bottom: none; font-weight: 600; padding: 15px 20px; }
        .table-wrap { overflow-x: auto; }
        .data-table { width: 100%; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
        .data-table th { background: #f8fafc; font-weight: 600; font-size: 12px; color: #475569; text-transform: uppercase; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        .data-table td { font-size: 13px; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .data-table tr:hover td { background: #f1f5f9; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 900px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold m-0" style="font-size: 22px; color: #1e293b;">Chronological Voucher Migration</h2>
            <a href="all-vouchers.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Vouchers</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Migration Action</span>
                <span class="badge bg-secondary">Year <?= $year ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    This utility renumbers all vouchers for the current company and year (<?= $year ?>) chronologically in order of creation (ID).
                    This eliminates sequence "mixes" and duplicates when prefix configuration is updated.
                </p>
                <div class="row mb-3 bg-light p-3 rounded mx-0 small">
                    <div class="col-md-6"><strong>Current Company ID:</strong> <?= $companyId ?></div>
                    <div class="col-md-6"><strong>Target Prefix:</strong> <code><?= htmlspecialchars($targetPrefix) ?></code></div>
                </div>
                
                <form method="POST" onsubmit="return confirm('WARNING: This will permanently renumber all <?= count($currentVouchers) ?> vouchers for this company and year in the database. Are you sure you want to proceed?');">
                    <input type="hidden" name="run_migration" value="1">
                    <button type="submit" class="btn btn-danger w-100 fw-bold"><i class="fas fa-sync-alt me-1"></i> Renumber Vouchers Chronologically</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Current Vouchers in database (<?= count($currentVouchers) ?> found)</div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Voucher No.</th>
                            <th>Payee</th>
                            <th>Amount</th>
                            <th>Date Created</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentVouchers as $cv): ?>
                            <tr>
                                <td><?= $cv['id'] ?></td>
                                <td><strong><?= htmlspecialchars($cv['voucher_no']) ?></strong></td>
                                <td><?= htmlspecialchars($cv['payee_name']) ?></td>
                                <td><?= htmlspecialchars($cv['currency']) ?> <?= number_format($cv['total_amount'], 2) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($cv['date_created'])) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= ucfirst($cv['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
