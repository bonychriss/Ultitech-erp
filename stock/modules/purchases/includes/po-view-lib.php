<?php

declare(strict_types=1);

function poViewDeskBootstrap(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    if (!isset($GLOBALS['stockBasePath'])) {
        require_once __DIR__ . '/../../../config/paths.php';
    }
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/functions.php';
    require_once __DIR__ . '/../purchase_workflow.php';
    $salesFunctions = dirname(__DIR__, 4) . '/modules/sales/functions.php';
    if (is_file($salesFunctions)) {
        require_once $salesFunctions;
    }
    $booted = true;
}

function poViewDeskRequireAccess(): void
{
    poViewDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function poViewParseId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

/**
 * @param mixed $value
 * @return mixed
 */
function poViewSanitizeUtf8($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = poViewSanitizeUtf8($item);
        }

        return $value;
    }

    if (!is_string($value) || $value === '') {
        return $value;
    }

    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
}

/**
 * @param array<string, mixed> $data
 */
function poViewJsonEncode(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($data, $flags);
    if ($json !== false) {
        return $json;
    }

    $json = json_encode(poViewSanitizeUtf8($data), $flags);
    if ($json !== false) {
        return $json;
    }

    return json_encode(['error' => 'Failed to encode purchase order response.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"Failed to encode purchase order response."}';
}

function poViewShouldUseReact(): bool
{
    if (function_exists('isUltimate') && isUltimate()) {
        return true;
    }
    if (function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices()) {
        return true;
    }

    return false;
}

function poViewModuleUrl(string $path, array $params = []): string
{
    global $stockBasePath;
    $base = !empty($stockBasePath)
        ? rtrim((string) $stockBasePath, '/') . '/modules/purchases/'
        : (function_exists('app_url') ? app_url('stock/modules/purchases/') : '/stock/modules/purchases/');
    $url = $base . ltrim($path, '/');
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function poViewLoadReactAssets(): ?array
{
    $ordersLib = dirname(__DIR__, 4) . '/modules/sales/orders/includes/orders-lib.php';
    if (!is_file($ordersLib)) {
        return null;
    }
    require_once $ordersLib;
    $assets = ordersDeskLoadReactAssets();
    if ($assets === null) {
        return null;
    }
    $assets['apiUrl'] = poViewModuleUrl('api');

    return $assets;
}

function poViewDeskShellHeadExtras(array $company = []): string
{
    $ordersLib = dirname(__DIR__, 4) . '/modules/sales/orders/includes/orders-view-lib.php';
    if (is_file($ordersLib)) {
        require_once $ordersLib;

        return ordersViewDeskShellHeadExtras($company);
    }

    return '';
}

/**
 * @return list<array{key:string,label:string,state:string}>
 */
function poViewBuildPipeline(string $currentStatus, ?string $workflow): array
{
    if (function_exists('isSupplierLinkWorkflow') && isSupplierLinkWorkflow((string) $workflow)) {
        $stages = [
            'draft' => ['label' => 'Draft', 'keys' => [PURCHASE_STATUS_DRAFT]],
            'supplier' => ['label' => 'Pending Supplier', 'keys' => [PURCHASE_STATUS_PENDING_SUPPLIER, PURCHASE_STATUS_NEGOTIATION]],
            'approval' => ['label' => 'Pending Approval', 'keys' => [PURCHASE_STATUS_SUPPLIER_RESPONDED, PURCHASE_STATUS_PENDING_APPROVAL]],
            'approved' => ['label' => 'Approved', 'keys' => [PURCHASE_STATUS_APPROVED]],
            'received' => ['label' => 'Received', 'keys' => [PURCHASE_STATUS_RECEIVED]],
        ];
    } else {
        $stages = [
            'pending' => ['label' => 'Pending', 'keys' => [PURCHASE_STATUS_PENDING]],
            'responded' => ['label' => 'Supplier Responded', 'keys' => [PURCHASE_STATUS_SUPPLIER_RESPONDED, PURCHASE_STATUS_NEGOTIATION]],
            'approved' => ['label' => 'Approved', 'keys' => [PURCHASE_STATUS_APPROVED]],
            'received' => ['label' => 'Received', 'keys' => [PURCHASE_STATUS_RECEIVED]],
        ];
    }

    $foundActive = false;
    $out = [];
    foreach ($stages as $key => $data) {
        $isActive = in_array($currentStatus, $data['keys'], true);
        $isDone = !$isActive && !$foundActive;
        if ($isActive) {
            $foundActive = true;
        }
        $out[] = [
            'key' => $key,
            'label' => $data['label'],
            'state' => $isActive ? 'active' : ($isDone ? 'done' : 'pending'),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function poViewLoadCompanyProfile(PDO $pdo, int $companyId): array
{
    return resolveStockPurchaseCompanyProfile($pdo, $companyId);
}

/**
 * @return list<array<string, mixed>>
 */
function poViewLoadLineItems(PDO $pdo, int $id, bool $isLegacyPurchase): array
{
    $items = [];
    $linesSubtotalUsd = 0.0;

    $legacyImageExpr = 'NULL';
    $stockImageSelect = 'NULL AS product_image, NULL AS image_product_id';
    $stockImageJoin = '';
    if (function_exists('stock_product_main_image_sql')) {
        try {
            $legacyImageExpr = stock_product_main_image_sql($pdo, 'pr');
            $stockImageSelect = stock_product_main_image_sql($pdo, 'pimg') . ' AS product_image, pimg.id AS image_product_id';
            $stockImageJoin = "LEFT JOIN products pimg
                   ON (LOWER(TRIM(pimg.name)) = LOWER(TRIM(si.name)))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(pimg.product_code)) = LOWER(TRIM(si.sku)))";
        } catch (Throwable $e) {
            $legacyImageExpr = 'NULL';
            $stockImageSelect = 'NULL AS product_image, NULL AS image_product_id';
            $stockImageJoin = '';
        }
    }

    try {
        if ($isLegacyPurchase) {
            $stmtItems = $pdo->prepare(
                'SELECT pi.id, pi.product_id AS product_id, pi.quantity AS quantity, pi.unit_price AS unit_price,
                    (pi.quantity * pi.unit_price) AS total_amount,
                    pr.name AS product_name, pr.product_code AS product_code, pr.description AS product_desc,
                    ' . $legacyImageExpr . ' AS product_image,
                    NULL AS last_price, pi.product_id AS image_product_id
                FROM purchase_items pi
                LEFT JOIN products pr ON pr.id = pi.product_id
                WHERE pi.purchase_id = ?
                ORDER BY pi.id ASC'
            );
            $stmtItems->execute([$id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $stmtItems = $pdo->prepare(
                'SELECT pi.id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                    (pi.qty_ordered * pi.unit_cost) AS total_amount,
                    si.name AS product_name, si.sku AS product_code, si.description AS product_desc,
                    ' . $stockImageSelect . ',
                    (SELECT pi2.unit_cost FROM stocks_po_items pi2
                        INNER JOIN stocks_purchase_orders p2 ON pi2.po_id = p2.id
                        WHERE pi2.item_id = pi.item_id AND p2.status = \'Approved\' AND p2.id < ?
                        ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1) AS last_price
                 FROM stocks_po_items pi
                 INNER JOIN stocks_items si ON si.id = pi.item_id
                 ' . $stockImageJoin . '
                 WHERE pi.po_id = ?
                 ORDER BY pi.id ASC'
            );
            $stmtItems->execute([$id, $id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        foreach ($items as $row) {
            $linesSubtotalUsd += (float) ($row['total_amount'] ?? 0);
        }
    } catch (Throwable $e) {
        $items = [];
    }

    return [$items, $linesSubtotalUsd];
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function poViewApplyTotals(array $po, array $items, float $linesSubtotalUsd): array
{
    $po['subtotal'] = $linesSubtotalUsd;
    $po['discount_percentage'] = isset($po['discount_percentage']) ? (float) $po['discount_percentage'] : 0.0;
    $po['discount_amount'] = isset($po['discount_amount']) ? (float) $po['discount_amount'] : 0.0;
    if ($po['discount_amount'] <= 0 && $po['discount_percentage'] > 0 && $linesSubtotalUsd > 0) {
        $po['discount_amount'] = $linesSubtotalUsd * ($po['discount_percentage'] / 100.0);
    }
    $po['net_subtotal'] = max(0, $linesSubtotalUsd - $po['discount_amount']);
    $po['tax_amount'] = isset($po['tax_amount']) ? (float) $po['tax_amount'] : 0.0;
    $po['tax_percentage'] = isset($po['tax_percentage']) ? (float) $po['tax_percentage'] : 0.0;
    if ($po['tax_amount'] <= 0 && $po['tax_percentage'] > 0) {
        $po['tax_amount'] = $po['net_subtotal'] * ($po['tax_percentage'] / 100.0);
    }
    if ($po['tax_percentage'] <= 0 && $po['tax_amount'] > 0 && $po['net_subtotal'] > 0) {
        $po['tax_percentage'] = round(($po['tax_amount'] / $po['net_subtotal']) * 100, 4);
    }
    $storedTotalUsd = isset($po['total_amount']) ? (float) $po['total_amount'] : 0.0;
    $po['total_amount'] = $storedTotalUsd > 0 ? $storedTotalUsd : ($po['net_subtotal'] + $po['tax_amount']);

    return $po;
}

function poViewPortalUrl(array $po): string
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    global $stockBasePath;
    $portalPath = (!empty($stockBasePath) ? rtrim((string) $stockBasePath, '/') : '/stock')
        . '/modules/purchases/supplier_response.php?token='
        . rawurlencode((string) ($po['public_token'] ?? ''));

    return $protocol . '://' . $host . $portalPath;
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function poViewBuildShareData(array $po, array $items, string $companyName): array
{
    $portalUrl = poViewPortalUrl($po);
    $contact = trim((string) (($po['contact_person'] ?? '') ?: 'Supplier'));
    $waBody = "Hi {$contact},\n\nPurchase Order: " . ($po['purchase_no'] ?? '') . "\n\nPlease see items required in the attached/link.\n\nRegards,\n" . $companyName;
    $waPhone = preg_replace('/[^0-9]/', '', (string) ($po['supplier_phone'] ?? ''));
    if (substr($waPhone, 0, 1) === '0') {
        $waPhone = '255' . substr($waPhone, 1);
    } elseif (strlen($waPhone) === 9) {
        $waPhone = '255' . $waPhone;
    }

    return [
        'portal_url' => $portalUrl,
        'whatsapp_body' => $waBody,
        'whatsapp_phone' => $waPhone,
        'whatsapp_url' => $waPhone !== ''
            ? 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($waBody)
            : '',
    ];
}

/**
 * @param list<array<string, mixed>> $items
 */
function poViewRenderDocumentHtml(
    array $po,
    array $items,
    array $company,
    string $companyLogoUrl,
    string $userSignatureUrl,
    string $userFullName,
    callable $formatPoMoney,
    callable $formatPoLineMoney,
    string $noImageUrl
): string {
    $displayPoNumber = (string) ($po['purchase_no'] ?? '');
    $poCurrencyCode = strtoupper(trim((string) ($po['currency'] ?? 'USD')));
    $invoice = $po;

    ob_start();
    echo '<div id="order-content">';
    include __DIR__ . '/po_view_sheet_inner.php';
    echo '</div>';

    return (string) ob_get_clean();
}

/**
 * @param list<array<string, mixed>> $items
 */
function poViewRenderAlertsHtml(
    PDO $pdo,
    int $id,
    array $po,
    array $items,
    int $companyId,
    ?array $supplierVoucherMismatch,
    bool $canSyncSupplierFromVoucher,
    callable $formatPoMoney,
    string $invoiceViewUrl,
    string $invoiceDownloadUrl
): string {
    ob_start();

    if ($supplierVoucherMismatch && $canSyncSupplierFromVoucher) {
        ?>
        <div class="ov-flash ov-flash-warning ov-no-print ov-alert-banner">
            <strong><i class="fas fa-exclamation-triangle me-2"></i>Supplier mismatch</strong>
            <p class="mb-2 mt-1 small">
                This PO shows <strong><?= htmlspecialchars((string) $supplierVoucherMismatch['supplier_name']) ?></strong>,
                but the linked payment voucher payee is <strong><?= htmlspecialchars((string) $supplierVoucherMismatch['payee_name']) ?></strong>.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a href="view_po.php?id=<?= (int) $id ?>&sync_supplier=1" class="ov-btn ov-btn-secondary"
                   onclick="return confirm('Update this PO supplier to match the linked voucher payee?');">
                    Fix supplier from voucher
                </a>
                <a href="edit.php?id=<?= (int) $id ?>" class="ov-btn ov-btn-secondary">Edit PO</a>
            </div>
        </div>
        <?php
    }

    if (function_exists('isSupplierLinkWorkflow')
        && isSupplierLinkWorkflow($po['procurement_workflow'] ?? '')
        && ($po['status'] ?? '') === PURCHASE_STATUS_DRAFT) {
        ?>
        <div class="ov-flash ov-no-print ov-alert-banner">
            <strong><i class="fas fa-file-alt me-2"></i>Draft (supplier link workflow)</strong>
            <p class="small text-muted mb-2">Review lines and supplier, then send the secure portal link.</p>
            <form method="post" class="m-0 d-inline" onsubmit="return confirm('Email the supplier the quote request with portal link?');">
                <input type="hidden" name="action" value="send_to_supplier">
                <button type="submit" class="ov-btn ov-btn-primary">Send to supplier</button>
            </form>
        </div>
        <?php
    }

    $linkedShipment = false;
    try {
        $stmtLink = $pdo->prepare(
            'SELECT id, shipment_number, status, tracking_number, eta FROM shipments
             WHERE stocks_po_id = ? OR purchase_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmtLink->execute([$id, $id]);
        $linkedShipment = $stmtLink->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $linkedShipment = false;
    }

    if (($po['status'] ?? '') === PURCHASE_STATUS_APPROVED && !$linkedShipment) {
        ?>
        <div class="ov-flash ov-no-print ov-alert-banner">
            <strong><i class="fas fa-boxes me-2"></i>Arrange Shipment</strong>
            <p class="mb-2 small">This order is Approved. Create a shipment record to track delivery.</p>
            <a href="../shipments/create.php?purchase_id=<?= (int) $id ?>" class="ov-btn ov-btn-primary">Create Shipment</a>
        </div>
        <?php
    } elseif ($linkedShipment) {
        ?>
        <div class="ov-flash ov-flash-success ov-no-print ov-alert-banner">
            <strong><i class="fas fa-truck me-2"></i>Shipment: <?= htmlspecialchars((string) $linkedShipment['shipment_number']) ?></strong>
            <p class="mb-2 small">
                Status: <?= htmlspecialchars(strtoupper((string) $linkedShipment['status'])) ?>
                | Tracking: <?= htmlspecialchars((string) ($linkedShipment['tracking_number'] ?? 'NA')) ?>
            </p>
            <a href="../shipments/view.php?id=<?= (int) $linkedShipment['id'] ?>" class="ov-btn ov-btn-secondary">View Shipment</a>
        </div>
        <?php
    }

    if (function_exists('purchaseAwaitingApprovalStatuses')
        && in_array($po['status'] ?? '', purchaseAwaitingApprovalStatuses(), true)) {
        ?>
        <div class="ov-supplier-review ov-no-print">
            <div class="ov-supplier-review-header">
                <h5><i class="fas fa-exclamation-circle me-2"></i>Supplier Has Responded</h5>
                <span class="ov-badge">Action Required</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Quoted Price</th>
                            <th class="text-end">Last Price</th>
                            <th class="text-center">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $quoted = (float) ($item['unit_price'] ?? 0);
                        $last = (float) ($item['last_price'] ?? 0);
                        $variance = 0;
                        $varianceClass = 'text-muted';
                        if ($last > 0) {
                            $variance = (($quoted - $last) / $last) * 100;
                            if ($variance > 10) {
                                $varianceClass = 'text-danger fw-bold';
                            } elseif ($variance < -10) {
                                $varianceClass = 'text-success fw-bold';
                            }
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($item['product_name'] ?? '')) ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) ($item['quantity'] ?? '')) ?></td>
                            <td class="text-end fw-bold"><?= $formatPoMoney($quoted) ?></td>
                            <td class="text-end text-muted"><?= $last > 0 ? $formatPoMoney($last) : 'N/A' ?></td>
                            <td class="text-center <?= $varianceClass ?>">
                                <?= $last > 0 ? number_format(abs($variance), 1) . '%' : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <form method="POST" class="m-0" onsubmit="return confirm('Accept this quote?');">
                    <input type="hidden" name="action" value="accept_quote">
                    <button type="submit" class="ov-btn ov-btn-primary">Accept Quote</button>
                </form>
                <button type="button" class="ov-btn ov-btn-secondary" data-bs-toggle="modal" data-bs-target="#negotiationModal">
                    Negotiate
                </button>
                <a href="edit.php?id=<?= (int) $id ?>" class="ov-btn ov-btn-secondary">Adjust / Edit</a>
            </div>
            <?php if ($invoiceViewUrl !== ''): ?>
                <p class="small mt-2 mb-0">
                    <a href="<?= htmlspecialchars($invoiceViewUrl) ?>" target="_blank" rel="noopener">View Attached Invoice</a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    return (string) ob_get_clean();
}

/**
 * @return array<string, mixed>
 */
function poViewLoadContext(int $id): array
{
    global $pdo;

    poViewDeskBootstrap();
    ensureStocksPurchaseOrdersWorkflowColumns($pdo);
    ensurePurchaseWorkflowSchema($pdo);

    $company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);
    $po = stockPurchaseLoadPoForView($pdo, $id, $company_id);
    if (!$po) {
        throw new RuntimeException('Purchase Order not found.');
    }

    $isLegacyPurchase = ($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases';

    if (empty($po['public_token'])) {
        $token = bin2hex(random_bytes(16));
        try {
            $pdo->prepare('UPDATE stocks_purchase_orders SET public_token = ? WHERE id = ?')->execute([$token, $id]);
            $po['public_token'] = $token;
        } catch (Throwable $e) {
            $po['public_token'] = $token;
        }
    }

    $company = poViewLoadCompanyProfile($pdo, $company_id);
    $poCompanyId = resolveStockPurchaseCompanyIdForProfile($company_id);
    $companyName = trim((string) ($company['company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = 'Company';
    }

    $userSignature = '';
    $userFullName = '';
    try {
        $stmtUser = $pdo->prepare('SELECT full_name, signature_path FROM users WHERE id = ?');
        $stmtUser->execute([$_SESSION['user_id'] ?? 0]);
        $currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($currentUser) {
            $userSignature = (string) ($currentUser['signature_path'] ?? '');
            $userFullName = (string) ($currentUser['full_name'] ?? '');
        }
    } catch (Throwable $e) {
    }

    $companyLogoUrl = function_exists('getCompanyLogoUrl')
        ? (string) getCompanyLogoUrl($poCompanyId > 0 ? $poCompanyId : null)
        : '';
    if ($companyLogoUrl === '' && function_exists('getCompanySetting')) {
        $companyLogoUrl = mediaUrlFromPath(getCompanySetting('company_logo', ''));
    }
    if ($companyLogoUrl === '' && !empty($company['logo'])) {
        $companyLogoUrl = mediaUrlFromPath((string) $company['logo']);
    }
    if ($companyLogoUrl === '') {
        $companyLogoUrl = function_exists('app_url') ? app_url('/assets/images/Untitled.jpg') : '/assets/images/Untitled.jpg';
    }

    $userSignatureUrl = !empty($userSignature) ? mediaUrlFromPath($userSignature) : '';
    global $stockBasePath;
    $noImageUrl = !empty($stockBasePath)
        ? rtrim((string) $stockBasePath, '/') . '/assets/images/no-image.png'
        : (function_exists('app_url') ? app_url('/stock/assets/images/no-image.png') : '/stock/assets/images/no-image.png');

    [$items, $linesSubtotalUsd] = poViewLoadLineItems($pdo, $id, $isLegacyPurchase);
    $po = poViewApplyTotals($po, $items, $linesSubtotalUsd);

    $defaultCurrencyCode = strtoupper(trim((string) ($company['currency'] ?? 'USD')));
    if ($defaultCurrencyCode === '') {
        $defaultCurrencyCode = 'USD';
    }
    $poCurrencyCode = strtoupper(trim((string) ($po['currency'] ?? '')));
    if ($poCurrencyCode === '') {
        $poCurrencyCode = $defaultCurrencyCode;
    }
    $rate = (float) ($po['exchange_rate'] ?? 0);
    if ($rate <= 0) {
        $rate = (float) ($company['exchange_rate'] ?? 1);
    }
    if ($rate <= 0) {
        $rate = 1.0;
    }
    $currSymbol = getCurrencySymbol($poCurrencyCode);
    $poUsesNativeCurrencyStorage = function_exists('stock_po_uses_native_currency_storage')
        ? stock_po_uses_native_currency_storage($poCurrencyCode, $rate)
        : ($poCurrencyCode === 'TZS' && $rate <= 1.01);

    $formatPoMoney = static function (float $storedAmount) use ($rate, $currSymbol, $poCurrencyCode, $poUsesNativeCurrencyStorage): string {
        $display = function_exists('stock_po_amount_to_display')
            ? stock_po_amount_to_display($storedAmount, $poCurrencyCode, $rate)
            : ($poUsesNativeCurrencyStorage ? $storedAmount : convertCurrency($storedAmount, $rate));

        return $currSymbol . number_format($display, 2);
    };
    $formatPoLineMoney = $formatPoMoney;

    $invoiceViewUrl = '';
    $invoiceDownloadUrl = '';
    if (!empty($po['invoice_attachment'])) {
        $invoiceViewUrl = 'download_invoice.php?id=' . $id;
        $invoiceDownloadUrl = 'download_invoice.php?id=' . $id . '&download=1';
    }

    $supplierVoucherMismatch = null;
    $canSyncSupplierFromVoucher = function_exists('hasRole') ? (hasRole('admin') || hasRole('procurement')) : true;
    if (function_exists('stockPurchaseDetectSupplierVoucherMismatch')) {
        $supplierVoucherMismatch = stockPurchaseDetectSupplierVoucherMismatch($po, $company_id, $pdo);
    }

    $workflow = (string) ($po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD);
    $status = (string) ($po['status'] ?? '');
    $share = poViewBuildShareData($po, $items, $companyName);

    $hideApprove = ($status === PURCHASE_STATUS_APPROVED)
        || in_array($status, [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER], true);
    $canEditPoView = function_exists('purchaseOrderAllEditAccessStatuses')
        && in_array($status, purchaseOrderAllEditAccessStatuses($workflow), true);

    $documentHtml = poViewRenderDocumentHtml(
        $po,
        $items,
        $company,
        $companyLogoUrl,
        $userSignatureUrl,
        $userFullName,
        $formatPoMoney,
        $formatPoLineMoney,
        $noImageUrl
    );

    $alertsHtml = poViewRenderAlertsHtml(
        $pdo,
        $id,
        $po,
        $items,
        $company_id,
        $supplierVoucherMismatch,
        $canSyncSupplierFromVoucher,
        $formatPoMoney,
        $invoiceViewUrl,
        $invoiceDownloadUrl
    );

    $docFontFamily = function_exists('sales_document_font_family_css')
        ? sales_document_font_family_css($company)
        : "'Arima', Arial, sans-serif";

    return [
        'po_id' => $id,
        'display_po_number' => (string) ($po['purchase_no'] ?? ('PO-' . $id)),
        'po' => [
            'id' => $id,
            'purchase_no' => (string) ($po['purchase_no'] ?? ''),
            'status' => $status,
            'supplier_name' => (string) ($po['supplier_name'] ?? ''),
            'supplier_email' => (string) ($po['supplier_email'] ?? ''),
            'supplier_phone' => (string) ($po['supplier_phone'] ?? ''),
            'contact_person' => (string) ($po['contact_person'] ?? ''),
            'currency' => $poCurrencyCode,
            'total_amount' => (float) ($po['total_amount'] ?? 0),
        ],
        'pipeline' => poViewBuildPipeline($status, $workflow),
        'share' => $share,
        'flags' => [
            'can_approve' => !$hideApprove,
            'can_edit' => $canEditPoView,
            'can_send_to_supplier' => function_exists('isSupplierLinkWorkflow')
                && isSupplierLinkWorkflow($workflow)
                && $status === PURCHASE_STATUS_DRAFT,
            'can_copy_portal_link' => true,
            'can_upload_invoice' => true,
            'has_invoice_attachment' => $invoiceViewUrl !== '',
        ],
        'alerts_html' => $alertsHtml,
        'document_html' => $documentHtml,
        'document_font_family' => $docFontFamily,
        'font_stylesheets' => function_exists('sales_document_font_stylesheet_links')
            ? sales_document_font_stylesheet_links($company)
            : '',
        'email' => [
            'subject' => 'Purchase Order ' . ($po['purchase_no'] ?? '') . ' - ' . $companyName . ' - Action Required',
            'default_recipient' => (string) ($po['supplier_email'] ?? ''),
        ],
        'urls' => [
            'view' => poViewModuleUrl('view_po.php', ['id' => $id]),
            'index' => poViewModuleUrl('index.php'),
            'edit' => poViewModuleUrl('edit.php', ['id' => $id]),
            'approve' => poViewModuleUrl('approve.php', ['id' => $id]),
            'receipt_audit' => poViewModuleUrl('receipt_audit.php', ['po_id' => $id]),
            'domestic_receive' => poViewModuleUrl('domestic_receive.php', ['id' => $id]),
            'invoice_view' => $invoiceViewUrl !== '' ? poViewModuleUrl($invoiceViewUrl) : '',
            'invoice_download' => $invoiceDownloadUrl !== '' ? poViewModuleUrl($invoiceDownloadUrl) : '',
        ],
    ];
}

function poViewInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $id = poViewParseId($_GET);
    if ($id <= 0) {
        throw new RuntimeException('Purchase order id is required.');
    }

    return poViewLoadContext($id);
}

function poViewRenderReactShell(int $poId): void
{
    $assets = poViewLoadReactAssets();
    if ($assets === null) {
        return;
    }

    try {
        $preview = poViewLoadContext($poId);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Purchase Order not found</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
        exit;
    }

    $displayNumber = (string) ($preview['display_po_number'] ?? 'PO');
    $page_title = 'Purchase Order ' . $displayNumber;
    $employeeHeaderTitle = '';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--order-view';

    $cfg = [
        'po_id' => $poId,
    ];

    $ordersHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__SALES_ORDERS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_ORDERS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('po_view', JSON_UNESCAPED_SLASHES) . ';</script>';

    $ordersViewHeadExtras = poViewDeskShellHeadExtras([]);

    require __DIR__ . '/po-view-react-shell.php';
    exit;
}

/**
 * Resolve receipt status label for a PO line.
 */
function poViewReceiptLineStatus(float $ordered, float $received): string
{
    if ($received <= 0) {
        return 'pending';
    }
    if ($received + 0.0001 >= $ordered) {
        return $received > $ordered + 0.0001 ? 'over' : 'complete';
    }

    return 'partial';
}

/**
 * @return array{ok:bool,label:string,detail:string}
 */
function poViewReceiptCheck(string $key, bool $ok, string $label, string $detail = ''): array
{
    return [
        'key' => $key,
        'ok' => $ok,
        'label' => $label,
        'detail' => $detail,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function poViewLoadReceiptTransactions(PDO $pdo, int $poId): array
{
    try {
        $hasTxn = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_transactions'")->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    if (!$hasTxn) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.item_id, t.type, t.quantity, t.unit_cost, t.transaction_date,
                    t.external_reference, t.notes, si.name AS item_name, si.sku
             FROM stocks_transactions t
             LEFT JOIN stocks_items si ON si.id = t.item_id
             WHERE t.reference_type = 'purchase_order' AND t.reference_id = ?
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT 200"
        );
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'item_id' => (int) ($row['item_id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'type' => (string) ($row['type'] ?? 'in'),
                'quantity' => (float) ($row['quantity'] ?? 0),
                'transaction_date' => (string) ($row['transaction_date'] ?? ''),
                'external_reference' => (string) ($row['external_reference'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<array<string, mixed>> $transactions
 */
function poViewSumTxnQtyByItem(array $transactions): array
{
    $out = [];
    foreach ($transactions as $txn) {
        $itemId = (int) ($txn['item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $qty = (float) ($txn['quantity'] ?? 0);
        $type = strtolower((string) ($txn['type'] ?? 'in'));
        $signed = $type === 'out' ? -$qty : $qty;
        $out[$itemId] = ($out[$itemId] ?? 0.0) + $signed;
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function poViewBuildReceiptVerification(array $lines, array $po, string $poStatus): array
{
    $checks = [];
    $issues = 0;
    $warnings = 0;

    $complete = 0;
    $partial = 0;
    $pending = 0;
    foreach ($lines as $line) {
        $status = (string) ($line['receipt_status'] ?? 'pending');
        if ($status === 'complete' || $status === 'over') {
            $complete++;
        } elseif ($status === 'partial') {
            $partial++;
        } else {
            $pending++;
        }

        foreach ($line['checks'] ?? [] as $check) {
            if ($check['ok'] ?? false) {
                continue;
            }
            $checkKey = (string) ($check['key'] ?? '');
            if ($checkKey === 'txn_match') {
                $stockDeltaOk = false;
                foreach ($line['checks'] ?? [] as $innerCheck) {
                    if (($innerCheck['key'] ?? '') === 'stock_delta' && ($innerCheck['ok'] ?? false)) {
                        $stockDeltaOk = true;
                        break;
                    }
                }
                if ($stockDeltaOk && in_array((string) ($line['receipt_status'] ?? ''), ['complete', 'over'], true)) {
                    $warnings++;
                    continue;
                }
            }
            if ($checkKey === 'stock_delta' && (float) ($line['qty_received'] ?? 0) > 0) {
                $warnings++;
                continue;
            }
            $issues++;
        }
    }

    $totalLines = count($lines);
    $checks[] = poViewReceiptCheck(
        'all_received',
        $totalLines > 0 && $pending === 0 && $partial === 0,
        'All line items fully received',
        $totalLines > 0
            ? sprintf('%d of %d lines complete', $complete, $totalLines)
            : 'No line items on this PO'
    );

    $checks[] = poViewReceiptCheck(
        'po_status',
        !in_array($poStatus, [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER, PURCHASE_STATUS_PENDING], true)
            || ($pending === $totalLines),
        'PO status aligns with receipt progress',
        'Status: ' . $poStatus
    );

    if ($poStatus === PURCHASE_STATUS_RECEIVED) {
        $checks[] = poViewReceiptCheck(
            'status_received',
            $pending === 0 && $partial === 0,
            'PO marked Received and quantities match',
            $pending + $partial > 0 ? 'Some lines are still open' : 'All lines closed'
        );
    }

    $overall = 'pass';
    if ($issues > 0) {
        $overall = 'fail';
    } elseif ($warnings > 0 || $partial > 0) {
        $overall = 'warn';
    } elseif ($pending === $totalLines && $totalLines > 0) {
        $overall = 'pending';
    }

    $score = 100;
    if ($totalLines > 0) {
        $score = (int) round(($complete / $totalLines) * 100);
        if ($issues > 0) {
            $score = max(0, $score - (15 * $issues));
        }
        if ($warnings > 0) {
            $score = max(0, $score - (8 * $warnings));
        }
    }

    return [
        'status' => $overall,
        'score' => $score,
        'checks' => $checks,
        'counts' => [
            'total_lines' => $totalLines,
            'complete_lines' => $complete,
            'partial_lines' => $partial,
            'pending_lines' => $pending,
        ],
    ];
}

/**
 * Optional AI narrative for receipt verification.
 *
 * @param array<string, mixed> $payload
 * @return array{available:bool,via_ai:bool,summary:string}
 */
function poViewReceiptAiVerification(array $payload): array
{
    $ruleSummary = (string) ($payload['rule_summary'] ?? '');
    $aiPath = dirname(__DIR__, 4) . '/includes/ai_helpers.php';
    if (!is_file($aiPath)) {
        return [
            'available' => false,
            'via_ai' => false,
            'summary' => $ruleSummary,
        ];
    }
    require_once $aiPath;

    if (!function_exists('ai_openai_request') || !function_exists('ai_fetch_settings_row')) {
        return [
            'available' => false,
            'via_ai' => false,
            'summary' => $ruleSummary,
        ];
    }

    try {
        $settings = ai_fetch_settings_row();
        if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
            return [
                'available' => true,
                'via_ai' => false,
                'summary' => $ruleSummary,
            ];
        }
    } catch (Throwable $e) {
        return [
            'available' => false,
            'via_ai' => false,
            'summary' => $ruleSummary,
        ];
    }

    $linesText = [];
    foreach ($payload['lines'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $linesText[] = sprintf(
            '- %s: ordered %s, received %s, stock before %s, stock after %s, status %s',
            (string) ($line['product_name'] ?? 'Item'),
            (string) ($line['qty_ordered'] ?? 0),
            (string) ($line['qty_received'] ?? 0),
            (string) ($line['stock_before'] ?? 0),
            (string) ($line['stock_after'] ?? 0),
            (string) ($line['receipt_status'] ?? '')
        );
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You verify purchase order stock receipts for a business user. Reply in 2-4 short plain sentences using plain ASCII punctuation only. Say whether stock was received correctly. If quantities and stock before/after match, say receipt is OK even when transaction logs are missing. No markdown.',
        ],
        [
            'role' => 'user',
            'content' => 'PO ' . ($payload['po_number'] ?? '') . ' status ' . ($payload['po_status'] ?? '')
                . '. Verification: ' . ($payload['verification_status'] ?? '')
                . '. ' . $ruleSummary . "\nLines:\n" . implode("\n", $linesText),
        ],
    ];

    try {
        $result = ai_openai_request($messages);
        $content = trim((string) ($result['content'] ?? ''));
        if ($content !== '') {
            return [
                'available' => true,
                'via_ai' => true,
                'summary' => $content,
            ];
        }
    } catch (Throwable $e) {
        // fall through
    }

    return [
        'available' => true,
        'via_ai' => false,
        'summary' => $ruleSummary,
    ];
}

/**
 * @return array<string, mixed>
 */
function poViewLoadReceiptInfo(int $poId, bool $withAi = true): array
{
    global $pdo;

    poViewDeskBootstrap();
    ensureStocksPurchaseOrdersWorkflowColumns($pdo);
    ensurePurchaseWorkflowSchema($pdo);

    $company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);
    $po = stockPurchaseLoadPoForView($pdo, $poId, $company_id);
    if (!$po) {
        throw new RuntimeException('Purchase Order not found.');
    }

    $isLegacy = ($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases';
    $poNumber = (string) ($po['purchase_no'] ?? ('PO-' . $poId));
    $poStatus = (string) ($po['status'] ?? '');
    $transactions = poViewLoadReceiptTransactions($pdo, $poId);
    $txnByItem = poViewSumTxnQtyByItem($transactions);

    $rawLines = [];
    if ($isLegacy) {
        ensureLegacyPurchaseItemsReceivedColumn($pdo);
        try {
            $stmt = $pdo->prepare(
                'SELECT pi.id AS po_item_id, pi.product_id AS item_id, pi.quantity AS qty_ordered,
                        COALESCE(pi.qty_received, 0) AS qty_received,
                        COALESCE(p.name, CONCAT(\'Product #\', pi.product_id)) AS product_name,
                        COALESCE(p.product_code, \'\') AS sku,
                        COALESCE(s.quantity, 0) AS inventory_qty
                 FROM purchase_items pi
                 LEFT JOIN products p ON p.id = pi.product_id
                 LEFT JOIN stock s ON s.product_id = pi.product_id
                 WHERE pi.purchase_id = ?
                 ORDER BY pi.id ASC'
            );
            $stmt->execute([$poId]);
            $rawLines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rawLines = [];
        }
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT pi.id AS po_item_id, pi.item_id, pi.qty_ordered AS qty_ordered,
                        COALESCE(pi.qty_received, 0) AS qty_received,
                        si.name AS product_name, COALESCE(si.sku, \'\') AS sku,
                        COALESCE(si.stock_quantity, 0) AS stock_after
                 FROM stocks_po_items pi
                 JOIN stocks_items si ON si.id = pi.item_id
                 WHERE pi.po_id = ?
                 ORDER BY pi.id ASC'
            );
            $stmt->execute([$poId]);
            $rawLines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rawLines = [];
        }
    }

    $lines = [];
    foreach ($rawLines as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $ordered = (float) ($row['qty_ordered'] ?? 0);
        $received = (float) ($row['qty_received'] ?? 0);
        $txnQty = (float) ($txnByItem[$itemId] ?? 0);

        if ($isLegacy) {
            $stockAfter = (float) ($row['inventory_qty'] ?? 0);
        } else {
            $stockAfter = (float) ($row['stock_after'] ?? 0);
        }

        $stockBefore = max(0, $stockAfter - $received);
        if ($txnQty > 0) {
            $stockBefore = max(0, $stockAfter - $txnQty);
        }
        $stockDelta = $stockAfter - $stockBefore;
        $receiptStatus = poViewReceiptLineStatus($ordered, $received);

        $lineChecks = [];
        $lineChecks[] = poViewReceiptCheck(
            'received_qty',
            $received > 0,
            $received > 0 ? 'Stock receipt recorded' : 'No quantity received yet',
            sprintf('Received %s of %s ordered', rtrim(rtrim(number_format($received, 2), '0'), '.'), rtrim(rtrim(number_format($ordered, 2), '0'), '.'))
        );
        $lineChecks[] = poViewReceiptCheck(
            'txn_match',
            $received <= 0 || abs($txnQty - $received) < 0.01,
            'Receipt transactions match PO received qty',
            $txnQty > 0
                ? sprintf('Transactions total %s', rtrim(rtrim(number_format($txnQty, 2), '0'), '.'))
                : 'No receipt transactions logged'
        );
        $lineChecks[] = poViewReceiptCheck(
            'stock_delta',
            $received <= 0 || abs($stockDelta - $received) < 0.01 || abs($stockDelta - $txnQty) < 0.01,
            'Inventory increased by received amount',
            sprintf('Before %s, after %s (+%s)', rtrim(rtrim(number_format($stockBefore, 2), '0'), '.'), rtrim(rtrim(number_format($stockAfter, 2), '0'), '.'), rtrim(rtrim(number_format($stockDelta, 2), '0'), '.'))
        );

        $lines[] = [
            'po_item_id' => (int) ($row['po_item_id'] ?? 0),
            'item_id' => $itemId,
            'product_name' => (string) ($row['product_name'] ?? 'Item'),
            'sku' => (string) ($row['sku'] ?? ''),
            'qty_ordered' => $ordered,
            'qty_received' => $received,
            'txn_qty' => $txnQty,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'stock_delta' => $stockDelta,
            'receipt_status' => $receiptStatus,
            'received_ok' => in_array($receiptStatus, ['complete', 'over'], true),
            'checks' => $lineChecks,
        ];
    }

    $verification = poViewBuildReceiptVerification($lines, $po, $poStatus);
    $ruleSummary = match ($verification['status'] ?? 'pending') {
        'pass' => 'All items appear to have been received correctly and inventory movements look consistent.',
        'warn' => 'Receipt looks complete, but review the notes below for minor audit items.',
        'fail' => 'One or more receipt checks failed. Review transactions and stock levels.',
        default => 'No stock has been received on this purchase order yet.',
    };

    $ai = ['available' => false, 'via_ai' => false, 'summary' => $ruleSummary];
    if ($withAi) {
        $ai = poViewReceiptAiVerification([
            'po_number' => $poNumber,
            'po_status' => $poStatus,
            'verification_status' => (string) ($verification['status'] ?? ''),
            'rule_summary' => $ruleSummary,
            'lines' => $lines,
        ]);
    }

    return [
        'po_id' => $poId,
        'po_number' => $poNumber,
        'po_status' => $poStatus,
        'supplier_name' => (string) ($po['supplier_name'] ?? ''),
        'summary' => [
            'overall_status' => (string) ($verification['status'] ?? 'pending'),
            'score' => (int) ($verification['score'] ?? 0),
            'total_lines' => (int) ($verification['counts']['total_lines'] ?? 0),
            'complete_lines' => (int) ($verification['counts']['complete_lines'] ?? 0),
            'partial_lines' => (int) ($verification['counts']['partial_lines'] ?? 0),
            'pending_lines' => (int) ($verification['counts']['pending_lines'] ?? 0),
        ],
        'lines' => $lines,
        'transactions' => $transactions,
        'verification' => array_merge($verification, ['ai' => $ai, 'rule_summary' => $ruleSummary]),
        'urls' => [
            'receipt_audit' => poViewModuleUrl('receipt_audit.php', ['po_id' => $poId]),
            'domestic_receive' => poViewModuleUrl('domestic_receive.php', ['id' => $poId]),
            'view' => poViewModuleUrl('view_po.php', ['id' => $poId]),
        ],
    ];
}

function poViewReceiptInfoInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $id = poViewParseId($_GET);
    if ($id <= 0) {
        throw new RuntimeException('Purchase order id is required.');
    }

    $withAi = !isset($_GET['ai']) || (string) $_GET['ai'] !== '0';

    return poViewLoadReceiptInfo($id, $withAi);
}
