<?php
/**
 * Supplier portal for stocks_purchase_orders (supplier-link & standard stock POs with token).
 * Expects: $pdo (PDO), $token (string) from supplier_response.php
 */
declare(strict_types=1);

require_once __DIR__ . '/purchase_workflow.php';
require_once __DIR__ . '/../../config/functions.php';

$token = trim((string) $token);
$po = loadStockPoByPublicToken($pdo, $token);

if (!$po) {
    die('Invalid Access: Order not found.');
}

ensureStocksPurchaseOrdersWorkflowColumns($pdo);
ensurePurchaseWorkflowSchema($pdo);

// Refresh workflow column if missing on row
if (!isset($po['procurement_workflow'])) {
    $po['procurement_workflow'] = PURCHASE_PROC_STANDARD;
}

if (!empty($po['token_expiry']) && strtotime((string) $po['token_expiry']) < time()) {
    die('Access Denied: This quote request link has expired.');
}

$workflow = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
$isLink = isSupplierLinkWorkflow($workflow);

if ($isLink && ($po['status'] ?? '') === PURCHASE_STATUS_DRAFT) {
    die('This purchase order has not been sent to suppliers yet. Please wait for the buyer to release it.');
}

$writableStatuses = supplierPortalWritableStatuses($workflow);
$isWritable = in_array($po['status'] ?? '', $writableStatuses, true);

$settings = getCompanySettings($pdo);
$rate = (float) ($settings['exchange_rate'] ?? 1);
if ($rate <= 0) {
    $rate = 1.0;
}

