<?php
// modules/balances/view-transaction.php
require_once __DIR__ . '/config/database.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);
if ($company_id <= 0) {
    $_SESSION['error'] = 'Missing company context.';
    header('Location: transactions.php');
    exit;
}

$balancesQs = function (array $extra = []) {
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
};

$qsNoId = function (array $extra = []) {
    $q = array_merge($_GET ?: [], $extra);
    unset($q['id']);
    $qs = http_build_query($q);
    return $qs === '' ? '' : ('?' . $qs);
};

if (!isset($_GET['id']) || (int) $_GET['id'] <= 0) {
    header('Location: transactions.php' . $qsNoId());
    exit;
}

$tx_id = (int) $_GET['id'];

$stmt = $pdo->prepare('
    SELECT t.*, a.name as account_name, a.currency, a.type as account_type, u.full_name as user_name
    FROM account_transactions t
    JOIN financial_accounts a ON t.account_id = a.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.id = ? AND t.company_id = ?
');
$stmt->execute([$tx_id, $company_id]);
$tx = $stmt->fetch();

if (!$tx) {
    $_SESSION['error'] = 'Transaction not found.';
    header('Location: transactions.php' . $qsNoId());
    exit;
}

$isCredit = $tx['type'] === 'credit';

$voucher = null;
if ($tx['reference_type'] === 'payment_voucher' || $tx['reference_type'] === 'voucher') {
    $vStmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? AND company_id = ?');
    $vStmt->execute([$tx['reference_id'], $company_id]);
    $voucher = $vStmt->fetch();
}

$page_title = 'Transaction #' . $tx['id'];

include __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .bal-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .dash-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .tx-detail-label {
        color: #6b7280;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.35rem;
    }
    .tx-detail-value { color: #111827; font-size: 1rem; }
    .hover-lift { transition: all 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 8px 16px -4px rgba(15, 23, 42, 0.12) !important; }
    .voucher-container {
        font-family: "Courier New", Courier, monospace !important;
        font-size: 11px;
        line-height: 1.2;
        padding: 30px;
        background: #fff;
        border: 1px solid #000;
        max-width: 900px;
        margin: 0 auto;
        position: relative;
    }
    .voucher-container table { width: 100%; border-collapse: collapse; margin-bottom: 10px; border: 1px solid #000; }
    .voucher-container table td, .voucher-container table th { border: 1px solid #000; padding: 4px 6px; font-weight: normal; }
    .voucher-container .pv-header h1 { font-size: 22px; margin: 0; letter-spacing: 2px; }
    .stamp-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-15deg);
        opacity: 0.15;
        pointer-events: none;
        z-index: 10;
    }
    .stamp-watermark img { width: 300px; height: auto; }
</style>

<main class="main-content bal-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-receipt text-[#2563EB]"></i><span>Transaction</span>
                    <span class="text-gray-500 fw-normal fs-6">#<?= (int) $tx['id'] ?></span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="transactions.php<?= htmlspecialchars($qsNoId()) ?>" class="btn btn-outline-secondary btn-sm rounded-2">
                    <i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline">Ledger</span>
                </a>
                <a href="transfer.php<?= htmlspecialchars($qsNoId()) ?>" class="btn btn-primary btn-sm rounded-2">
                    <i class="bi bi-arrow-left-right me-1"></i><span class="d-none d-sm-inline">New transfer</span><span class="d-sm-none">Transfer</span>
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <span><i class="fas fa-calendar text-gray-400 me-1"></i><?php echo date('l, d M Y'); ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span><?= date('M d, Y H:i', strtotime($tx['transaction_date'])) ?><?php if (defined('COMPANY_NAME')): ?> · <?= htmlspecialchars(COMPANY_NAME); ?><?php endif; ?></span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="index.php<?= htmlspecialchars($qsNoId()) ?>" class="text-decoration-none">Liquidity</a></li>
                    <li class="breadcrumb-item"><a href="accounts.php<?= htmlspecialchars($qsNoId()) ?>" class="text-decoration-none">Accounts</a></li>
                    <li class="breadcrumb-item"><a href="transactions.php<?= htmlspecialchars($qsNoId()) ?>" class="text-decoration-none">Transactions</a></li>
                    <li class="breadcrumb-item active" aria-current="page">#<?= (int) $tx['id'] ?></li>
                </ol>
            </nav>

            <div class="dash-card mb-4">
                <div class="px-4 py-3 border-bottom border-gray-100 d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="small text-muted mb-1">Recorded <?= date('M d, Y H:i', strtotime($tx['transaction_date'])) ?></div>
                        <div class="fw-bold text-gray-900">Transaction details</div>
                    </div>
                    <?php
                    $bgColor = $isCredit ? '#dcfce7' : '#fee2e2';
                    $textColor = $isCredit ? '#166534' : '#991b1b';
                    ?>
                    <div class="rounded-3 px-3 py-2 fw-semibold fs-5" style="background: <?= $bgColor ?>; color: <?= $textColor ?>;">
                        <?= $isCredit ? '+' : '-' ?> <?= htmlspecialchars($tx['currency']) ?> <?= number_format((float) $tx['amount'], 2) ?>
                    </div>
                </div>
                <div class="p-4">
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <div class="tx-detail-label">Financial account</div>
                            <div class="tx-detail-value d-flex align-items-center">
                                <i class="bi bi-wallet2 me-2 text-primary"></i>
                                <?= htmlspecialchars($tx['account_name']) ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="tx-detail-label">Transaction type</div>
                            <div class="tx-detail-value">
                                <span class="badge bg-<?= $isCredit ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $isCredit ? 'success' : 'danger' ?> border">
                                    <?= $isCredit ? 'Credit (inflow)' : 'Debit (outflow)' ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="tx-detail-label">Recorded by</div>
                            <div class="tx-detail-value"><?= htmlspecialchars($tx['user_name'] ?? 'System') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="tx-detail-label">System audit timestamp</div>
                            <div class="tx-detail-value small text-muted"><?= date('M d, Y H:i:s', strtotime($tx['created_at'])) ?></div>
                        </div>
                    </div>

                    <div class="mb-0">
                        <div class="tx-detail-label">Description</div>
                        <div class="p-3 bg-light rounded-3 border border-gray-100" style="font-size: 0.95rem; line-height: 1.6;">
                            <?php
                            $displayDesc = $tx['description'];
                            if ($voucher && !empty($voucher['description'])) {
                                $displayDesc = $voucher['description'];
                            }
                            echo nl2br(htmlspecialchars($displayDesc));
                            ?>
                        </div>
                    </div>
                </div>
            </div>

<?php if ($voucher):
    $items = [];
    try {
        $itStmt = $pdo->prepare('SELECT * FROM payment_items WHERE voucher_id = ? AND company_id = ? ORDER BY id ASC');
        $itStmt->execute([$voucher['id'], $company_id]);
        $items = $itStmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Payment items query error: ' . $e->getMessage());
        $items = [];
    }

    $approvalsList = [];
    try {
        $apStmt = $pdo->prepare('SELECT * FROM approvals WHERE voucher_id = ? AND company_id = ? ORDER BY created_at ASC, id ASC');
        $apStmt->execute([$voucher['id'], $company_id]);
        $approvalsList = $apStmt->fetchAll();
    } catch (Throwable $e) {
        $approvalsList = [];
    }

    $roleStatusMap = [];
    foreach ($approvalsList as $ap) {
        $r = strtolower(trim($ap['role'] ?? ''));
        if ($r) {
            $roleStatusMap[$r] = strtolower(trim($ap['status'] ?? ''));
        }
    }

    $signaturesByName = [];
    try {
        $uStmt = $pdo->prepare('SELECT lower(full_name) as name, signature_path FROM users WHERE company_id = ? AND signature_path IS NOT NULL');
        $uStmt->execute([$company_id]);
        while ($srow = $uStmt->fetch()) {
            $lowName = strtolower(trim(preg_replace('/\s+/', ' ', $srow['name'])));
            $signaturesByName[$lowName] = app_url('/' . ltrim($srow['signature_path'], '/'));
        }
    } catch (Throwable $se) {
    }

    $isPaid = (int) ($voucher['is_paid'] ?? 0) === 1;
    $isPosted = (int) ($voucher['is_posted'] ?? 0) === 1;

    $attachments = getVoucherAttachments($voucher['id']);
    $voucherViewUrl = app_url('/view-voucher.php') . '?' . http_build_query(['id' => (int) $voucher['id']]);
?>
            <div class="dash-card mb-4">
                <div class="px-4 py-3 border-bottom border-gray-100 fw-bold text-gray-800">
                    <i class="fas fa-file-invoice text-[#2563EB] me-2"></i>Official payment document
                </div>
                <div class="p-4 bg-[#fafafa]">
                    <div class="voucher-container shadow-sm">
                        <?php if ($isPosted): ?>
                            <div class="stamp-watermark"><img src="<?= htmlspecialchars(app_url('/assets/images/posted.png')) ?>" alt="POSTED"></div>
                        <?php elseif ($isPaid): ?>
                            <div class="stamp-watermark"><img src="<?= htmlspecialchars(app_url('/assets/images/IMG_6470.PNG')) ?>" alt="PAID"></div>
                        <?php endif; ?>

                        <div class="pv-header d-flex align-items-center mb-4">
                            <img src="<?= htmlspecialchars(app_url('/assets/images/Untitled.jpg')) ?>" alt="Logo" style="width: 48px; margin-right: 20px;">
                            <h1>PAYMENT VOUCHER</h1>
                        </div>

                        <table>
                            <colgroup><col style="width:18%"><col style="width:32%"><col style="width:18%"><col style="width:32%"></colgroup>
                            <tr><td>Voucher NO. :</td><td><?= htmlspecialchars($voucher['voucher_no']) ?></td><td>Date:</td><td><?= date('Y-m-d', strtotime($voucher['date_created'])) ?></td></tr>
                            <tr><td>Payee Name:</td><td><?= htmlspecialchars($voucher['payee_name']) ?></td><td>Prepared By:</td><td><?= htmlspecialchars(strtoupper($voucher['prepared_by'] ?: 'N/A')) ?></td></tr>
                            <tr><td>Description:</td><td><?= htmlspecialchars($voucher['description'] ?? '') ?></td><td>Supporting Docs:</td><td><?= htmlspecialchars($voucher['supporting_documents'] ?? '0') ?></td></tr>
                            <tr><td>Currency:</td><td><?= htmlspecialchars($voucher['currency']) ?></td><td>Amount:</td><td class="text-end fw-bold"><?= number_format((float) $voucher['total_amount'], 2) ?></td></tr>
                        </table>

                        <table>
                            <thead>
                                <tr><th>Payment Type</th><th>Budget Type</th><th>Name</th><th class="text-end">Amount</th><th>Description</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['payment_type']) ?></td>
                                        <td><?= htmlspecialchars($item['budget_type']) ?></td>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td class="text-end"><?= number_format((float) $item['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars($item['description'] ?: '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <table style="margin-top: 10px;">
                            <colgroup><col style="width:15%"><col style="width:35%"><col style="width:15%"><col style="width:35%"></colgroup>
                            <tr>
                                <td class="text-center">Applicant</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?= htmlspecialchars($voucher['applicant']) ?></span>
                                        <?php
                                        $appKey = strtolower(trim(preg_replace('/\s+/', ' ', ($voucher['applicant'] ?? ''))));
                                        if (!empty($signaturesByName[$appKey]) && isset($roleStatusMap['applicant']) && $roleStatusMap['applicant'] === 'approved'):
                                        ?>
                                            <img src="<?= htmlspecialchars($signaturesByName[$appKey]) ?>" style="max-height:30px;" alt="Sig">
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">Check</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?= htmlspecialchars($voucher['checked_by'] ?? '') ?></span>
                                        <?php
                                        $checkKey = strtolower(trim(preg_replace('/\s+/', ' ', ($voucher['checked_by'] ?? ''))));
                                        if (!empty($signaturesByName[$checkKey]) && isset($roleStatusMap['checked by']) && $roleStatusMap['checked by'] === 'approved'):
                                        ?>
                                            <img src="<?= htmlspecialchars($signaturesByName[$checkKey]) ?>" style="max-height:30px;" alt="Sig">
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center">Dept Manager</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?= htmlspecialchars($voucher['department_manager'] ?? '') ?></span>
                                        <?php
                                        $dmKey = strtolower(trim(preg_replace('/\s+/', ' ', ($voucher['department_manager'] ?? ''))));
                                        if (!empty($signaturesByName[$dmKey]) && isset($roleStatusMap['department manager']) && $roleStatusMap['department manager'] === 'approved'):
                                        ?>
                                            <img src="<?= htmlspecialchars($signaturesByName[$dmKey]) ?>" style="max-height:30px;" alt="Sig">
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">GM</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span>ADMINISTRATOR</span>
                                        <?php if ((int) $voucher['is_paid'] === 1 || strtolower($voucher['status'] ?? '') === 'approved'): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i> APPROVED
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <?php if (!empty($attachments) || !empty($voucher['swift_document'])): ?>
                            <div class="mt-4 border-top pt-3">
                                <h6 class="mb-3 text-muted" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                                    Supporting documents (<?= count($attachments) + (!empty($voucher['swift_document']) ? 1 : 0) ?>)
                                </h6>
                                <div class="row g-2">
                                    <?php if (!empty($voucher['swift_document'])):
                                        $swiftRel = ltrim($voucher['swift_document'], '/');
                                        $swiftLink = app_url('/proxy_pdf.php') . '?file=' . urlencode($swiftRel);
                                        ?>
                                        <div class="col-6 col-md-3">
                                            <a href="<?= htmlspecialchars($swiftLink) ?>" target="_blank" rel="noopener" class="text-decoration-none bg-primary bg-opacity-10 border border-primary p-2 d-block text-center rounded shadow-sm hover-lift" style="position: relative;">
                                                <div class="badge bg-primary px-2 py-1" style="position: absolute; top: -10px; right: -5px; font-size: 0.55rem; z-index: 5;">SWIFT PROOF</div>
                                                <div class="bg-white border rounded mb-1 d-flex align-items-center justify-content-center mx-auto shadow-sm" style="width: 100%; height: 80px;">
                                                    <i class="bi bi-shield-check text-primary" style="font-size: 2.5rem;"></i>
                                                </div>
                                                <span class="d-block text-primary fw-bold text-truncate" style="font-size: 0.65rem;">Bank Swift Confirmation</span>
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($attachments as $att):
                                        $rel = ltrim($att['file_path'], '/');
                                        $proxyLink = app_url('/proxy_pdf.php') . '?file=' . urlencode($rel);
                                        $isImg = preg_match('/\.(jpg|jpeg|png|gif)$/i', $rel);
                                        ?>
                                        <div class="col-6 col-md-3">
                                            <a href="<?= htmlspecialchars($proxyLink) ?>" target="_blank" rel="noopener" class="text-decoration-none bg-light border p-2 d-block text-center rounded shadow-sm hover-lift">
                                                <?php if ($isImg): ?>
                                                    <div class="bg-white rounded mb-1 d-flex align-items-center justify-content-center mx-auto overflow-hidden border" style="width: 100%; height: 80px;">
                                                        <img src="<?= htmlspecialchars($proxyLink) ?>" alt="" style="max-width: 100%; max-height: 100%; object-fit: cover;">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="bg-white border rounded mb-1 d-flex align-items-center justify-content-center mx-auto" style="width: 100%; height: 80px;">
                                                        <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2rem;"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="d-block text-muted text-truncate" style="font-size: 0.65rem;"><?= htmlspecialchars($att['original_name']) ?></span>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="text-center mt-4 no-print border-top pt-3">
                            <a href="<?= htmlspecialchars($voucherViewUrl) ?>" class="btn btn-sm btn-outline-primary rounded-2">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open in voucher system
                            </a>
                        </div>
                    </div>
                </div>
            </div>
<?php else: ?>
            <div class="dash-card mb-4">
                <div class="px-4 py-3 border-bottom border-gray-100 fw-bold text-gray-800">
                    <i class="fas fa-link text-[#2563EB] me-2"></i>Internal reference
                </div>
                <div class="p-4 text-muted fst-italic mb-0">Manual entry / internal transfer</div>
            </div>
<?php endif; ?>

            <p class="text-center text-muted small opacity-75 mb-0">Liquidity management audit trail</p>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
