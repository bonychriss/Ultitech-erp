<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/load-data.php';
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/delivery-note-invoice.php';
require_once dirname(__DIR__, 3) . '/modules/sales/functions.php';

function deliveryNoteViewBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 2) . '/config/database.php';
        $booted = true;
    }
}

function deliveryNoteViewRequireAccess(): void
{
    deliveryNoteViewBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function deliveryNoteViewParseId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

function deliveryNoteViewModuleQuery(): string
{
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'deliveries';

    return $module !== '' ? $module : 'deliveries';
}

/**
 * @return array{note:array<string,mixed>,items:list<array<string,mixed>>,has_skus:bool,salesperson_name:string,public_brand:array<string,mixed>,sales_settings:array<string,mixed>,linked_order:?array,is_public:bool}
 */
function deliveryNoteViewLoadNote(PDO $pdo, int $id, bool $isPublic = false, ?string $hash = null): array
{
    $order = null;
    if ($isPublic) {
        if ($hash === null || $hash === '') {
            throw new RuntimeException('Valid verification hash missing.');
        }
        $order = getOrderByVerificationHash($hash);
        if (!$order || (int) ($order['delivery_note_id'] ?? 0) !== $id) {
            throw new RuntimeException('Invalid or expired verification link.');
        }
    }

    $stmt = $pdo->prepare('SELECT dn.*, u.full_name AS creator_name
        FROM delivery_notes dn
        JOIN users u ON dn.created_by = u.id
        WHERE dn.id = ?');
    $stmt->execute([$id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        throw new RuntimeException('Delivery note not found.');
    }

    $salespersonName = deliveries_delivery_note_salesperson($pdo, $note);

    $linkedOrder = null;
    if (is_array($order)) {
        $linkedOrder = $order;
    } elseif (!empty($note['order_id'])) {
        $stmtOrd = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
        $stmtOrd->execute([(int) $note['order_id']]);
        $linkedOrder = $stmtOrd->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $companyId = 0;
    if (is_array($linkedOrder)) {
        $companyId = deliveries_resolve_order_company_id($pdo, $linkedOrder);
    }
    if ($companyId <= 0) {
        $companyId = (int) (currentCompanyId() ?? 0);
    }
    if ($companyId <= 0 && function_exists('getRequestedCompany')) {
        $reqCo = getRequestedCompany();
        if (is_array($reqCo) && !empty($reqCo['id'])) {
            $companyId = (int) $reqCo['id'];
        }
    }

    $publicBrand = deliveries_load_public_company_branding($pdo, $companyId);
    $salesSettings = [];
    if ($companyId > 0 && function_exists('tableExists') && tableExists('sales_settings', $pdo)) {
        try {
            if (function_exists('columnExists') && columnExists('sales_settings', 'company_id', $pdo)) {
                $settingsStmt = $pdo->prepare('SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1');
                $settingsStmt->execute([$companyId]);
                $salesSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } else {
                $salesSettings = $pdo->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            $salesSettings = [];
        }
    }

    $items = json_decode((string) ($note['items_json'] ?? '[]'), true);
    if (!is_array($items)) {
        $items = [];
    }

    $hasSkus = false;
    $productIds = [];
    foreach ($items as $item) {
        if (!empty($item['product_id'])) {
            $productIds[] = (int) $item['product_id'];
        }
    }

    $productImages = [];
    if ($productIds !== []) {
        $idsStr = implode(',', array_unique($productIds));
        try {
            $stmtImg = $pdo->query("SELECT id, main_image FROM products WHERE id IN ($idsStr)");
            while ($row = $stmtImg->fetch(PDO::FETCH_ASSOC)) {
                $productImages[(int) $row['id']] = trim((string) ($row['main_image'] ?? ''));
            }
        } catch (Throwable $e) {
            $productImages = [];
        }
    }

    foreach ($items as &$item) {
        if (!empty($item['sku']) && $item['sku'] !== '-') {
            $hasSkus = true;
        }
        $item['description'] = ucwords(strtolower((string) ($item['description'] ?? '')));
        if (!empty($item['product_id']) && isset($productImages[(int) $item['product_id']])) {
            $dbImg = $productImages[(int) $item['product_id']];
            if ($dbImg !== '') {
                $item['main_image'] = $dbImg;
            }
        }
    }
    unset($item);

    // Resolve filenames from gallery + tenant disk, then build product_image.php URLs
    // (static /stock/uploads/... paths 404 for tenant storage under company-slug routes).
    if (function_exists('sales_load_stock_image_helpers')) {
        sales_load_stock_image_helpers();
    }
    if (function_exists('sales_enrich_order_items_images')) {
        $items = sales_enrich_order_items_images($items, $pdo);
    }
    foreach ($items as &$item) {
        $pid = (int) ($item['product_id'] ?? 0);
        $img = trim((string) ($item['main_image'] ?? ''));
        $item['image_url'] = '';
        if ($pid <= 0 || $img === '') {
            continue;
        }
        if (function_exists('stock_product_list_image_url')) {
            $item['image_url'] = (string) stock_product_list_image_url($pid, $img, 'medium');
        } elseif (function_exists('sales_product_image_url')) {
            $item['image_url'] = (string) sales_product_image_url($pid, $img, 'medium');
        } elseif (function_exists('app_url')) {
            $item['image_url'] = app_url(
                'stock/product_image.php?' . http_build_query([
                    'product_id' => $pid,
                    'size' => 'medium',
                    'file' => basename($img),
                ])
            );
        }
    }
    unset($item);

    return [
        'note' => $note,
        'items' => $items,
        'has_skus' => $hasSkus,
        'salesperson_name' => $salespersonName,
        'public_brand' => $publicBrand,
        'sales_settings' => $salesSettings,
        'linked_order' => $linkedOrder,
        'is_public' => $isPublic,
    ];
}

/**
 * @return list<array{key:string,label:string,state:string}>
 */
function deliveryNoteViewBuildPipeline(array $note): array
{
    $signed = !empty($note['receiver_signature_path']);
    $stages = [
        ['key' => 'created', 'label' => 'Created'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'signed', 'label' => 'Signed'],
    ];

    $activeIndex = $signed ? 2 : 1;
    $out = [];
    foreach ($stages as $index => $stage) {
        $state = 'pending';
        if ($index < $activeIndex) {
            $state = 'done';
        } elseif ($index === $activeIndex) {
            $state = 'active';
        }
        $out[] = [
            'key' => $stage['key'],
            'label' => $stage['label'],
            'state' => $state,
        ];
    }

    return $out;
}

function deliveryNoteViewRenderDocumentHtml(array $ctx): string
{
    $note = $ctx['note'];
    $items = $ctx['items'];
    $hasSkus = $ctx['has_skus'];
    $salespersonName = $ctx['salesperson_name'];
    $publicBrand = $ctx['public_brand'];
    $salesSettings = $ctx['sales_settings'];

    $docFontStack = function_exists('sales_document_font_family_css')
        ? sales_document_font_family_css($salesSettings)
        : "'Inter', sans-serif";

    $dnCompanyName = (string) ($publicBrand['name'] ?? '');
    $dnCompanyAddress = (string) ($publicBrand['address'] ?? '');
    $dnCompanyPhone = (string) ($publicBrand['phone'] ?? '');
    $dnLogoUrl = (string) ($publicBrand['logoUrl'] ?? '');

    ob_start();
    echo '<div id="delivery-note-content">';
    include dirname(__DIR__) . '/view-delivery-note-inner.php';
    echo '</div>';

    return (string) ob_get_clean();
}

/**
 * @return array<string, mixed>
 */
function deliveryNoteViewLoadContext(int $id, bool $isPublic = false, ?string $hash = null): array
{
    global $pdo;

    deliveryNoteViewBootstrap();
    $module = deliveryNoteViewModuleQuery();
    $ctx = deliveryNoteViewLoadNote($pdo, $id, $isPublic, $hash);
    $note = $ctx['note'];
    $linkedOrder = $ctx['linked_order'];

    $displayNumber = (string) ($note['note_number'] ?? '');
    $orderId = (int) ($note['order_id'] ?? 0);
    $invoiceId = 0;
    if (is_array($linkedOrder)) {
        $invoiceId = deliveries_resolve_sales_invoice_id($pdo, $linkedOrder);
    }

    $salesSettings = $ctx['sales_settings'];
    $docFontStack = function_exists('sales_document_font_family_css')
        ? sales_document_font_family_css($salesSettings)
        : "'Inter', sans-serif";

    $signed = !empty($note['receiver_signature_path']);
    $canDownload = !$isPublic || $signed;

    return [
        'module' => $module,
        'note_id' => $id,
        'display_note_number' => $displayNumber,
        'note' => [
            'id' => (int) ($note['id'] ?? 0),
            'note_number' => $displayNumber,
            'customer_name' => (string) ($note['customer_name'] ?? ''),
            'customer_phone' => (string) ($note['customer_phone'] ?? ''),
            'delivery_address' => (string) ($note['delivery_address'] ?? ''),
            'delivery_date' => (string) ($note['delivery_date'] ?? ''),
            'salesperson_name' => (string) $ctx['salesperson_name'],
            'order_id' => $orderId,
            'is_signed' => $signed,
        ],
        'pipeline' => deliveryNoteViewBuildPipeline($note),
        'flags' => [
            'can_download' => $canDownload,
            'is_public' => $isPublic,
            'has_order' => $orderId > 0,
            'has_invoice' => $invoiceId > 0,
            'is_signed' => $signed,
        ],
        'document_html' => deliveryNoteViewRenderDocumentHtml($ctx),
        'font_stylesheets' => function_exists('sales_document_font_stylesheet_links')
            ? sales_document_font_stylesheet_links($salesSettings)
            : '',
        'document_font_family' => $docFontStack,
        'urls' => [
            'view' => deliveries_module_url('deliveries/view_delivery_note.php') . '&id=' . $id,
            'delivery_notes_index' => deliveries_module_url('deliveries/delivery_notes.php'),
            'order_view' => $orderId > 0
                ? deliveries_module_url('deliveries/order_details.php') . '&order_id=' . $orderId
                : '',
            'invoice_view' => $invoiceId > 0
                ? (function_exists('sales_module_url')
                    ? sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => 'sales'])
                    : deliveries_resolve_public_path('modules/sales/invoices/view.php?id=' . $invoiceId))
                : '',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function deliveryNoteViewInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'deliveries';

    $id = deliveryNoteViewParseId($_GET);
    if ($id <= 0) {
        throw new RuntimeException('Delivery note id is required.');
    }

    return deliveryNoteViewLoadContext($id);
}

function deliveryNoteViewDeskHeadExtras(array $salesSettings = []): string
{
    $parts = [
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">',
        '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>',
        '<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>',
        '<script>if (window.jspdf && window.jspdf.jsPDF) { window.jsPDF = window.jspdf.jsPDF; }</script>',
    ];

    if ($salesSettings !== [] && function_exists('sales_document_font_stylesheet_links')) {
        $parts[] = sales_document_font_stylesheet_links($salesSettings);
    }

    return implode("\n    ", $parts);
}

function deliveryNoteViewShouldUseReact(): bool
{
    $assets = deliveriesUiLoadReactAssets();

    return $assets !== null;
}

function deliveryNoteViewRenderReactShell(int $noteId): void
{
    $assets = deliveriesUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Delivery Note</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Delivery Note</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>deliveries/deliveries-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    try {
        $preview = deliveryNoteViewLoadContext($noteId);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Delivery note not found</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</body></html>';
        exit;
    }

    $module = deliveryNoteViewModuleQuery();
    $displayNumber = (string) ($preview['display_note_number'] ?? 'Delivery Note');
    $page_title = 'Delivery Note ' . $displayNumber;
    $employeeHeaderTitle = '';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--delivery-note-view';

    $cfg = [
        'page' => 'delivery-note-view',
        'module' => $module,
        'note_id' => $noteId,
        'deliveryNoteViewInitUrl' => $assets['deliveryNoteViewInitUrl'] ?? deliveriesUiPublicUrl('api/delivery-note-view-init.php'),
    ];

    $salesSettings = [];
    try {
        global $pdo;
        $ctx = deliveryNoteViewLoadNote($pdo, $noteId);
        $salesSettings = $ctx['sales_settings'];
    } catch (Throwable $e) {
        $salesSettings = [];
    }

    $dlvHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__DELIVERIES_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';</script>';

    $deliveryNoteViewHeadExtras = deliveryNoteViewDeskHeadExtras($salesSettings);

    require dirname(__DIR__) . '/render-delivery-note-view-shell.php';
    exit;
}

function deliveryNoteViewRenderPublicPage(): void
{
    global $pdo;

    deliveryNoteViewBootstrap();
    $id = deliveryNoteViewParseId($_GET);
    if ($id <= 0) {
        http_response_code(400);
        die('ID Missing');
    }

    $hash = isset($_GET['hash']) ? (string) $_GET['hash'] : '';
    try {
        $ctx = deliveryNoteViewLoadNote($pdo, $id, true, $hash);
        $preview = deliveryNoteViewLoadContext($id, true, $hash);
    } catch (Throwable $e) {
        http_response_code(404);
        die(htmlspecialchars($e->getMessage()));
    }

    $note = $ctx['note'];
    $salesSettings = $ctx['sales_settings'];
    $documentHtml = deliveryNoteViewRenderDocumentHtml($ctx);
    $docFontStack = function_exists('sales_document_font_family_css')
        ? sales_document_font_family_css($salesSettings)
        : "'Inter', sans-serif";
    $signed = !empty($note['receiver_signature_path']);

    require dirname(__DIR__) . '/view-delivery-note-public.php';
    exit;
}