$success_msg = '';
$error_msg = '';
$hasErrors = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rate_service') {
    header('Content-Type: application/json');
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($rating >= 1 && $rating <= 5) {
        try {
            $pdo->prepare('INSERT INTO supplier_ratings (purchase_id, rating, comment) VALUES (?, ?, ?)')
                ->execute([(int) $po['id'], $rating, $comment]);
            echo json_encode(['status' => 'success']);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error']);
        }
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_accept') {
    if ($isLink) {
        $error_msg = 'Quick accept is not available for this order. Please enter unit prices for each line.';
    } elseif (in_array($po['status'] ?? '', [PURCHASE_STATUS_PENDING, PURCHASE_STATUS_NEGOTIATION], true)) {
        $nextStatus = PURCHASE_STATUS_SUPPLIER_RESPONDED;
        $pdo->prepare('UPDATE stocks_purchase_orders SET status = ?, supplier_responded_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$nextStatus, $po['id']]);
        require_once __DIR__ . '/po_mailer.php';
        sendPOStatusEmail((int) $po['id'], 'quote_received', $pdo);
        header('Location: ?token=' . rawurlencode($token) . '&quick_accepted=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items']) && is_array($_POST['items'])) {
    if (!$isWritable) {
        die('Error: This quote is closed for editing.');
    }
    foreach ($_POST['items'] as $lineId => $data) {
        $lineId = (int) $lineId;
        $price = (float) ($data['price'] ?? 0);
        if ($price <= 0) {
            $error_msg = 'All items must have a valid unit price greater than 0.';
            $hasErrors = true;
            break;
        }
        $priceUsd = $price / $rate;
        $chk = $pdo->prepare('SELECT id, qty_ordered FROM stocks_po_items WHERE id = ? AND po_id = ?');
        $chk->execute([$lineId, $po['id']]);
        $line = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            continue;
        }
        $qty = (float) $line['qty_ordered'];
        $pdo->prepare('UPDATE stocks_po_items SET unit_cost = ?, landed_cost = ? WHERE id = ? AND po_id = ?')
            ->execute([$priceUsd, $priceUsd, $lineId, $po['id']]);
    }

    if (!$hasErrors) {
        if (isset($_FILES['invoice']) && $_FILES['invoice']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $ext = strtolower(pathinfo((string) $_FILES['invoice']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $uploadDir = __DIR__ . '/../../uploads/invoices/' . $po['id'] . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newFilename = 'invoice_' . date('Ymd_His') . '.' . $ext;
                $dest = $uploadDir . $newFilename;
                if (move_uploaded_file($_FILES['invoice']['tmp_name'], $dest)) {
                    $dbPath = 'uploads/invoices/' . $po['id'] . '/' . $newFilename;
                    $pdo->prepare('UPDATE stocks_purchase_orders SET invoice_attachment = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$dbPath, $po['id']]);
                    $po['invoice_attachment'] = $dbPath;
                } else {
                    $error_msg = 'Failed to upload file.';
                    $hasErrors = true;
                }
            } else {
                $error_msg = 'Invalid file type. Only PDF and images allowed.';
                $hasErrors = true;
            }
        } elseif (empty($po['invoice_attachment'])) {
            $error_msg = 'Please upload your commercial invoice or quote (PDF or image).';
            $hasErrors = true;
        }
    }

    if (!$hasErrors && $error_msg === '') {
        $next = $isLink ? PURCHASE_STATUS_PENDING_APPROVAL : PURCHASE_STATUS_SUPPLIER_RESPONDED;
        $pdo->prepare('UPDATE stocks_purchase_orders SET status = ?, supplier_responded_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$next, $po['id']]);
        require_once __DIR__ . '/po_mailer.php';
        sendPOStatusEmail((int) $po['id'], 'quote_received', $pdo);
        $success_msg = 'Thank you. Your quote has been submitted. The buyer will review and approve.';
        $stmtR = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE id = ?');
        $stmtR->execute([$po['id']]);
        $po = $stmtR->fetch(PDO::FETCH_ASSOC) ?: $po;
        $po['purchase_no'] = $po['po_number'] ?? $po['purchase_no'] ?? '';
        $isWritable = in_array($po['status'] ?? '', supplierPortalWritableStatuses($po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD), true);
    }
}

$stmtItems = $pdo->prepare(
    'SELECT pi.id AS item_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
            (pi.qty_ordered * pi.unit_cost) AS total_amount,
            si.name AS product_name, si.sku AS product_code, si.description AS product_desc
     FROM stocks_po_items pi
     INNER JOIN stocks_items si ON si.id = pi.item_id
     WHERE pi.po_id = ?
     ORDER BY pi.id ASC'
);
$stmtItems->execute([$po['id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

$po['purchase_no'] = $po['po_number'] ?? $po['purchase_no'] ?? '';
$po['contact_person'] = $po['contact_person'] ?? $po['contact_details'] ?? '';

$companyName = $settings['company_name'] ?? 'Company';
$companyAddress = (string) ($settings['address'] ?? '');
$companyPhone = (string) ($settings['phone'] ?? '');
$companyEmail = (string) ($settings['email'] ?? '');
$displayCurr = $settings['currency'] ?? 'USD';
$currencySymbol = getCurrencySymbol($displayCurr);

$lineTotalUsd = 0.0;
foreach ($items as $it) {
    $lineTotalUsd += (float) ($it['total_amount'] ?? 0);
}

$pageTotalDisplay = $lineTotalUsd * $rate;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote | <?php echo htmlspecialchars($po['purchase_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f1f5f9; font-family: system-ui, sans-serif; }
        .po-card { max-width: 920px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.06); }
    </style>
</head>
<body class="py-4 px-2">
    <div class="container">
        <?php if ($success_msg !== ''): ?>
            <div class="alert alert-success shadow-sm"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger shadow-sm"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (($po['status'] ?? '') === PURCHASE_STATUS_NEGOTIATION): ?>
            <div class="alert alert-warning po-card p-4 mb-3">
                <h5 class="fw-bold"><i class="fas fa-comments me-2"></i>Changes requested</h5>
                <div class="bg-white p-3 rounded border-start border-4 border-warning small"><?php echo nl2br(htmlspecialchars((string) ($po['negotiation_notes'] ?? ''))); ?></div>
                <p class="mb-0 mt-2 small fw-bold">Please update your prices and re-submit.</p>
            </div>
        <?php endif; ?>

        <?php if ($isWritable && !$isLink): ?>
            <div class="alert alert-primary po-card p-3 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><strong>Quick accept</strong> ù confirm the order as listed (no price changes).</div>
                <form method="post" class="m-0" onsubmit="return confirm('Accept this order as requested?');">
                    <input type="hidden" name="action" value="quick_accept">
                    <button type="submit" class="btn btn-primary btn-sm">Accept order</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="po-card p-4 p-md-5">
            <div class="d-flex flex-wrap justify-content-between align-items-start border-bottom pb-3 mb-4 gap-2">
                <div>
                    <h1 class="h4 fw-bold text-uppercase mb-1">Purchase order</h1>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($po['purchase_no']); ?></p>
                </div>
                <div class="text-end">
                    <?php
                    $st = $po['status'] ?? '';
                    $badge = 'bg-secondary';
                    if ($st === PURCHASE_STATUS_APPROVED) {
                        $badge = 'bg-success';
                    } elseif (in_array($st, [PURCHASE_STATUS_SUPPLIER_RESPONDED, PURCHASE_STATUS_PENDING_APPROVAL], true)) {
                        $badge = 'bg-info text-dark';
                    } elseif ($st === PURCHASE_STATUS_NEGOTIATION) {
                        $badge = 'bg-warning text-dark';
                    } elseif ($st === PURCHASE_STATUS_PENDING_SUPPLIER) {
                        $badge = 'bg-primary';
                    }
                    ?>
                    <span class="badge <?php echo $badge; ?> fs-6"><?php echo htmlspecialchars(purchaseDisplayStatusLabel($st, $workflow)); ?></span>
                </div>
            </div>

            <div class="row mb-4 small">
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase fw-bold" style="font-size:.7rem;">Buyer</h6>
                    <strong><?php echo htmlspecialchars($companyName); ?></strong><br>
                    <?php echo nl2br(htmlspecialchars($companyAddress)); ?><br>
                    <?php echo htmlspecialchars($companyPhone); ?> ù <?php echo htmlspecialchars($companyEmail); ?>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <h6 class="text-muted text-uppercase fw-bold" style="font-size:.7rem;">Supplier</h6>
                    <strong><?php echo htmlspecialchars((string) ($po['supplier_name'] ?? '')); ?></strong><br>
                    <?php echo htmlspecialchars((string) ($po['supplier_email'] ?? '')); ?>
                </div>
            </div>

            <?php if ($isWritable): ?>
                <div class="alert alert-light border mb-4 small">
                    <ol class="mb-0 ps-3">
                        <li>Enter your <strong>unit price</strong> per line in <?php echo htmlspecialchars($displayCurr); ?>.</li>
                        <li>Upload your <strong>invoice or quote</strong> (PDF or image).</li>
                        <li>Click <strong>Submit quote</strong>.</li>
                    </ol>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-center" style="width:100px;">Qty</th>
                                <th class="text-end" style="width:140px;">Unit price (<?php echo htmlspecialchars($displayCurr); ?>)</th>
                                <th class="text-end" style="width:120px;">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $qty = (float) $item['quantity'];
                                $unitUsd = (float) $item['unit_price'];
                                $unitDisplay = $unitUsd * $rate;
                                $lineDisp = $qty * $unitDisplay;
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) $item['product_name']); ?></div>
                                        <?php if (!empty($item['product_code'])): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string) $item['product_code']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format($qty, 2); ?></td>
                                    <td class="text-end">
                                        <?php if (!$isWritable): ?>
                                            <span class="fw-bold"><?php echo htmlspecialchars($currencySymbol); ?><?php echo number_format($unitDisplay, 2); ?></span>
                                        <?php else: ?>
                                            <input type="number" step="0.01" min="0.01" class="form-control form-control-sm text-end"
                                                   name="items[<?php echo (int) $item['item_id']; ?>][price]"
                                                   value="<?php echo $unitDisplay > 0 ? htmlspecialchars((string) number_format($unitDisplay, 2, '.', '')) : ''; ?>"
                                                   data-qty="<?php echo htmlspecialchars((string) $qty); ?>" required>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?php echo htmlspecialchars($currencySymbol); ?><?php echo number_format($lineDisp, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td colspan="3" class="text-end">Total (<?php echo htmlspecialchars($displayCurr); ?>)</td>
                                <td class="text-end"><?php echo htmlspecialchars($currencySymbol); ?><?php echo number_format($pageTotalDisplay, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if ($isWritable): ?>
                    <div class="mb-4 p-3 bg-light rounded border">
                        <label class="form-label fw-bold"><i class="fas fa-file-upload me-2"></i>Upload invoice / quote</label>
                        <input type="file" name="invoice" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?php echo empty($po['invoice_attachment']) ? 'required' : ''; ?>>
                        <?php if (!empty($po['invoice_attachment'])): ?>
                            <div class="small text-success mt-2"><i class="fas fa-check"></i> A file is already on file; upload again to replace.</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-success btn-lg px-5 rounded-pill fw-bold">
                            <i class="fas fa-paper-plane me-2"></i>Submit quote
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if (in_array($po['status'] ?? '', [PURCHASE_STATUS_SUPPLIER_RESPONDED, PURCHASE_STATUS_PENDING_APPROVAL, PURCHASE_STATUS_APPROVED], true)): ?>
                <div class="mt-5 pt-4 border-top text-center text-muted small">
                    Thank you for using our supplier portal.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
exit;
