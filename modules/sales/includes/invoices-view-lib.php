<?php

declare(strict_types=1);

require_once __DIR__ . '/invoices-lib.php';
require_once dirname(__DIR__, 2) . '/orders/includes/orders-view-lib.php';

function invoicesViewDeskBootstrap(): void
{
    invoicesDeskBootstrap();
}

function invoicesViewDeskRequireAccess(): void
{
    invoicesViewDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function invoicesViewParseId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

function invoicesViewModuleQuery(): string
{
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';

    return $module !== '' ? $module : 'sales';
}

/**
 * @return array{invoice:array<string,mixed>,display_invoice_number:string,signature_url:string}|null
 */
function salesInvoiceViewLoadInvoice(PDO $salesDb, int $id): ?array
{
    ensureCustomerColumnsExist();

    $invCols = [];
    $soCols = [];
    try {
        $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }
    try {
        $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }

    $cTinExpr = 'NULL AS tin';
    $cVrnExpr = 'NULL AS vrn';
    $cTaxExpr = 'NULL AS customer_tax_id';
    try {
        $custCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('tin', $custCols, true)) {
            $cTinExpr = 'c.tin';
        }
        if (in_array('vrn', $custCols, true)) {
            $cVrnExpr = 'c.vrn';
        }
        if (in_array('tax_number', $custCols, true)) {
            $cTaxExpr = 'c.tax_number AS customer_tax_id';
        }
    } catch (Throwable $e) {
    }

    $hasOrderJoin = in_array('order_id', $invCols, true);
    $soJoinSql = $hasOrderJoin ? ' LEFT JOIN sales_orders so ON i.order_id = so.id' : '';
    $shippedSelect = ($hasOrderJoin && in_array('shipped_at', $soCols, true))
        ? 'so.shipped_at'
        : 'NULL AS shipped_at';

    $soOrderSelects = [];
    if ($hasOrderJoin) {
        if (in_array('order_type', $soCols, true)) {
            $soOrderSelects[] = 'so.order_type AS sales_order_type';
        }
        if (in_array('currency', $soCols, true)) {
            $soOrderSelects[] = 'so.currency AS order_currency';
        }
        if (in_array('exchange_rate', $soCols, true)) {
            $soOrderSelects[] = 'so.exchange_rate AS order_exchange_rate';
        }
        if (in_array('display_currencies', $soCols, true)) {
            $soOrderSelects[] = 'so.display_currencies AS order_display_currencies';
        }
        if (in_array('currency_rates', $soCols, true)) {
            $soOrderSelects[] = 'so.currency_rates AS order_currency_rates';
        }
    }
    $soOrderSelectSql = $soOrderSelects !== [] ? (', ' . implode(', ', $soOrderSelects)) : '';

    $userJoinSql = '';
    $salespersonSelect = "'' AS salesperson";
    $hasUsers = function_exists('sales_connection_has_table')
        ? sales_connection_has_table($salesDb, 'users')
        : (function_exists('tableExists') && tableExists('users', $salesDb));
    if ($hasUsers) {
        $userRef = null;
        if (in_array('created_by', $invCols, true) && in_array('created_by', $soCols, true) && $hasOrderJoin) {
            $userRef = 'COALESCE(i.created_by, so.created_by)';
        } elseif (in_array('created_by', $invCols, true)) {
            $userRef = 'i.created_by';
        } elseif (in_array('created_by', $soCols, true) && $hasOrderJoin) {
            $userRef = 'so.created_by';
        }
        if ($userRef !== null) {
            $userJoinSql = " LEFT JOIN users u ON {$userRef} = u.id";
            $salespersonSelect = 'u.full_name AS salesperson';
        }
    }

    $sql = "SELECT i.*, c.company_name, c.contact_person, c.email, c.phone, c.address,
        {$cTinExpr}, {$cVrnExpr}, {$cTaxExpr}, {$salespersonSelect}, {$shippedSelect}{$soOrderSelectSql}
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id{$soJoinSql}{$userJoinSql}
        WHERE i.id = ?";
    $invoiceParams = [$id];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($sql, $invoiceParams, 'invoices', 'i');
    }

    $stmt = $salesDb->prepare($sql);
    $stmt->execute($invoiceParams);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return null;
    }

    if (!empty($invoice['order_currency'])) {
        $invoice['currency'] = $invoice['order_currency'];
    }
    if (isset($invoice['order_exchange_rate']) && $invoice['order_exchange_rate'] !== '' && $invoice['order_exchange_rate'] !== null) {
        $invoice['exchange_rate'] = $invoice['order_exchange_rate'];
    }
    if (!empty($invoice['order_display_currencies'])) {
        $invoice['display_currencies'] = $invoice['order_display_currencies'];
    }
    if (!empty($invoice['order_currency_rates'])) {
        $invoice['currency_rates'] = $invoice['order_currency_rates'];
    }
    if (!empty($invoice['sales_order_type'])) {
        $invoice['order_type'] = $invoice['sales_order_type'];
    }

    $displayInvoiceNumber = (string) ($invoice['invoice_number'] ?? '');

    $signatureUrl = function_exists('sales_resolve_document_signature_url')
        ? sales_resolve_document_signature_url($invoice, $salesDb)
        : '';

    return [
        'invoice' => $invoice,
        'display_invoice_number' => $displayInvoiceNumber,
        'signature_url' => $signatureUrl,
    ];
}

