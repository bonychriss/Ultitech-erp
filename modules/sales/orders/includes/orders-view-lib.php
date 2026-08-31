<?php

declare(strict_types=1);

require_once __DIR__ . '/orders-lib.php';

function ordersViewDeskBootstrap(): void
{
    ordersDeskBootstrap();
}

function ordersViewDeskRequireAccess(): void
{
    ordersViewDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function ordersViewParseId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

function ordersViewModuleQuery(): string
{
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';

    return $module !== '' ? $module : 'sales';
}

/**
 * @return array<string, mixed>
 */
function salesOrderViewLoadCompanySettings(PDO $salesDb): array
{
    $isRoadmaster = function_exists('isRoadmaster') && isRoadmaster();

    try {
        $company_id = (int) (currentCompanyId() ?? 0);
        if ($company_id > 0 && function_exists('columnExists') && columnExists('sales_settings', 'company_id', $salesDb)) {
            $stmtSettings = $salesDb->prepare('SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1');
            $stmtSettings->execute([$company_id]);
            $company_settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
        } else {
            $company_settings = $salesDb->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $company_settings = false;
    }

    if (!$company_settings) {
        $company_settings = [
            'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : '',
            'company_address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : '',
            'company_logo' => 'Untitled.jpg',
            'default_currency' => 'TZS',
            'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : '',
            'company_email' => defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '',
            'company_tin' => '',
            'company_vat' => '',
            'bank_details' => '',
            'company_website' => '',
            'include_product_catalogue' => 0,
        ];
    }

    if ($isRoadmaster) {
        $roadmasterFooterDefaults = [
            'truck_payment_details' => 'Payment details',
            'truck_terms' => 'Terms and Conditions..',
            'truck_validity' => 'Invoice is valid for 10 days',
            'truck_thanks_note' => 'Thank you for your business',
            'truck_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
            'spare_payment_details' => 'Payment details',
            'spare_terms' => 'Terms and Conditions..',
            'spare_validity' => 'Invoice is valid for 10 days',
            'spare_thanks_note' => 'Thank you for your business',
            'spare_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
        ];
        foreach ($roadmasterFooterDefaults as $footerKey => $footerDefault) {
            if (!isset($company_settings[$footerKey]) || trim((string) $company_settings[$footerKey]) === '') {
                $company_settings[$footerKey] = $footerDefault;
            }
        }
    }

    $company_settings['company_logo_url'] = getCompanyLogoUrl();
    foreach ([
        'company_name' => 'company_name',
        'company_address' => 'company_address',
        'company_phone' => 'company_phone',
        'company_email' => 'company_email',
        'company_tin' => 'company_tin',
    ] as $settingKey => $field) {
        $val = getCompanySetting($settingKey);
        if (is_string($val) && trim($val) !== '') {
            $company_settings[$field] = $val;
        }
    }

    $vrnVal = getCompanySetting('company_vrn');
    if (is_string($vrnVal) && trim($vrnVal) !== '') {
        $company_settings['company_vrn'] = $vrnVal;
    } else {
        $vatVal = getCompanySetting('company_vat');
        if (is_string($vatVal) && trim($vatVal) !== '') {
            $company_settings['company_vrn'] = $vatVal;
        }
    }

    $bankVal = getCompanySetting('bank_details');
    if (is_string($bankVal) && trim($bankVal) !== '') {
        $company_settings['bank_details'] = $bankVal;
    }

    $footerVal = getCompanySetting('document_footer_message');
    if (is_string($footerVal) && trim($footerVal) !== '') {
        $company_settings['document_footer_message'] = $footerVal;
    }

    $companyProfile = null;
    if (function_exists('getCurrentCompany')) {
        $companyProfile = getCurrentCompany();
    }
    if ((!is_array($companyProfile) || empty($companyProfile)) && function_exists('getRequestedCompany')) {
        $companyProfile = getRequestedCompany();
    }
    if (is_array($companyProfile) && !empty($companyProfile)) {
        $profileName = trim((string) ($companyProfile['company_name'] ?? ''));
        $profileAddress = trim((string) ($companyProfile['address'] ?? ''));
        $profilePhone = trim((string) ($companyProfile['phone'] ?? ''));
        $profileEmail = trim((string) ($companyProfile['email'] ?? ''));

        if ($profileName !== '') {
            $company_settings['company_name'] = $profileName;
        }
        if ($profileAddress !== '') {
            $company_settings['company_address'] = $profileAddress;
        }
        if ($profilePhone !== '') {
            $company_settings['company_phone'] = $profilePhone;
        }
        if ($profileEmail !== '') {
            $company_settings['company_email'] = $profileEmail;
        }
    }

    return $company_settings;
}

/**
 * @return array{order:array<string,mixed>,display_order_number:string,signature_url:string}|null
 */
function salesOrderViewLoadOrder(PDO $salesDb, int $id): ?array
{
    ensureCustomerColumnsExist();
    $cTinExpr = 'NULL AS tin';
    $cVrnExpr = 'NULL AS vrn';
    try {
        $custCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($custCols)) {
            if (in_array('tin', $custCols, true)) {
                $cTinExpr = 'c.tin';
            }
            if (in_array('vrn', $custCols, true)) {
                $cVrnExpr = 'c.vrn';
            }
        }
    } catch (Throwable $e) {
        // keep NULL aliases
    }

    $sql = "SELECT so.*, c.company_name, c.contact_person, c.email, c.phone, c.address, $cTinExpr, $cVrnExpr, u.full_name AS salesperson
            FROM sales_orders so
            LEFT JOIN customers c ON so.customer_id = c.id
            LEFT JOIN users u ON so.created_by = u.id
            WHERE so.id = ?";
    $params = [$id];
    $scope = salesCompanyScopeSql('sales_orders', 'so');
    $sql .= $scope[0];
    $params = array_merge($params, $scope[1]);

    $stmt = $salesDb->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }

    $displayOrderNumber = (string) ($order['order_number'] ?? '');
    $displayOrderNumber = preg_replace('/-OLD-\d+$/i', '', $displayOrderNumber) ?: $displayOrderNumber;

    $signatureUrl = function_exists('sales_resolve_document_signature_url')
        ? sales_resolve_document_signature_url($order, $salesDb)
        : '';

    return [
        'order' => $order,
        'display_order_number' => $displayOrderNumber,
        'signature_url' => $signatureUrl,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function salesOrderViewLoadItems(PDO $salesDb, int $orderId): array
{
    $productImageCol = null;
    $extraCols = [];
    $neededCols = ['vin', 'chassis_number', 'engine_number', 'truck_type', 'model_number', 'model_year', 'engine_model', 'transmission_model', 'item_type', 'oem_number'];

    try {
        $productCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('main_image', $productCols, true)) {
            $productImageCol = 'main_image';
        } elseif (in_array('image', $productCols, true)) {
            $productImageCol = 'image';
        }

        foreach ($neededCols as $col) {
            if (in_array($col, $productCols, true)) {
                $extraCols[] = "p.`$col`";
            } else {
                $extraCols[] = "NULL AS `$col`";
            }
        }
    } catch (Throwable $e) {
        foreach ($neededCols as $col) {
            $extraCols[] = "NULL AS `$col`";
        }
    }

    $productImageSelect = $productImageCol ? "p.`$productImageCol` AS main_image" : 'NULL AS main_image';
    $extraColsSelect = !empty($extraCols) ? ', ' . implode(', ', $extraCols) : '';

    $sqlItems = "SELECT soi.*, p.name AS product_name, p.product_code, p.description AS product_description, $productImageSelect $extraColsSelect
                 FROM sales_order_items soi
                 LEFT JOIN products p ON soi.product_id = p.id
                 WHERE soi.order_id = ?";

    $stmtItems = $salesDb->prepare($sqlItems);
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (function_exists('sales_enrich_order_items_images')) {
        $items = sales_enrich_order_items_images($items, $salesDb);
    }

    return $items;
}

/**
 * @param list<array<string, mixed>> $items
 */
function salesOrderViewDetectTruckOrder(array $order, array $items): bool
{
    if (!function_exists('isRoadmaster') || !isRoadmaster()) {
        return false;
    }

    $storedTruck = isset($order['order_type']) && strtolower(trim((string) $order['order_type'])) === 'truck';
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

function salesOrderViewFindLinkedInvoice(PDO $salesDb, int $orderId): array
{
    if ($orderId <= 0) {
        return ['id' => 0, 'invoice_number' => ''];
    }

    try {
        $invoiceCheckSql = 'SELECT id, invoice_number FROM invoices WHERE order_id = ?';
        $invoiceCheckParams = [$orderId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($invoiceCheckSql, $invoiceCheckParams, 'invoices');
        }
        $stmtCheck = $salesDb->prepare($invoiceCheckSql);
        $stmtCheck->execute($invoiceCheckParams);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['id' => 0, 'invoice_number' => ''];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => trim((string) ($row['invoice_number'] ?? '')),
        ];
    } catch (Throwable $e) {
        return ['id' => 0, 'invoice_number' => ''];
    }
}

function salesOrderViewFindLinkedInvoiceId(PDO $salesDb, int $orderId): int
{
    return (int) (salesOrderViewFindLinkedInvoice($salesDb, $orderId)['id'] ?? 0);
}

/**
 * @return array{ok:bool,message?:string,error?:string,status?:string}
 */
function salesOrderViewApplyStatusAction(PDO $salesDb, int $id, string $action, array $order): array
{
    $newStatus = '';
    if ($action === 'confirm') {
        $newStatus = 'confirmed';
    } elseif ($action === 'cancel') {
        $newStatus = 'cancelled';
    } elseif ($action === 'invoice') {
        $newStatus = 'invoiced';
    } elseif ($action === 'ship') {
        $newStatus = 'shipped';
    } elseif ($action === 'sent') {
        $newStatus = 'quotation';
    }

    if ($newStatus === '') {
        return ['ok' => false, 'error' => 'Unknown action.'];
    }

    if ($action === 'ship' && $order['status'] !== 'shipped' && $order['status'] !== 'delivered') {
        $stockCheck = checkStockAvailability($id);
        if (!$stockCheck['valid']) {
            return [
                'ok' => false,
                'error' => 'Cannot Ship: Insufficient Stock for ' . implode(', ', $stockCheck['errors']),
            ];
        }
        deductStockForOrder($id);
    }

    if ($action === 'cancel' && ($order['status'] === 'shipped' || $order['status'] === 'delivered')) {
        restoreStockForOrder($id);
    }

    if ($action === 'ship') {
        $updateSql = 'UPDATE sales_orders SET status = ?, shipped_at = NOW() WHERE id = ?';
        $salesDb->prepare($updateSql)->execute([$newStatus, $id]);
    } else {
        $updateSql = 'UPDATE sales_orders SET status = ? WHERE id = ?';
        $salesDb->prepare($updateSql)->execute([$newStatus, $id]);
    }

    return ['ok' => true, 'message' => 'Status updated', 'status' => $newStatus];
}

/**
 * @return list<array{key:string,label:string,state:string}>
 */
function salesOrderViewBuildPipeline(string $currentStatus): array
{
    $currentStatus = strtolower(trim($currentStatus));
    $stages = [
        'draft' => ['label' => 'Quotation', 'keys' => ['draft']],
        'sent' => ['label' => 'Quotation Sent', 'keys' => ['quotation']],
        'confirmed' => ['label' => 'Sales Order', 'keys' => ['confirmed', 'shipped', 'delivered']],
        'invoiced' => ['label' => 'Invoiced', 'keys' => ['invoiced', 'paid']],
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
function salesOrderViewBuildShareData(array $order, string $displayOrderNumber, array $items, array $company_settings): array
{
    $token = '';
    try {
        $token = generateShareToken('order', $order['id'], $_SESSION['user_id'] ?? null);
    } catch (Throwable $e) {
        $token = 'error';
    }

    $module = ordersViewModuleQuery();
    $docLink = sales_module_url('secure_download.php', ['token' => $token, 'module' => $module]);

    $senderName = $order['salesperson'] ?: 'Sales Team';
    $waBody = 'Dear ' . ($order['contact_person'] ?: 'Customer') . ",\n\n";
    $waBody .= 'We have prepared your order #' . $displayOrderNumber . '. ';
    $waBody .= "You can view it here:\n" . $docLink . "\n\n";
    $waBody .= 'Best regards,' . "\n" . $senderName;

    $orderSharePhone = preg_replace('/[^0-9]/', '', (string) ($order['phone'] ?? ''));
    if (substr($orderSharePhone, 0, 1) === '0') {
        $orderSharePhone = '255' . substr($orderSharePhone, 1);
    } elseif (strlen($orderSharePhone) === 9) {
        $orderSharePhone = '255' . $orderSharePhone;
    }

    $showCatalogue = !empty($items) && (
        !empty($company_settings['include_catalogue'])
        || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1
    );

    return [
        'doc_link' => $docLink,
        'whatsapp_body' => $waBody,
        'whatsapp_phone' => $orderSharePhone,
        'whatsapp_url' => $orderSharePhone !== ''
            ? 'https://wa.me/' . $orderSharePhone . '?text=' . rawurlencode($waBody)
            : '',
        'show_catalogue' => $showCatalogue,
    ];
}

/**
 * @param list<array<string, mixed>> $items
 */
function salesOrderViewRenderDocumentHtml(
    array $order,
    array $items,
    array $company_settings,
    string $displayOrderNumber,
    bool $isTruckOrder,
    string $signatureUrl,
    bool $hasLinkedInvoice = false
): string {
    $currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
    $invoice = $order;

    ob_start();
    echo '<div id="order-content">';
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
function salesOrderViewRenderCatalogHtml(
    array $order,
    array $items,
    array $company_settings,
    string $displayOrderNumber
): string {
    if (empty($items)) {
        return '';
    }

    $showCatalogue = !empty($company_settings['include_catalogue'])
        || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1;
    if (!$showCatalogue) {
        return '';
    }

    $currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');

    ob_start();
    ?>
    <div id="catalog-content" class="sheet-container ov-catalog-sheet">
        <div class="ov-catalog-inner">
            <div class="ov-catalog-header">
                <h1 class="sheet-title ov-catalog-title">
                    Product Catalog
                    <small class="ov-catalog-subtitle">- Order #<?= htmlspecialchars($displayOrderNumber) ?></small>
                </h1>
                <div class="ov-catalog-company">
                    <?php
                    $logoPath = !empty($company_settings['company_logo_url'])
                        ? $company_settings['company_logo_url']
                        : '/assets/images/' . ($company_settings['company_logo'] ?: 'Untitled.jpg');
                    ?>
                    <img src="<?= htmlspecialchars((string) $logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="Company Logo" class="ov-catalog-logo" crossorigin="anonymous" onerror="this.style.display='none'">
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
                                $description = !empty($item['description']) ? $item['description'] : ($item['product_description'] ?? '');
                                echo nl2br(htmlspecialchars((string) $description));
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
function salesOrderViewLoadContext(int $id): array
{
    global $pdo;

    ordersViewDeskBootstrap();
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $module = ordersViewModuleQuery();

    $loaded = salesOrderViewLoadOrder($salesDb, $id);
    if ($loaded === null) {
        throw new RuntimeException('Sales Order not found.');
    }

    $order = $loaded['order'];
    $displayOrderNumber = $loaded['display_order_number'];
    $signatureUrl = $loaded['signature_url'];
    $items = salesOrderViewLoadItems($salesDb, $id);
    $company_settings = salesOrderViewLoadCompanySettings($salesDb);
    $isTruckOrder = salesOrderViewDetectTruckOrder($order, $items);
    if ($isTruckOrder) {
        $order['order_type'] = 'truck';
    }

    $currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
    $share = salesOrderViewBuildShareData($order, $displayOrderNumber, $items, $company_settings);
    $status = (string) ($order['status'] ?? '');
    $statusKey = strtolower(trim($status));
    $linkedInvoice = salesOrderViewFindLinkedInvoice($salesDb, $id);
    $linkedInvoiceId = (int) ($linkedInvoice['id'] ?? 0);
    $linkedInvoiceNumber = trim((string) ($linkedInvoice['invoice_number'] ?? ''));
    $documentDisplayNumber = ($linkedInvoiceId > 0 && $linkedInvoiceNumber !== '')
        ? $linkedInvoiceNumber
        : $displayOrderNumber;
    $canCreateInvoice = $linkedInvoiceId <= 0
        && !in_array($statusKey, ['cancelled', 'canceled', 'delivered', 'invoiced', 'paid'], true)
        && in_array($statusKey, ['draft', 'quotation', 'sent', 'confirmed'], true);
    $canViewInvoice = $linkedInvoiceId > 0;
    $invoiceCreateUrl = sales_module_url('invoices/create.php', ['order_id' => $id, 'module' => $module]);
    $invoiceViewUrl = $linkedInvoiceId > 0
        ? sales_module_url('invoices/view.php', ['id' => $linkedInvoiceId, 'module' => $module])
        : '';

    $returnUrl = isset($_GET['return']) ? urldecode((string) $_GET['return']) : '';
    $orderReturnUrl = rawurlencode(sales_module_url('orders/view.php', [
        'id' => (int) $order['id'],
        'module' => $module,
    ]));
    $orderProductsUrl = sales_module_url('products_view.php', [
        'order_id' => (int) $order['id'],
        'return' => $orderReturnUrl,
        'module' => $module,
    ]);

    $canCancel = !in_array($statusKey, ['cancelled', 'canceled', 'delivered', 'invoiced', 'paid'], true);
    $documentLabel = function_exists('sales_order_document_title_label')
        ? sales_order_document_title_label($statusKey !== '' ? $statusKey : $status, $linkedInvoiceId > 0)
        : 'Sales Order';

    return [
        'module' => $module,
        'order_id' => $id,
        'display_order_number' => $displayOrderNumber,
        'display_document_number' => $documentDisplayNumber,
        'linked_invoice_id' => $linkedInvoiceId,
        'linked_invoice_number' => $linkedInvoiceNumber,
        'document_label' => $documentLabel,
        'order' => [
            'id' => (int) ($order['id'] ?? 0),
            'order_number' => (string) ($order['order_number'] ?? ''),
            'display_order_number' => $displayOrderNumber,
            'display_document_number' => $documentDisplayNumber,
            'status' => $status,
            'email' => (string) ($order['email'] ?? ''),
            'phone' => (string) ($order['phone'] ?? ''),
            'contact_person' => (string) ($order['contact_person'] ?? ''),
            'company_name' => (string) ($order['company_name'] ?? ''),
            'salesperson' => (string) ($order['salesperson'] ?? ''),
            'currency' => (string) $currency,
            'total_amount' => (float) ($order['total_amount'] ?? 0),
        ],
        'is_truck_order' => $isTruckOrder,
        'pipeline' => salesOrderViewBuildPipeline($statusKey !== '' ? $statusKey : $status),
        'share' => $share,
        'flags' => [
            'can_mark_sent' => $statusKey === 'draft',
            'can_confirm' => in_array($statusKey, ['draft', 'quotation'], true),
            'can_create_invoice' => $canCreateInvoice,
            'can_view_invoice' => $canViewInvoice,
            'can_open_invoice' => $canCreateInvoice,
            'has_linked_invoice' => $linkedInvoiceId > 0,
            'can_cancel' => $canCancel,
            'show_catalogue' => (bool) $share['show_catalogue'],
        ],
        'document_html' => salesOrderViewRenderDocumentHtml(
            $order,
            $items,
            $company_settings,
            $documentDisplayNumber,
            $isTruckOrder,
            $signatureUrl,
            $linkedInvoiceId > 0
        ),
        'catalog_html' => $share['show_catalogue']
            ? salesOrderViewRenderCatalogHtml($order, $items, $company_settings, $displayOrderNumber)
            : '',
        'font_stylesheets' => function_exists('sales_document_font_stylesheet_links')
            ? sales_document_font_stylesheet_links($company_settings)
            : '',
        'document_font_family' => function_exists('sales_document_font_family_css')
            ? sales_document_font_family_css($company_settings)
            : "'Arima', Arial, sans-serif",
        'urls' => [
            'view' => sales_module_url('orders/view.php', ['id' => $id, 'module' => $module]),
            'orders_index' => sales_module_url('orders/index.php', ['module' => $module]),
            'edit' => sales_module_url('orders/edit.php', ['id' => $id, 'module' => $module]),
            'products' => $orderProductsUrl,
            'create_invoice' => $canCreateInvoice ? $invoiceCreateUrl : '',
            'view_invoice' => $invoiceViewUrl,
            'send_email' => sales_module_url('send_doc.php', ['type' => 'order', 'id' => $id, 'module' => $module]),
            'return' => $returnUrl,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function salesOrderViewInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $id = ordersViewParseId($_GET);
    if ($id <= 0) {
        throw new RuntimeException('Order id is required.');
    }

    return salesOrderViewLoadContext($id);
}

function ordersViewDeskShellHeadExtras(array $companySettings = []): string
{
    $parts = [
        ordersDeskShellHeadExtras(),
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

function salesOrderViewShouldUseReact(): bool
{
    if (!function_exists('salesOrderViewUsesReactShell') || !salesOrderViewUsesReactShell()) {
        return false;
    }

    return ordersDeskLoadReactAssets() !== null;
}

function salesOrderViewRenderReactShell(int $orderId): void
{
    $assets = ordersDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Order View</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Order View</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/orders/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    try {
        $preview = salesOrderViewLoadContext($orderId);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Sales Order not found</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
        exit;
    }

    $module = ordersViewModuleQuery();
    $displayNumber = (string) ($preview['display_order_number'] ?? 'Order');
    $page_title = (string) ($preview['document_label'] ?? 'Order') . ' ' . $displayNumber;
    $employeeHeaderTitle = '';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--order-view';

    $cfg = [
        'module' => $module,
        'order_id' => $orderId,
    ];

    $ordersHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__SALES_ORDERS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_ORDERS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('order_view', JSON_UNESCAPED_SLASHES) . ';</script>';

    $ordersViewHeadExtras = ordersViewDeskShellHeadExtras(
        salesOrderViewLoadCompanySettings(function_exists('sales_pdo') ? sales_pdo() : $GLOBALS['pdo'])
    );

    require dirname(__FILE__) . '/orders-view-react-shell.php';
    exit;
}
