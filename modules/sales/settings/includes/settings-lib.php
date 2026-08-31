<?php

declare(strict_types=1);

function settingsDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 4) . '/includes/config.php';
        require_once dirname(__DIR__, 4) . '/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/functions.php';
        $booted = true;
    }
}

function settingsDeskRequireAccess(): void
{
    settingsDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function settingsDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    $assetBase = function_exists('sales_app_url')
        ? sales_app_url('modules/sales/settings/frontend/dist/assets/')
        : '/modules/sales/settings/frontend/dist/assets/';
    $apiUrl = function_exists('sales_app_url')
        ? sales_app_url('modules/sales/settings/api')
        : '/modules/sales/settings/api';

    return [
        'distHtml' => $distHtml,
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function settingsDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 4) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

function sales_settings_sync_schema(PDO $pdo): void
{
    try {
        $stmt = $pdo->query('DESCRIBE sales_settings');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = [];
        if (!in_array('company_id', $columns, true)) {
            $missing[] = 'ADD COLUMN company_id INT DEFAULT 0';
        }
        if (!in_array('spare_part_layout', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_part_layout INT DEFAULT 1';
        }
        if (!in_array('truck_layout', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_layout INT DEFAULT 1';
        }
        if (!in_array('truck_footer', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_footer TEXT';
        }
        if (!in_array('spare_footer', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_footer TEXT';
        }
        if (!in_array('truck_payment_details', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_payment_details TEXT';
        }
        if (!in_array('truck_terms', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_terms TEXT';
        }
        if (!in_array('truck_validity', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_validity TEXT';
        }
        if (!in_array('truck_thanks_note', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_thanks_note TEXT';
        }
        if (!in_array('truck_return_policy', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_return_policy TEXT';
        }
        if (!in_array('spare_payment_details', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_payment_details TEXT';
        }
        if (!in_array('spare_terms', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_terms TEXT';
        }
        if (!in_array('spare_validity', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_validity TEXT';
        }
        if (!in_array('spare_thanks_note', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_thanks_note TEXT';
        }
        if (!in_array('spare_return_policy', $columns, true)) {
            $missing[] = 'ADD COLUMN spare_return_policy TEXT';
        }
        if (!in_array('document_footer_message', $columns, true)) {
            $missing[] = 'ADD COLUMN document_footer_message TEXT NULL';
        }
        if (!in_array('truck_remarks', $columns, true)) {
            $missing[] = 'ADD COLUMN truck_remarks TEXT NULL';
        }
        if (!in_array('enable_tax_inclusive', $columns, true)) {
            $missing[] = 'ADD COLUMN enable_tax_inclusive TINYINT(1) DEFAULT 0';
        }
        if (!in_array('enable_tax_exclusive', $columns, true)) {
            $missing[] = 'ADD COLUMN enable_tax_exclusive TINYINT(1) DEFAULT 1';
        }
        if (!in_array('sales_document_font', $columns, true)) {
            $missing[] = "ADD COLUMN sales_document_font VARCHAR(64) DEFAULT 'arima'";
        }

        if ($missing !== []) {
            $pdo->exec('ALTER TABLE sales_settings ' . implode(', ', $missing));
        }
    } catch (Throwable $e) {
        // Table may not exist yet.
    }
}

function sales_settings_sync_products_schema(PDO $pdo): void
{
    try {
        $stmt = $pdo->query('DESCRIBE products');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = [];
        $needed = [
            'vin' => 'ADD COLUMN vin VARCHAR(255) NULL',
            'chassis_number' => 'ADD COLUMN chassis_number VARCHAR(255) NULL',
            'engine_number' => 'ADD COLUMN engine_number VARCHAR(255) NULL',
            'truck_type' => 'ADD COLUMN truck_type VARCHAR(255) NULL',
            'model_number' => 'ADD COLUMN model_number VARCHAR(255) NULL',
            'model_year' => 'ADD COLUMN model_year VARCHAR(10) NULL',
            'engine_model' => 'ADD COLUMN engine_model VARCHAR(255) NULL',
            'transmission_model' => 'ADD COLUMN transmission_model VARCHAR(255) NULL',
            'item_type' => "ADD COLUMN item_type VARCHAR(50) DEFAULT 'spare'",
        ];

        foreach ($needed as $col => $sql) {
            if (!in_array($col, $columns, true)) {
                $missing[] = $sql;
            }
        }

        if ($missing !== []) {
            $pdo->exec('ALTER TABLE products ' . implode(', ', $missing));
        }
    } catch (Throwable $e) {
        // Silently fail.
    }
}

/**
 * @return array<string, mixed>
 */
function sales_settings_default_row(): array
{
    return [
        'company_name' => 'Ultimate General Trading Company',
        'company_address' => 'Mikocheni B, Dar es salaam Tanzania',
        'company_logo' => 'Untitled.jpg',
        'company_phone' => '',
        'company_email' => '',
        'company_website' => '',
        'company_tin' => '',
        'company_vat' => '',
        'bank_details' => '',
        'default_currency' => 'TZS',
        'invoice_remarks' => '',
        'document_footer_message' => '',
        'spare_part_layout' => 1,
        'truck_layout' => 1,
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
        'enable_tax_inclusive' => 0,
        'enable_tax_exclusive' => 1,
        'sales_document_font' => 'arima',
    ];
}

/**
 * @return array<string, mixed>
 */
function sales_settings_field_map(): array
{
    return [
        'company_name' => 'company_name',
        'company_address' => 'company_address',
        'company_tin' => 'company_tin',
        'company_vat' => 'company_vat',
        'company_phone' => 'company_phone',
        'company_email' => 'company_email',
        'company_website' => 'company_website',
        'bank_details' => 'bank_details',
        'default_currency' => 'default_currency',
        'invoice_remarks' => 'invoice_remarks',
        'document_footer_message' => 'document_footer_message',
        'spare_part_layout' => 'spare_part_layout',
        'truck_layout' => 'truck_layout',
        'truck_payment_details' => 'truck_payment_details',
        'truck_terms' => 'truck_terms',
        'truck_validity' => 'truck_validity',
        'truck_thanks_note' => 'truck_thanks_note',
        'truck_return_policy' => 'truck_return_policy',
        'spare_payment_details' => 'spare_payment_details',
        'spare_terms' => 'spare_terms',
        'spare_validity' => 'spare_validity',
        'spare_thanks_note' => 'spare_thanks_note',
        'spare_return_policy' => 'spare_return_policy',
        'truck_remarks' => 'truck_remarks',
        'enable_tax_inclusive' => 'enable_tax_inclusive',
        'enable_tax_exclusive' => 'enable_tax_exclusive',
        'sales_document_font' => 'sales_document_font',
    ];
}

/**
 * @return array<string, mixed>
 */
function sales_settings_fetch(PDO $pdo, int $companyId): array
{
    sales_settings_sync_schema($pdo);
    sales_settings_sync_products_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        return sales_settings_default_row();
    }

    return $settings;
}

/**
 * @param array<string, mixed> $post
 * @param array<string, mixed> $files
 * @return array{success:bool,message:string}
 */
function sales_settings_save(PDO $pdo, int $companyId, array $post, array $files = []): array
{
    sales_settings_sync_schema($pdo);

    $fieldMap = sales_settings_field_map();
    $updateParts = [];
    $params = [];

    foreach ($fieldMap as $postKey => $dbCol) {
        if (array_key_exists($postKey, $post)) {
            $value = $post[$postKey];
            if ($postKey === 'sales_document_font' && function_exists('getSystemFontCatalog')) {
                $fontKey = strtolower(trim((string) $value));
                $catalog = getSystemFontCatalog();
                if (!isset($catalog[$fontKey])) {
                    $fontKey = 'arima';
                }
                $value = $fontKey;
            }
            $updateParts[] = "$dbCol = ?";
            $params[] = $value;
        }
    }

    if (isset($files['company_logo']) && is_array($files['company_logo']) && ($files['company_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = (string) ($files['company_logo']['name'] ?? '');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed, true)) {
            $newFilename = 'company_logo_' . time() . '.' . $ext;
            $uploadPath = dirname(__DIR__, 4) . '/assets/images/' . $newFilename;

            if (move_uploaded_file($files['company_logo']['tmp_name'], $uploadPath)) {
                $updateParts[] = 'company_logo = ?';
                $params[] = $newFilename;
            }
        }
    }

    if ($updateParts === []) {
        return ['success' => true, 'message' => 'No changes provided'];
    }

    try {
        $check = $pdo->prepare('SELECT id FROM sales_settings WHERE company_id = ?');
        $check->execute([$companyId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $sql = 'UPDATE sales_settings SET ' . implode(', ', $updateParts) . ' WHERE company_id = ?';
            $params[] = $companyId;
            $stmt = $pdo->prepare($sql);
        } else {
            $cols = array_values($fieldMap);
            $cols[] = 'company_id';
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));

            $insertParams = [];
            foreach ($fieldMap as $postKey => $dbCol) {
                $insertParams[] = $post[$postKey] ?? null;
            }
            $insertParams[] = $companyId;

            $sql = 'INSERT INTO sales_settings (' . implode(', ', $cols) . ") VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $params = $insertParams;
        }

        if ($stmt->execute($params)) {
            return ['success' => true, 'message' => 'Settings updated successfully'];
        }

        $err = $stmt->errorInfo();

        return ['success' => false, 'message' => 'Database error: ' . ($err[2] ?? 'Unknown error')];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
    }
}

/**
 * @return array<string, list<array{id:int,label:string,description:string}>>
 */
function sales_settings_layout_catalog(): array
{
    return [
        'truck' => [
            [
                'id' => 1,
                'label' => 'Watermark',
                'description' => 'High-fidelity truck layout with grayscale watermark styling.',
                'comingSoon' => false,
            ],
            [
                'id' => 2,
                'label' => 'Classic',
                'description' => 'Structured truck layout with boxed sections.',
                'comingSoon' => true,
            ],
            [
                'id' => 3,
                'label' => 'Minimalist',
                'description' => 'Clean truck layout with simplified header styling.',
                'comingSoon' => true,
            ],
        ],
        'spare' => [
            [
                'id' => 1,
                'label' => 'Premium',
                'description' => 'Borderless stone header with clean product rows.',
                'comingSoon' => false,
            ],
            [
                'id' => 2,
                'label' => 'Classic',
                'description' => 'Boxed table borders with structured sections.',
                'comingSoon' => false,
            ],
            [
                'id' => 3,
                'label' => 'Minimalist',
                'description' => 'Dark blue header with white typography.',
                'comingSoon' => false,
            ],
        ],
        'ultimate' => [
            [
                'id' => 1,
                'label' => 'Standard',
                'description' => 'Classic Ultimate invoice layout used on quotations and invoices.',
                'comingSoon' => false,
            ],
        ],
    ];
}

/**
 * @return array{invoice:array<string,mixed>,items:list<array<string,mixed>>}
 */
function sales_settings_sample_document_context(bool $isTruck): array
{
    $today = date('Y-m-d');
    $prefix = $isTruck ? 'QT-TRK' : 'QT-SPR';

    return [
        'invoice' => [
            'invoice_number' => $prefix . '-PREVIEW',
            'order_number' => $prefix . '-PREVIEW',
            'invoice_date' => $today,
            'due_date' => date('Y-m-d', strtotime($today . ' +14 days')),
            'quote_date' => $today,
            'status' => 'sent',
            'company_name' => 'Sample Customer Ltd',
            'tin' => '100-200-300',
            'vrn' => '40-000000-A',
            'salesperson' => 'Sales Team',
            'subtotal' => 1250000.0,
            'tax_amount' => 225000.0,
            'total_amount' => 1475000.0,
            'balance_due' => 1475000.0,
            'currency' => 'TZS',
        ],
        'items' => [
            [
                'product_name' => $isTruck ? 'Isuzu FTR Truck Chassis' : 'Brake Pad Set (Front)',
                'product_code' => 'DEMO-001',
                'description' => 'Preview line item for layout settings.',
                'quantity' => 1,
                'unit_price' => 1250000.0,
                'line_total' => 1250000.0,
                'tax_amount' => 225000.0,
            ],
        ],
    ];
}

function sales_settings_render_layout_preview_html(bool $isTruck, array $companySettings, ?string $layoutPath, bool $fullView = false): string
{
    $viewportClass = $fullView ? 'ss-layout-preview-viewport ss-layout-preview-viewport--full' : 'ss-layout-preview-viewport';
    $scaleClass = $fullView ? 'ss-layout-preview-scale ss-layout-preview-scale--full' : 'ss-layout-preview-scale';
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layout preview</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
        }
        .ss-layout-preview-viewport {
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 12px;
            box-sizing: border-box;
        }
        .ss-layout-preview-viewport--full {
            justify-content: flex-start;
            padding: 24px;
        }
        .ss-layout-preview-scale {
            transform: scale(0.48);
            transform-origin: top center;
            width: 210mm;
        }
        .ss-layout-preview-scale--full {
            transform: none;
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
        }
        .ss-layout-preview-error {
            margin: 2rem auto;
            max-width: 420px;
            padding: 1rem 1.25rem;
            border: 1px solid #fecaca;
            border-radius: 12px;
            background: #fef2f2;
            color: #991b1b;
            font-family: Inter, sans-serif;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
<div class="<?= htmlspecialchars($viewportClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($layoutPath === null || !is_file($layoutPath)): ?>
        <div class="ss-layout-preview-error">No branded document layout is available for this company.</div>
    <?php else: ?>
        <?php
        $sample = sales_settings_sample_document_context($isTruck);
        $invoice = $sample['invoice'];
        $items = $sample['items'];
        $company_settings = $companySettings;
        $currency = $company_settings['default_currency'] ?? 'TZS';
        $is_print = false;
        ?>
        <div class="<?= htmlspecialchars($scaleClass, ENT_QUOTES, 'UTF-8') ?>">
            <?php include $layoutPath; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
    <?php

    return (string) ob_get_clean();
}

/**
 * @return array<string, mixed>
 */
function sales_settings_init_data(): array
{
    settingsDeskBootstrap();

    global $pdo;

    $companyId = (int) (currentCompanyId() ?? 0);
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
    $settings = sales_settings_fetch($pdo, $companyId);
    $companyName = trim((string) ($settings['company_name'] ?? ''));

    $imagesBase = function_exists('sales_app_url')
        ? sales_app_url('assets/images/')
        : '/assets/images/';

    $fontCatalog = [];
    if (function_exists('getSystemFontCatalog')) {
        foreach (getSystemFontCatalog() as $key => $def) {
            $fontCatalog[] = [
                'key' => (string) $key,
                'label' => (string) ($def['label'] ?? $key),
                'stack' => (string) ($def['stack'] ?? ''),
                'google' => (string) ($def['google'] ?? ''),
                'localCss' => !empty($def['local_css']) && function_exists('app_url')
                    ? app_url($def['local_css'])
                    : (string) ($def['local_css'] ?? ''),
            ];
        }
    }

    return [
        'settings' => $settings,
        'module' => $module,
        'company_name' => $companyName,
        'is_roadmaster' => function_exists('isRoadmaster') && isRoadmaster(),
        'is_ultimate' => function_exists('isUltimate') && isUltimate(),
        'fontCatalog' => $fontCatalog,
        'layouts' => sales_settings_layout_catalog(),
        'urls' => [
            'dashboard' => sales_module_url('dashboard/index.php', ['module' => $module]),
            'save' => sales_module_url('settings/api_settings.php', ['module' => $module]),
            'layoutPreview' => sales_module_url('settings/api/layout-preview.php', ['module' => $module]),
        ],
        'assets' => [
            'imagesBase' => $imagesBase,
        ],
    ];
}

function salesSettingsRenderReactShell(): void
{
    $assets = settingsDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Sales Settings</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Sales Settings</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales/settings/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Sales Settings';
    $employeeHeaderTitle = 'Sales Settings';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $cfg = [
        'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'sales',
    ];

    $settingsHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__SALES_SETTINGS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_SETTINGS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';</script>';

    require dirname(__FILE__) . '/settings-react-shell.php';
    exit;
}