/**
 * @param list<array<string, mixed>> $items
 */
function salesInvoiceViewDetectTruckOrder(array $invoice, array $items): bool
{
    if (!function_exists('isRoadmaster') || !isRoadmaster()) {
        return false;
    }

    $storedTruck = isset($invoice['order_type']) && strtolower(trim((string) $invoice['order_type'])) === 'truck';
    $hasVehicleLine = false;
    foreach ($items as $it) {
        $ity = isset($it['item_type']) ? strtolower(trim((string) $it['item_type'])) : '';
        if ($ity === 'vehicle' || $ity === 'truck') {
            $hasVehicleLine = true;
            break;
        }
    }

    return $storedTruck || $hasVehicleLine;
}

/**
 * @return array{ok:bool,message?:string,error?:string}
 */
function salesInvoiceViewApplyShipAction(PDO $salesDb, array $invoice): array
{
    $orderId = (int) ($invoice['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'No linked order to ship.'];
    }

    $ordStatus = 'unknown';
    $ordSql = 'SELECT status FROM sales_orders WHERE id = ?';
    $ordParams = [$orderId];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($ordSql, $ordParams, 'sales_orders');
    }
    $stmtOrd = $salesDb->prepare($ordSql);
    $stmtOrd->execute($ordParams);
    $linkedOrder = $stmtOrd->fetch(PDO::FETCH_ASSOC);
    if ($linkedOrder) {
        $ordStatus = (string) ($linkedOrder['status'] ?? 'unknown');
    }

    if (!empty($invoice['shipped_at']) || in_array($ordStatus, ['shipped', 'delivered', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'Order is already shipped or cannot be shipped.'];
    }

    $stockCheck = checkStockAvailability($orderId);
    if (!$stockCheck['valid']) {
        return [
            'ok' => false,
            'error' => 'Cannot Ship: Insufficient Stock for ' . implode(', ', $stockCheck['errors']),
        ];
    }

    deductStockForOrder($orderId);

    $shipSql = "UPDATE sales_orders SET status = 'shipped', shipped_at = NOW() WHERE id = ?";
    $shipParams = [$orderId];
    $salesOrderCompanyScope = function_exists('salesScopedCompanyId') ? salesScopedCompanyId('sales_orders') : null;
    if ($salesOrderCompanyScope !== null) {
        $shipSql .= ' AND company_id = ?';
        $shipParams[] = $salesOrderCompanyScope;
    }
    $salesDb->prepare($shipSql)->execute($shipParams);

    return ['ok' => true, 'message' => 'Order marked as shipped and stock deducted.'];
}

/**
 * @return list<array{key:string,label:string,state:string}>
 */
function salesInvoiceViewBuildPipeline(string $currentStatus): array
{
    $stages = [
        'draft' => ['label' => 'Draft', 'keys' => ['draft']],
        'sent' => ['label' => 'Posted', 'keys' => ['sent', 'viewed', 'partial', 'overdue']],
        'paid' => ['label' => 'Paid', 'keys' => ['paid']],
    ];

    $foundActive = false;
    $out = [];
    foreach ($stages as $key => $data) {
        $isActive = in_array($currentStatus, $data['keys'], true);
        $isDone = false;
        if (!$isActive && !$foundActive) {
            $isDone = true;
        }
        if ($isActive) {
            $foundActive = true;
        }

        $state = $isActive ? 'active' : ($isDone ? 'done' : 'pending');
        $out[] = [
            'key' => $key,
            'label' => $data['label'],
            'state' => $state,
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function salesInvoiceViewBuildShareData(
    array $invoice,
    string $displayInvoiceNumber,
    array $items,
    array $company_settings
): array {
    $token = '';
    try {
        $token = generateShareToken('invoice', $invoice['id'], $_SESSION['user_id'] ?? null);
    } catch (Throwable $e) {
        $token = 'error';
    }

    $module = invoicesViewModuleQuery();
    $docLink = sales_module_url('secure_download.php', ['token' => $token, 'module' => $module]);

    $senderName = ($invoice['salesperson'] ?? '') ?: 'Sales Team';
    $waBody = 'Dear ' . ($invoice['contact_person'] ?: 'Customer') . ",\n\n";
    $waBody .= "It was a pleasure speaking with you.\n";
    $waBody .= "We have prepared a tailored Invoice for you based on our discussion. We are confident that this offer provides the best value and quality for your requirements.\n\n";
    $waBody .= 'Please find your Invoice #' . $displayInvoiceNumber . " here:\n" . $docLink . "\n\n";
    $waBody .= "If you have any questions or would like to adjust any details, please let me know. I am happy to help!\n\n";
    $waBody .= 'Best regards,' . "\n" . $senderName;

    $invoiceSharePhone = preg_replace('/[^0-9]/', '', (string) ($invoice['phone'] ?? ''));
    if (substr($invoiceSharePhone, 0, 1) === '0') {
        $invoiceSharePhone = '255' . substr($invoiceSharePhone, 1);
    } elseif (strlen($invoiceSharePhone) === 9) {
        $invoiceSharePhone = '255' . $invoiceSharePhone;
    }

    $showCatalogue = !empty($items) && !empty($company_settings['include_catalogue']);

    return [
        'doc_link' => $docLink,
        'whatsapp_body' => $waBody,
        'whatsapp_phone' => $invoiceSharePhone,
        'whatsapp_url' => $invoiceSharePhone !== ''
            ? 'https://wa.me/' . $invoiceSharePhone . '?text=' . rawurlencode($waBody)
            : '',
        'show_catalogue' => $showCatalogue,
    ];
}

function salesInvoiceViewResolveBrandingLogoUrl(array $company_settings): string
{
    $brandingLogoUrl = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '';
    if ($brandingLogoUrl === '' && !empty($company_settings['company_logo'])) {
        $logoRel = (string) $company_settings['company_logo'];
        $logoIsData = (function_exists('str_starts_with') && str_starts_with($logoRel, 'data:'))
            || (strncmp($logoRel, 'data:', 5) === 0);
        if (!preg_match('#^https?://#i', $logoRel) && !$logoIsData) {
            $logoStartsAssets = (function_exists('str_starts_with') && str_starts_with($logoRel, 'assets/'))
                || (strncmp($logoRel, 'assets/', 7) === 0);
            $logoRel = $logoStartsAssets ? $logoRel : 'assets/images/' . ltrim($logoRel, '/');
            $logoDisk = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($logoRel, '/'));
            if (is_file($logoDisk) && function_exists('app_url')) {
                $brandingLogoUrl = app_url('/' . ltrim($logoRel, '/'));
            }
        }
    }

    return $brandingLogoUrl;
}

/**
 * @param list<array<string, mixed>> $items
 */
function salesInvoiceViewRenderDocumentHtml(
    array $invoice,
    array $items,
    array $company_settings,
    bool $isTruckOrder,
    string $signatureUrl,
    ?array $invoiceDualCurrencyCtx,
    string $brandingLogoUrl
): string {
    $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');

    ob_start();
    echo '<div id="invoice-content">';
    $layoutPath = function_exists('sales_branded_document_layout_inner_path')
        ? sales_branded_document_layout_inner_path($isTruckOrder)
        : null;
    if ($layoutPath !== null && is_file($layoutPath)) {
        include $layoutPath;
    } else {
        include dirname(__DIR__) . '/view_sheet_inner.php';
    }
    echo '</div>';

    return (string) ob_get_clean();
}

/**
 * @param list<array<string, mixed>> $items
 */
function salesInvoiceViewRenderCatalogHtml(
    array $invoice,
    array $items,
    array $company_settings,
    string $displayInvoiceNumber,
    string $brandingLogoUrl
): string {
    if (empty($items) || empty($company_settings['include_catalogue'])) {
        return '';
    }

    $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');

    ob_start();
    ?>
    <div id="catalog-content" class="sheet-container ov-catalog-sheet">
        <div class="ov-catalog-inner">
            <div class="ov-catalog-header">
                <h1 class="sheet-title ov-catalog-title">
                    Product Catalog
                    <small class="ov-catalog-subtitle">- Invoice #<?= htmlspecialchars($displayInvoiceNumber) ?></small>
                </h1>
                <div class="ov-catalog-company">
                    <?php if ($brandingLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($brandingLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Company Logo" class="ov-catalog-logo" crossorigin="anonymous" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div class="ov-catalog-company-name"><?= htmlspecialchars((string) $company_settings['company_name']) ?></div>
                </div>
            </div>
            <table class="ov-catalog-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product Details</th>
                        <th class="num">Pricing</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="ov-catalog-image-cell">
                            <?php if (!empty($item['product_id'])):
                                $imagePath = function_exists('sales_order_item_image_url')
                                    ? sales_order_item_image_url($item, 'medium')
                                    : (function_exists('sales_product_image_url')
                                        ? sales_product_image_url((int) $item['product_id'], (string) ($item['main_image'] ?? ''), 'medium')
                                        : app_url('/stock/product_image.php?product_id=' . (int) $item['product_id'] . '&size=medium&file=' . rawurlencode((string) ($item['main_image'] ?? ''))));
                                ?>
                                <img src="<?= htmlspecialchars((string) $imagePath, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars((string) $item['product_name']) ?>"
                                     class="ov-catalog-product-image"
                                     crossorigin="anonymous"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="ov-catalog-image-fallback"><i class="fas fa-image"></i></div>
                            <?php else: ?>
                                <div class="ov-catalog-image-fallback"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="ov-catalog-product-name"><?= htmlspecialchars((string) $item['product_name']) ?></div>
                            <?php if (!empty($item['product_code'])): ?>
                                <div class="ov-catalog-product-code"><?= htmlspecialchars((string) $item['product_code']) ?></div>
                            <?php endif; ?>
                            <div class="ov-catalog-description">
                                <?php
                                $description = trim((string) (!empty($item['description']) ? $item['description'] : ($item['product_description'] ?? '')));
                                if ($description !== '' && !empty($item['product_code'])) {
                                    $description = preg_replace('/\s*\[' . preg_quote((string) $item['product_code'], '/') . '\]\s*$/u', '', $description);
                                }
                                echo nl2br(htmlspecialchars($description));
                                ?>
                            </div>
                        </td>
                        <td class="ov-catalog-pricing">
                            <div><span class="ov-catalog-label">Quantity:</span><br><strong><?= number_format((float) $item['quantity']) ?></strong></div>
                            <div><span class="ov-catalog-label">Unit Price:</span><br><strong><?= number_format((float) $item['unit_price'], 2) ?> <?= htmlspecialchars((string) $currency) ?></strong></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ov-catalog-footer">For more information about these products, please contact us.</div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * @return array<string, mixed>
 */
function salesInvoiceViewLoadContext(int $id): array
{
    global $pdo;

    invoicesViewDeskBootstrap();
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $module = invoicesViewModuleQuery();

    $loaded = salesInvoiceViewLoadInvoice($salesDb, $id);
    if ($loaded === null) {
        throw new RuntimeException('Invoice not found.');
    }

    $invoice = $loaded['invoice'];
    $displayInvoiceNumber = $loaded['display_invoice_number'];
    $signatureUrl = $loaded['signature_url'];

    $orderId = (int) ($invoice['order_id'] ?? 0);
    $items = $orderId > 0 ? salesOrderViewLoadItems($salesDb, $orderId) : [];

    $company_settings = salesOrderViewLoadCompanySettings($salesDb);
    $isTruckOrder = salesInvoiceViewDetectTruckOrder($invoice, $items);
    if ($isTruckOrder) {
        $invoice['order_type'] = 'truck';
    }

    $invoiceDualCurrencyCtx = null;
    if ($isTruckOrder && function_exists('isRoadmaster') && isRoadmaster() && function_exists('sales_invoice_dual_currency_context')) {
        $invoiceDualCurrencyCtx = sales_invoice_dual_currency_context($invoice, $items, true);
    }

    $brandingLogoUrl = salesInvoiceViewResolveBrandingLogoUrl($company_settings);
    $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
    $share = salesInvoiceViewBuildShareData($invoice, $displayInvoiceNumber, $items, $company_settings);
    $status = (string) ($invoice['status'] ?? '');

    $ordStatus = 'unknown';
    if ($orderId > 0) {
        $ordSql = 'SELECT status FROM sales_orders WHERE id = ?';
        $ordParams = [$orderId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($ordSql, $ordParams, 'sales_orders');
        }
        $stmtOrd = $salesDb->prepare($ordSql);
        $stmtOrd->execute($ordParams);
        $linkedOrder = $stmtOrd->fetch(PDO::FETCH_ASSOC);
        $ordStatus = (string) ($linkedOrder['status'] ?? 'unknown');
    }

    $invoiceShowShip = $orderId > 0
        && empty($invoice['shipped_at'])
        && !in_array($ordStatus, ['shipped', 'delivered', 'cancelled'], true);

    $returnUrl = isset($_GET['return']) ? urldecode((string) $_GET['return']) : '';
    $invoiceReturnUrl = rawurlencode(sales_module_url('invoices/view.php', [
        'id' => $id,
        'module' => $module,
    ]));
    $invoiceProductsUrl = !empty($items)
        ? sales_module_url('products_view.php', [
            'invoice_id' => $id,
            'return' => $invoiceReturnUrl,
            'module' => $module,
        ])
        : '';

    $paymentUrl = '';
    if (!in_array($status, ['paid', 'draft'], true)) {
        require_once dirname(__DIR__, 4) . '/includes/revenue_sync.php';
        $revEntryId = syncInvoiceToRevenue($salesDb, $id);
        if ($revEntryId) {
            $paymentUrl = function_exists('app_url')
                ? app_url('/revenue_record_payment.php?id=' . $revEntryId)
                : '/revenue_record_payment.php?id=' . $revEntryId;
        }
    }

    $createFlash = null;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['sales_invoice_create_flash'])
        && (int) ($_SESSION['sales_invoice_create_flash']['invoice_id'] ?? 0) === $id) {
        $createFlash = $_SESSION['sales_invoice_create_flash'];
        unset($_SESSION['sales_invoice_create_flash']);
    } elseif (isset($_GET['msg']) && (string) $_GET['msg'] === 'created') {
        $stockKey = (string) ($_GET['stock'] ?? '');
        if ($stockKey === 'deducted') {
            $createFlash = [
                'stock_deduction' => [
                    'attempted' => true,
                    'success' => true,
                    'message' => 'Stock deducted successfully.',
                    'items_processed' => 0,
                ],
            ];
        } elseif ($stockKey === 'failed') {
            $createFlash = [
                'stock_deduction' => [
                    'attempted' => true,
                    'success' => false,
                    'message' => 'Stock was not deducted. Please check inventory.',
                    'items_processed' => 0,
                ],
            ];
        }
    }

    return [
        'module' => $module,
        'invoice_id' => $id,
        'display_invoice_number' => $displayInvoiceNumber,
        'create_flash' => $createFlash,
        'invoice' => [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'display_invoice_number' => $displayInvoiceNumber,
            'status' => $status,
            'email' => (string) ($invoice['email'] ?? ''),
            'phone' => (string) ($invoice['phone'] ?? ''),
            'contact_person' => (string) ($invoice['contact_person'] ?? ''),
            'company_name' => (string) ($invoice['company_name'] ?? ''),
            'salesperson' => (string) ($invoice['salesperson'] ?? ''),
            'currency' => (string) $currency,
            'order_id' => $orderId,
            'subtotal' => (float) ($invoice['subtotal'] ?? 0),
            'tax_amount' => (float) ($invoice['tax_amount'] ?? 0),
            'total_amount' => (float) ($invoice['total_amount'] ?? 0),
            'balance_due' => (float) ($invoice['balance_due'] ?? 0),
        ],
        'is_truck_order' => $isTruckOrder,
        'pipeline' => salesInvoiceViewBuildPipeline($status),
        'share' => $share,
        'flags' => [
            'can_edit' => $status === 'draft',
            'can_ship' => $invoiceShowShip,
            'can_register_payment' => !in_array($status, ['paid', 'draft'], true) && $paymentUrl !== '',
            'has_order' => $orderId > 0,
            'has_products' => !empty($items),
            'show_catalogue' => (bool) $share['show_catalogue'],
        ],
        'document_html' => salesInvoiceViewRenderDocumentHtml(
            $invoice,
            $items,
            $company_settings,
            $isTruckOrder,
            $signatureUrl,
            $invoiceDualCurrencyCtx,
            $brandingLogoUrl
        ),
        'catalog_html' => $share['show_catalogue']
            ? salesInvoiceViewRenderCatalogHtml($invoice, $items, $company_settings, $displayInvoiceNumber, $brandingLogoUrl)
            : '',
        'font_stylesheets' => function_exists('sales_document_font_stylesheet_links')
            ? sales_document_font_stylesheet_links($company_settings)
            : '',
        'document_font_family' => function_exists('sales_document_font_family_css')
            ? sales_document_font_family_css($company_settings)
            : "'Arima', Arial, sans-serif",
        'urls' => [
            'view' => sales_module_url('invoices/view.php', ['id' => $id, 'module' => $module]),
            'invoices_index' => sales_module_url('invoices/index.php', ['module' => $module]),
            'edit' => sales_module_url('invoices/edit.php', ['id' => $id, 'module' => $module]),
            'order_view' => $orderId > 0
                ? sales_module_url('orders/view.php', ['id' => $orderId, 'module' => $module])
                : '',
            'products' => $invoiceProductsUrl,
            'delivery_note' => $orderId > 0
                ? sales_module_url('orders/delivery_note.php', ['id' => $orderId, 'module' => $module])
                : '',
            'register_payment' => $paymentUrl,
            'send_email' => sales_module_url('send_doc.php', ['type' => 'invoice', 'id' => $id, 'module' => $module]),
            'return' => $returnUrl,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function salesInvoiceViewInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $id = invoicesViewParseId($_GET);
    if ($id <= 0) {
        throw new RuntimeException('Invoice id is required.');
    }

    return salesInvoiceViewLoadContext($id);
}

function invoicesViewDeskShellHeadExtras(array $companySettings = []): string
{
    $parts = [
        invoicesDeskShellHeadExtras(),
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link href="' . htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/sales-mobile.css') : '/assets/css/sales-mobile.css', ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">',
        '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>',
        '<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>',
        '<script>if (window.jspdf && window.jspdf.jsPDF) { window.jsPDF = window.jspdf.jsPDF; }</script>',
        '<script src="' . htmlspecialchars(function_exists('app_url') ? app_url('/modules/sales/assets/js/document-pdf-capture.js') : '/modules/sales/assets/js/document-pdf-capture.js', ENT_QUOTES, 'UTF-8') . '"></script>',
    ];

    if ($companySettings !== [] && function_exists('sales_document_font_stylesheet_links')) {
        $parts[] = sales_document_font_stylesheet_links($companySettings);
    }

    return implode("\n    ", $parts);
}

function salesInvoiceViewShouldUseReact(): bool
{
    if (!function_exists('salesInvoiceViewUsesReactShell') || !salesInvoiceViewUsesReactShell()) {
        return false;
    }

    return invoicesDeskModuleAssetUrls() !== null;
}

function salesInvoiceViewRenderReactShell(int $invoiceId): void
{
    $assets = invoicesDeskModuleAssetUrls();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Invoice View</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Invoice View</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/invoices/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    try {
        $preview = salesInvoiceViewLoadContext($invoiceId);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Invoice not found</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
        exit;
    }

    $module = invoicesViewModuleQuery();
    $displayNumber = (string) ($preview['display_invoice_number'] ?? 'Invoice');
    $page_title = 'Invoice ' . $displayNumber;
    $employeeHeaderTitle = '';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--invoice-view';

    $cfg = [
        'module' => $module,
        'invoice_id' => $invoiceId,
    ];

    $invoicesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__INVOICES_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__INVOICES_PAGE__ = ' . json_encode('invoice_view', JSON_UNESCAPED_SLASHES) . ';</script>';

    $invoicesViewHeadExtras = invoicesViewDeskShellHeadExtras(
        salesOrderViewLoadCompanySettings(function_exists('sales_pdo') ? sales_pdo() : $GLOBALS['pdo'])
    );

    require dirname(__FILE__) . '/invoices-view-react-shell.php';
    exit;
}
