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
        $exists = false;
        try {
            $pdo->query('SELECT 1 FROM sales_settings LIMIT 1');
            $exists = true;
        } catch (Throwable $e) {
            $exists = false;
        }

        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS sales_settings (
                    id INT NOT NULL AUTO_INCREMENT,
                    company_name VARCHAR(200) DEFAULT NULL,
                    company_address TEXT DEFAULT NULL,
                    company_logo VARCHAR(255) DEFAULT NULL,
                    company_phone VARCHAR(50) DEFAULT NULL,
                    company_email VARCHAR(100) DEFAULT NULL,
                    company_website VARCHAR(100) DEFAULT NULL,
                    company_tin VARCHAR(50) DEFAULT NULL,
                    company_vat VARCHAR(50) DEFAULT NULL,
                    bank_details TEXT DEFAULT NULL,
                    default_currency VARCHAR(10) DEFAULT 'TZS',
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    company_id INT DEFAULT 0,
                    spare_part_layout INT DEFAULT 1,
                    truck_layout INT DEFAULT 1,
                    truck_footer TEXT DEFAULT NULL,
                    spare_footer TEXT DEFAULT NULL,
                    truck_payment_details TEXT DEFAULT NULL,
                    truck_terms TEXT DEFAULT NULL,
                    truck_validity TEXT DEFAULT NULL,
                    truck_thanks_note TEXT DEFAULT NULL,
                    truck_return_policy TEXT DEFAULT NULL,
                    spare_payment_details TEXT DEFAULT NULL,
                    spare_terms TEXT DEFAULT NULL,
                    spare_validity TEXT DEFAULT NULL,
                    spare_thanks_note TEXT DEFAULT NULL,
                    spare_return_policy TEXT DEFAULT NULL,
                    document_footer_message TEXT DEFAULT NULL,
                    invoice_remarks TEXT DEFAULT NULL,
                    truck_remarks TEXT DEFAULT NULL,
                    enable_tax_inclusive TINYINT(1) DEFAULT 0,
                    enable_tax_exclusive TINYINT(1) DEFAULT 1,
                    sales_document_font VARCHAR(64) DEFAULT 'arima',
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

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
        if (!in_array('invoice_remarks', $columns, true)) {
            $missing[] = 'ADD COLUMN invoice_remarks TEXT NULL';
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
        // Schema sync is best-effort.
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
    $liveName = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_name', '')) : '';
    $liveAddress = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_address', '')) : '';
    $livePhone = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_phone', '')) : '';
    $liveEmail = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_email', '')) : '';
    $liveWebsite = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_website', '')) : '';
    $liveTin = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_tin', '')) : '';
    $liveVat = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_vat', '')) : '';
    $liveLogo = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_logo', '')) : '';

    return [
        'company_name' => $liveName,
        'company_address' => $liveAddress,
        'company_logo' => $liveLogo !== '' ? $liveLogo : '',
        'company_phone' => $livePhone,
        'company_email' => $liveEmail,
        'company_website' => $liveWebsite,
        'company_tin' => $liveTin,
        'company_vat' => $liveVat,
        'bank_details' => '',
        'default_currency' => 'TZS',
        'invoice_remarks' => '',
        'document_footer_message' => '',
        'spare_part_layout' => 1,
        'truck_layout' => 1,
        'truck_payment_details' => '',
        'truck_terms' => '',
        'truck_validity' => '',
        'truck_thanks_note' => '',
        'truck_return_policy' => '',
        'spare_payment_details' => '',
        'spare_terms' => '',
        'spare_validity' => '',
        'spare_thanks_note' => '',
        'spare_return_policy' => '',
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
 * True when a payment-details field is empty or still the default placeholder.
 */
function sales_settings_is_blank_payment_text($value): bool
{
    $text = trim((string) $value);
    if ($text === '') {
        return true;
    }

    return strcasecmp($text, 'Payment details') === 0;
}

/**
 * Overlay Admin → Company Settings → Finance onto sales_settings for the UI / documents.
 * Payment Details on Document Layout auto-fills from company bank_details when blank.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function sales_settings_merge_company_finance(array $settings): array
{
    if (!function_exists('getCompanySetting')) {
        return $settings;
    }

    $bank = trim((string) getCompanySetting('bank_details', ''));
    $footer = trim((string) getCompanySetting('document_footer_message', ''));
    $tin = trim((string) getCompanySetting('company_tin', ''));
    $vat = trim((string) getCompanySetting('company_vat', ''));
    $location = trim((string) getCompanySetting('company_location', ''));
    $taxMode = strtolower(trim((string) getCompanySetting('tax_calculation_mode', '')));

    // Always prefer live company identity for the active tenant (avoids Ultimate defaults on Roadmaster).
    foreach ([
        'company_name' => 'company_name',
        'company_address' => 'company_address',
        'company_phone' => 'company_phone',
        'company_email' => 'company_email',
        'company_website' => 'company_website',
        'company_logo' => 'company_logo',
    ] as $settingKey => $field) {
        $live = trim((string) getCompanySetting($settingKey, ''));
        if ($live !== '') {
            $settings[$field] = $live;
        }
    }

    $currentName = trim((string) ($settings['company_name'] ?? ''));
    $isUltimateTenant = function_exists('isUltimate') && isUltimate();
    if (!$isUltimateTenant && preg_match('/ultimate\s+general\s+trading/i', $currentName)) {
        $liveName = trim((string) getCompanySetting('company_name', ''));
        if ($liveName !== '') {
            $settings['company_name'] = $liveName;
        } elseif (function_exists('isRoadmaster') && isRoadmaster()) {
            $settings['company_name'] = 'ROADMASTER SPARES LIMITED';
        } else {
            $settings['company_name'] = '';
        }
    }

    if (function_exists('getCompanyLogoUrl')) {
        $logoUrl = trim((string) getCompanyLogoUrl());
        if ($logoUrl !== '') {
            $settings['company_logo_url'] = $logoUrl;
        }
    }

    if ($bank !== '') {
        $settings['bank_details'] = $bank;
        if (sales_settings_is_blank_payment_text($settings['spare_payment_details'] ?? null)) {
            $settings['spare_payment_details'] = $bank;
        }
        if (sales_settings_is_blank_payment_text($settings['truck_payment_details'] ?? null)) {
            $settings['truck_payment_details'] = $bank;
        }
    }

    if ($footer !== '' && trim((string) ($settings['document_footer_message'] ?? '')) === '') {
        $settings['document_footer_message'] = $footer;
    }
    if ($tin !== '' && trim((string) ($settings['company_tin'] ?? '')) === '') {
        $settings['company_tin'] = $tin;
    }
    if ($vat !== '' && trim((string) ($settings['company_vat'] ?? '')) === '') {
        $settings['company_vat'] = $vat;
    }
    if ($location !== '' && trim((string) ($settings['company_location'] ?? '')) === '') {
        $settings['company_location'] = $location;
    }

    if ($taxMode === 'inclusive') {
        $settings['enable_tax_inclusive'] = 1;
        $settings['enable_tax_exclusive'] = 0;
    } elseif ($taxMode === 'exclusive') {
        $settings['enable_tax_inclusive'] = 0;
        $settings['enable_tax_exclusive'] = 1;
    }

    if (function_exists('getCurrentCompany')) {
        $company = getCurrentCompany();
        $currency = trim((string) ($company['base_currency'] ?? ''));
        if ($currency !== '' && trim((string) ($settings['default_currency'] ?? '')) === '') {
            $settings['default_currency'] = $currency;
        }
    }

    return $settings;
}

/**
 * Persist company finance payment/footer into sales_settings so Document Layout stays in sync.
 */
function sales_settings_sync_from_company_finance(PDO $pdo, int $companyId, string $bankDetails, string $documentFooter = ''): void
{
    if ($companyId <= 0) {
        return;
    }

    try {
        sales_settings_sync_schema($pdo);
        $stmt = $pdo->prepare('SELECT id, bank_details, spare_payment_details, truck_payment_details, document_footer_message FROM sales_settings WHERE company_id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $bankDetails = trim($bankDetails);
        $documentFooter = trim($documentFooter);
        $sets = [];
        $params = [];

        if ($bankDetails !== '') {
            $sets[] = 'bank_details = ?';
            $params[] = $bankDetails;
            if (sales_settings_is_blank_payment_text($row['spare_payment_details'] ?? null)) {
                $sets[] = 'spare_payment_details = ?';
                $params[] = $bankDetails;
            }
            if (sales_settings_is_blank_payment_text($row['truck_payment_details'] ?? null)) {
                $sets[] = 'truck_payment_details = ?';
                $params[] = $bankDetails;
            }
        }

        if ($documentFooter !== '' && trim((string) ($row['document_footer_message'] ?? '')) === '') {
            $sets[] = 'document_footer_message = ?';
            $params[] = $documentFooter;
        }

        if ($sets === []) {
            return;
        }

        $params[] = $companyId;
        $pdo->prepare('UPDATE sales_settings SET ' . implode(', ', $sets) . ' WHERE company_id = ?')->execute($params);
    } catch (Throwable $e) {
        // Non-fatal: sales UI can still merge live via getCompanySetting.
    }
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
        $settings = sales_settings_default_row();
    }

    return sales_settings_merge_company_finance($settings);
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
function sales_settings_library_layout_catalog_entries(): array
{
    return [
        [
            'id' => 11,
            'label' => 'Purple Classic',
            'description' => 'Bold purple header table, TOTAL DUE bar, three-column footer.',
            'comingSoon' => false,
        ],
        [
            'id' => 12,
            'label' => 'Purple Brand Name',
            'description' => 'Purple pills, rounded TOTAL bar, and a black-and-purple footer wave.',
            'comingSoon' => false,
        ],
        [
            'id' => 13,
            'label' => 'Navy & Orange Swoosh',
            'description' => 'Pre-made frame artwork with corner swooshes; details overlaid on top.',
            'comingSoon' => false,
        ],
        [
            'id' => 14,
            'label' => 'Pixel Mart Red & Black',
            'description' => 'Vector frame with black title band and red angles; full overlaid invoice.',
            'comingSoon' => false,
        ],
        [
            'id' => 15,
            'label' => 'Yellow Bar Classic',
            'description' => 'Yellow-bar artwork with INVOICE, dark table header, and yellow total.',
            'comingSoon' => false,
        ],
    ];
}

function sales_settings_layout_catalog(): array
{
    $library = sales_settings_library_layout_catalog_entries();

    return [
        'truck' => array_merge(
            [
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
                    'comingSoon' => false,
                ],
                [
                    'id' => 3,
                    'label' => 'Minimalist',
                    'description' => 'Clean truck layout with simplified header styling.',
                    'comingSoon' => false,
                ],
            ],
            $library
        ),
        'spare' => array_merge(
            [
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
            $library
        ),
        'ultimate' => array_merge(
            [
                [
                    'id' => 1,
                    'label' => 'Standard',
                    'description' => 'Classic Ultimate invoice layout used on quotations and invoices.',
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
                    'description' => 'Teal header with white typography and clear grid lines.',
                    'comingSoon' => false,
                ],
                [
                    'id' => 4,
                    'label' => 'Formal',
                    'description' => 'Soft header band with refined ruled product table.',
                    'comingSoon' => false,
                ],
            ],
            $library
        ),
    ];
}

/**
 * Demo footer copy used in layout previews when company settings are empty.
 *
 * @return array<string, string>
 */
function sales_settings_sample_footer_fields(bool $isTruck): array
{
    $payment = "Bank: CRDB Bank\nAccount name: Sample Trading Ltd\nAccount no: 0150123456789\nSwift: CORUTZTZ";
    $terms = 'Goods remain the property of the seller until paid in full. Please settle within the stated validity period.';
    $validity = 'Valid for 14 days from issue date.';
    $thanks = $isTruck
        ? 'Thank you for choosing our trucks and services.'
        : 'Thank you for your business.';
    $returns = 'Returns accepted within 7 days for unused items in original packaging.';

    return [
        'bank_details' => $payment,
        'document_footer_message' => 'This is a computer-generated document preview.',
        'spare_payment_details' => $payment,
        'spare_terms' => $terms,
        'spare_validity' => $validity,
        'spare_thanks_note' => $thanks,
        'spare_return_policy' => $returns,
        'truck_payment_details' => $payment,
        'truck_terms' => $terms,
        'truck_validity' => $validity,
        'truck_thanks_note' => $thanks,
        'truck_return_policy' => $returns,
    ];
}

/**
 * @param array<string, mixed> $companySettings
 * @return array<string, mixed>
 */
function sales_settings_with_preview_footer_defaults(array $companySettings, bool $isTruck): array
{
    $samples = sales_settings_sample_footer_fields($isTruck);
    foreach ($samples as $key => $value) {
        $current = trim((string) ($companySettings[$key] ?? ''));
        if ($current === '') {
            $companySettings[$key] = $value;
        }
    }

    if (trim((string) ($companySettings['company_address'] ?? '')) === '') {
        $companySettings['company_address'] = "Plot 12, Industrial Area\nDar es Salaam, Tanzania";
    }
    if (trim((string) ($companySettings['company_phone'] ?? '')) === '') {
        $companySettings['company_phone'] = '+255 700 000 000';
    }
    if (trim((string) ($companySettings['company_email'] ?? '')) === '') {
        $companySettings['company_email'] = 'sales@example.com';
    }

    return $companySettings;
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
            'subtotal' => 1420000.0,
            'tax_amount' => 255600.0,
            'total_amount' => 1675600.0,
            'balance_due' => 1675600.0,
            'currency' => 'TZS',
            'notes' => 'Please include the invoice number on your payment reference.',
            'remarks' => 'Delivery within 3–5 working days after confirmation.',
        ],
        'items' => [
            [
                'product_name' => $isTruck ? 'Isuzu FTR Truck Chassis' : 'Brake Pad Set',
                'product_code' => 'DEMO-001',
                'description' => 'Front axle set',
                'quantity' => 1,
                'unit_price' => 1250000.0,
                'line_total' => 1250000.0,
                'tax_amount' => 225000.0,
            ],
            [
                'product_name' => $isTruck ? 'Service Kit' : 'Oil Filter',
                'product_code' => 'DEMO-002',
                'description' => 'Standard spare',
                'quantity' => 2,
                'unit_price' => 85000.0,
                'line_total' => 170000.0,
                'tax_amount' => 30600.0,
            ],
        ],
    ];
}

function sales_settings_render_layout_preview_html(bool $isTruck, array $companySettings, ?string $layoutPath, bool $fullView = false, bool $cardView = false, bool $modalView = false): string
{
    $viewportClass = 'ss-layout-preview-viewport';
    $scaleClass = 'ss-layout-preview-scale';
    $embedClass = '';
    if ($fullView) {
        $viewportClass .= ' ss-layout-preview-viewport--full';
        $scaleClass .= ' ss-layout-preview-scale--full';
    } elseif ($modalView) {
        $viewportClass .= ' ss-layout-preview-viewport--modal';
        $scaleClass .= ' ss-layout-preview-scale--modal';
        $embedClass = 'ss-preview-embed ss-preview-modal';
    } elseif ($cardView) {
        $viewportClass .= ' ss-layout-preview-viewport--card';
        $scaleClass .= ' ss-layout-preview-scale--card';
        $embedClass = 'ss-preview-embed ss-preview-card';
    }
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en" class="<?= htmlspecialchars($embedClass, ENT_QUOTES, 'UTF-8') ?>">
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
        html.ss-preview-embed,
        body.ss-preview-embed {
            width: 100%;
            height: 100%;
            max-height: 100%;
            overflow: hidden !important;
            background: #fff;
            scrollbar-width: none;
        }
        html.ss-preview-embed::-webkit-scrollbar,
        body.ss-preview-embed::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        .ss-layout-preview-viewport {
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: flex-start;
            padding: 8px;
            box-sizing: border-box;
            overflow: hidden;
            background: #f1f5f9;
        }
        .ss-layout-preview-viewport--full {
            justify-content: center;
            padding: 24px;
            overflow: auto;
        }
        .ss-layout-preview-viewport--card,
        .ss-layout-preview-viewport--modal {
            position: absolute;
            inset: 0;
            min-height: 0;
            height: 100%;
            padding: 8px;
            background: #fff;
            overflow: hidden !important;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            box-sizing: border-box;
        }
        .ss-layout-preview-viewport--modal {
            padding: 10px;
            background: #f8fafc;
            align-items: center;
            justify-content: center;
        }
        .ss-layout-preview-scale {
            /* Keep origin top-left so the panel iframe does not clip to empty side gutters. */
            transform: scale(0.38);
            transform-origin: top left;
            width: 210mm;
            margin: 0;
        }
        .ss-layout-preview-scale--full {
            transform: none;
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
        }
        .ss-layout-preview-scale--card {
            width: 210mm;
            /* zoom shrinks layout box so flex centering works inside the card */
            zoom: 0.24;
            transform: none;
            flex-shrink: 0;
        }
        .ss-layout-preview-scale--modal {
            width: 210mm;
            zoom: 1;
            transform: none;
            transform-origin: top center;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            flex-shrink: 0;
        }
        @supports not (zoom: 1) {
            .ss-layout-preview-viewport--card,
            .ss-layout-preview-viewport--modal {
                justify-content: center;
            }
            .ss-layout-preview-scale--card {
                zoom: unset;
                transform: scale(0.24);
                transform-origin: top center;
                margin-left: auto;
                margin-right: auto;
            }
            .ss-layout-preview-scale--modal {
                zoom: unset;
                transform: none;
                transform-origin: top center;
            }
        }

        /* Standard sheet styles (normally provided by invoice view pages). */
        .sheet-container {
            width: 210mm;
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            color: #111;
            box-sizing: border-box;
            padding: 12mm 12mm 10mm;
            overflow: hidden;
        }
        .sheet-title { margin: 0; font-size: 18pt; color: #1E272E; }
        .notebook { width: 100%; overflow: hidden; }
        .o-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 16px;
        }
        .o-table th {
            background-color: #ffcc00;
            color: #1E272E;
            font-weight: 600;
            font-size: 8.5pt;
            padding: 8px 6px;
            border-bottom: 2px solid #000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        .o-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 9pt;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .o-table th:nth-child(1),
        .o-table td:nth-child(1) { width: 58px !important; }
        .o-table th:nth-child(2),
        .o-table td:nth-child(2) { width: 18% !important; }
        .o-table th:nth-child(3),
        .o-table td:nth-child(3) { width: auto !important; }
        .o-table th:nth-child(4),
        .o-table td:nth-child(4) { width: 52px !important; text-align: center; }
        .o-table th:nth-child(5),
        .o-table td:nth-child(5),
        .o-table th:nth-child(6),
        .o-table td:nth-child(6) {
            width: 96px !important;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .o-totals-table {
            width: 280px;
            border-collapse: collapse;
            margin-left: auto;
        }
        .o-totals-table td {
            padding: 5px 0;
            font-size: 10pt;
        }
        .o-totals-table td:last-child {
            text-align: right;
            font-weight: 700;
            padding-left: 16px;
            white-space: nowrap;
        }
        .o-totals-grand td,
        .o-totals-due td {
            font-weight: 800;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
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
<body class="<?= htmlspecialchars($embedClass, ENT_QUOTES, 'UTF-8') ?>">
<div class="<?= htmlspecialchars($viewportClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($layoutPath === null || !is_file($layoutPath)): ?>
        <div class="ss-layout-preview-error">No branded document layout is available for this company.</div>
    <?php else: ?>
        <?php
        $sample = sales_settings_sample_document_context($isTruck);
        $invoice = $sample['invoice'];
        $items = $sample['items'];
        $company_settings = sales_settings_with_preview_footer_defaults(
            sales_settings_merge_company_finance($companySettings),
            $isTruck
        );
        $currency = $company_settings['default_currency'] ?? 'TZS';
        $is_print = false;
        $isTruckOrder = $isTruck;
        $document_footer_closing = true;
        $logoUrl = trim((string) ($company_settings['company_logo_url'] ?? ''));
        if ($logoUrl === '' && function_exists('getCompanyLogoUrl')) {
            $logoUrl = trim((string) getCompanyLogoUrl());
        }
        // Never show another tenant's / placeholder Ultimate logo on non-Ultimate previews.
        if (
            $logoUrl !== ''
            && function_exists('isUltimate')
            && !isUltimate()
            && preg_match('/ultimate|ugt[_-]?logo|untitled\.jpg/i', $logoUrl)
        ) {
            $logoUrl = '';
        }
        $signatureUrl = '';
        if (function_exists('sales_is_library_document_layout_id')) {
            $previewLayoutId = (int) ($isTruck
                ? ($company_settings['truck_layout'] ?? 0)
                : ($company_settings['spare_part_layout'] ?? 0));
            if (function_exists('isUltimate') && isUltimate()) {
                $previewLayoutId = (int) ($company_settings['spare_part_layout'] ?? 0);
            }
            if (sales_is_library_document_layout_id($previewLayoutId)) {
                $libraryLayoutId = $previewLayoutId;
            }
        }
        ?>
        <div class="<?= htmlspecialchars($scaleClass, ENT_QUOTES, 'UTF-8') ?>" id="ss-layout-preview-scale">
            <?php include $layoutPath; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($modalView): ?>
<script>
(function () {
  function fitModalPreview() {
    var viewport = document.querySelector('.ss-layout-preview-viewport--modal');
    var sheet = document.getElementById('ss-layout-preview-scale');
    if (!viewport || !sheet) return;

    sheet.style.zoom = '1';
    sheet.style.transform = 'none';
    sheet.style.width = '210mm';

    var pad = 16;
    var availW = Math.max(120, viewport.clientWidth - pad);
    var availH = Math.max(120, viewport.clientHeight - pad);
    var naturalW = sheet.scrollWidth || sheet.offsetWidth || 1;
    var naturalH = sheet.scrollHeight || sheet.offsetHeight || 1;
    var scale = Math.min(availW / naturalW, availH / naturalH);
    if (!isFinite(scale) || scale <= 0) scale = 1;
    // Fill the pane as much as possible while keeping the full page visible.
    scale = Math.min(scale, 1.15);

    if (typeof sheet.style.zoom !== 'undefined') {
      sheet.style.zoom = String(scale);
      sheet.style.transform = 'none';
    } else {
      sheet.style.zoom = '';
      sheet.style.transform = 'scale(' + scale + ')';
      sheet.style.transformOrigin = 'top center';
    }
  }

  window.addEventListener('load', fitModalPreview);
  window.addEventListener('resize', fitModalPreview);
  setTimeout(fitModalPreview, 50);
  setTimeout(fitModalPreview, 250);
})();
</script>
<?php endif; ?>
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
        'monthlyTargets' => sales_settings_monthly_targets($pdo, date('Y-m')),
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
            'docPreviewSample' => function_exists('sales_app_url')
                ? sales_app_url('/modules/sales/settings/assets/doc-preview-sample.png')
                : (function_exists('app_url')
                    ? app_url('/modules/sales/settings/assets/doc-preview-sample.png')
                    : '/modules/sales/settings/assets/doc-preview-sample.png'),
        ],
    ];
}

function sales_settings_amount_input(float $amount): string
{
    if ($amount <= 0) {
        return '';
    }
    $text = number_format($amount, 2, '.', '');

    return rtrim(rtrim($text, '0'), '.');
}

/**
 * @return array<int,int>
 */
function sales_settings_sales_people(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT id, full_name, username, department, role FROM users WHERE is_active = 1 ORDER BY full_name ASC, username ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $people = [];
    foreach ($rows as $row) {
        $blob = strtolower(trim((string) ($row['department'] ?? '') . ' ' . (string) ($row['role'] ?? '')));
        if (!str_contains($blob, 'sales')) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $name = trim((string) ($row['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['username'] ?? ''));
        }
        $people[] = ['id' => $id, 'name' => $name !== '' ? $name : ('User ' . $id)];
    }

    return $people;
}

function sales_settings_valid_month(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

/**
 * @return array{month:string,people:array<int,array<string,mixed>>}
 */
function sales_settings_monthly_targets(PDO $pdo, string $month): array
{
    if (!sales_settings_valid_month($month)) {
        $month = date('Y-m');
    }
    $people = sales_settings_sales_people($pdo);
    $amounts = [];
    if (function_exists('ensureSalesTargetsSchema')) {
        ensureSalesTargetsSchema();
    }
    $sharedAmount = 0.0;
    $ongoingShared = 0.0;
    $sharedYearly = 0.0;
    $loaded = false;
    try {
            $year = substr($month, 0, 4);
            $ids = array_column($people, 'id');
            $ids[] = 0;
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT user_id, period, target_amount FROM sales_targets WHERE user_id IN ($placeholders) AND period IN (?, ?, 'monthly')");
            $stmt->execute(array_merge($ids, [$month, $year]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $amounts[(int) $row['user_id']][(string) $row['period']] = (float) $row['target_amount'];
            }
            $sharedAmount = (float) ($amounts[0][$month] ?? 0);
            $ongoingShared = (float) ($amounts[0]['monthly'] ?? 0);
            $sharedYearly = (float) ($amounts[0][$year] ?? 0);
            $loaded = true;
    } catch (Throwable $e) {
        $amounts = [];
        $ongoingShared = 0.0;
        $sharedYearly = 0.0;
    }
    $year = substr($month, 0, 4);
    $out = [];
    foreach ($people as $person) {
        $id = (int) $person['id'];
        $monthly = (float) ($amounts[$id][$month] ?? 0);
        $ongoing = (float) ($amounts[$id]['monthly'] ?? 0);
        $yearly = (float) ($amounts[$id][$year] ?? 0);
        $out[] = [
            'id' => $id,
            'name' => $person['name'],
            'amount' => sales_settings_amount_input($monthly),
            'ongoing_amount' => sales_settings_amount_input($ongoing),
            'yearly_amount' => sales_settings_amount_input($yearly),
            'yearly_monthly' => $yearly > 0 ? round($yearly / 12, 2) : null,
        ];
    }

    if ($loaded) {
        sales_settings_sync_missing_target_notice($pdo, $month, $amounts, $people);
    }

    return [
        'month' => $month,
        'mode' => 'all',
        'shared_amount' => sales_settings_amount_input($sharedAmount),
        'ongoing_shared' => sales_settings_amount_input($ongoingShared),
        'shared_yearly' => sales_settings_amount_input($sharedYearly),
        'people' => $out,
    ];
}

/**
 * Salespeople who have no monthly, standing, or yearly amount for this month.
 *
 * @param array<int,array<string,float>> $amounts
 * @param array<int,array{id:int,name:string}> $people
 * @return array<int,string>
 */
function sales_settings_sales_target_missing(string $month, array $amounts, array $people): array
{
    $sharedMonth = (float) ($amounts[0][$month] ?? 0);
    $sharedOngoing = (float) ($amounts[0]['monthly'] ?? 0);
    $missing = [];
    foreach ($people as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $own = $amounts[$id] ?? [];
        $set = $sharedMonth > 0
            || $sharedOngoing > 0
            || (float) ($own[$month] ?? 0) > 0
            || (float) ($own['monthly'] ?? 0) > 0;
        if (!$set) {
            $missing[] = (string) ($person['name'] ?? ('User ' . $id));
        }
    }

    return $missing;
}

/**
 * True when an admin still needs to enter a monthly sales target.
 */
function sales_settings_sales_target_needs_entry(PDO $pdo): bool
{
    $isAdminUser = (function_exists('isAdmin') && isAdmin())
        || (($_SESSION['username'] ?? '') === 'admin');
    if (!$isAdminUser) {
        return false;
    }
    try {
        $state = sales_settings_monthly_targets($pdo, date('Y-m'));
    } catch (Throwable $e) {
        return false;
    }
    $people = is_array($state['people'] ?? null) ? $state['people'] : [];
    if ($people === []) {
        return false;
    }
    if ((float) ($state['shared_amount'] ?? 0) > 0 || (float) ($state['ongoing_shared'] ?? 0) > 0) {
        return false;
    }
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        if ((float) ($person['amount'] ?? 0) > 0 || (float) ($person['ongoing_amount'] ?? 0) > 0) {
            continue;
        }
        return true;
    }

    return false;
}

/**
 * @param array<int,array<string,float>> $amounts
 * @param array<int,array{id:int,name:string}> $people
 */
function sales_settings_sync_missing_target_notice(PDO $pdo, string $month, array $amounts, array $people): void
{
    if (!function_exists('createNotification') || !function_exists('ensureNotificationsSchema')) {
        return;
    }
    if ($people === []) {
        return;
    }
    try {
        ensureNotificationsSchema();
        $title = 'Set the sales target';
        $missing = sales_settings_sales_target_missing($month, $amounts, $people);
        if ($missing === []) {
            $clear = $pdo->prepare("DELETE FROM notifications WHERE audience = 'admin' AND title = ?");
            $clear->execute([$title]);

            return;
        }
        $existing = $pdo->prepare("SELECT id, is_read FROM notifications WHERE audience = 'admin' AND title = ? ORDER BY id DESC");
        $existing->execute([$title]);
        $rows = $existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $row = $rows[0] ?? null;
        if (count($rows) > 1) {
            $dropIds = [];
            foreach (array_slice($rows, 1) as $extra) {
                $extraId = (int) ($extra['id'] ?? 0);
                if ($extraId > 0) {
                    $dropIds[] = $extraId;
                }
            }
            if ($dropIds !== []) {
                $placeholders = implode(',', array_fill(0, count($dropIds), '?'));
                $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders)")->execute($dropIds);
                try {
                    $pdo->prepare("DELETE FROM user_notification_dismissals WHERE source = 'core' AND notification_id IN ($placeholders)")->execute($dropIds);
                } catch (Throwable $e) {
                }
            }
        }
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $noticeId = (int) $row['id'];
            $dismissed = false;
            try {
                $gone = $pdo->prepare("SELECT 1 FROM user_notification_dismissals WHERE source = 'core' AND notification_id = ? LIMIT 1");
                $gone->execute([$noticeId]);
                $dismissed = (bool) $gone->fetchColumn();
            } catch (Throwable $e) {
                $dismissed = false;
            }
            if ((int) ($row['is_read'] ?? 0) === 1 || $dismissed) {
                $pdo->prepare('UPDATE notifications SET is_read = 0, created_at = NOW() WHERE id = ?')->execute([$noticeId]);
                try {
                    $pdo->prepare('UPDATE notifications SET updated_at = NOW() WHERE id = ?')->execute([$noticeId]);
                } catch (Throwable $e) {
                }
                try {
                    $pdo->prepare("DELETE FROM user_notification_dismissals WHERE source = 'core' AND notification_id = ?")->execute([$noticeId]);
                } catch (Throwable $e) {
                }
            }

            return;
        }
        $shown = array_slice($missing, 0, 5);
        $who = count($missing) === count($people)
            ? 'each salesperson'
            : implode(', ', $shown) . (count($missing) > 5 ? ', and others' : '');
        createNotification([
            'user_id' => null,
            'audience' => 'admin',
            'title' => $title,
            'message' => 'The sales target is not set for ' . $who . '. Enter the amount in Sales target.',
            'type' => 'warning',
        ]);
    } catch (Throwable $e) {
    }
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function sales_settings_parse_amount(string $raw): float
{
    $clean = str_replace([',', ' '], '', trim($raw));

    return is_numeric($clean) ? (float) $clean : 0.0;
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function sales_settings_notify_targets(PDO $pdo, string $month, string $mode, string $scope, float $sharedAmount, float $sharedYearly, array $rows): void
{
    if (!function_exists('createNotification')) {
        return;
    }
    $hasPersonal = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (sales_settings_parse_amount((string) ($row['amount'] ?? '')) > 0
            || sales_settings_parse_amount((string) ($row['yearly_amount'] ?? '')) > 0) {
            $hasPersonal = true;
            break;
        }
    }
    if ($sharedAmount <= 0 && $sharedYearly <= 0 && !$hasPersonal) {
        return;
    }
    $money = static function (float $amount): string {
        return 'TZS ' . number_format($amount, 0, '.', ',');
    };
    if ($scope === 'ongoing') {
        $when = 'every month until it is changed';
    } else {
        $stamp = strtotime($month . '-01');
        $when = $stamp ? date('F Y', $stamp) : $month;
    }
    $title = 'Sales target updated';
    if ($mode !== 'each' && $sharedAmount > 0) {
        $message = 'The monthly sales target is ' . $money($sharedAmount) . ' for every salesperson, for ' . $when . '.';
        if ($sharedYearly > 0) {
            $message .= ' The yearly amount is ' . $money($sharedYearly) . '.';
        }
    } elseif ($sharedYearly > 0 && $sharedAmount <= 0) {
        $message = 'The yearly sales target is ' . $money($sharedYearly) . ' for every salesperson.';
    } else {
        $message = 'Sales targets were updated for ' . $when . '.';
    }
    try {
        createNotification([
            'user_id' => null,
            'audience' => 'all',
            'title' => $title,
            'message' => $message,
            'type' => 'info',
        ]);
    } catch (Throwable $e) {
    }
}

function sales_settings_save_monthly_targets(PDO $pdo, string $month, array $rows, string $mode = 'all', string $sharedRaw = '', string $scope = 'month', string $sharedYearlyRaw = ''): array
{
    if (!sales_settings_valid_month($month)) {
        throw new InvalidArgumentException('Choose a valid month.');
    }
    if ($scope === 'ongoing') {
        $period = 'monthly';
    } elseif ($scope === 'year') {
        $period = substr($month, 0, 4);
    } else {
        $period = $month;
    }
    $year = substr($month, 0, 4);
    if (function_exists('ensureSalesTargetsSchema')) {
        ensureSalesTargetsSchema();
    }
    $allowed = [];
    foreach (sales_settings_sales_people($pdo) as $person) {
        $allowed[(int) $person['id']] = true;
    }
    $upsert = $pdo->prepare('INSERT INTO sales_targets (user_id, period, target_amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE target_amount = ?');
    $delete = $pdo->prepare('DELETE FROM sales_targets WHERE user_id = ? AND period = ?');
    $sharedAmount = sales_settings_parse_amount($sharedRaw);
    $sharedYearly = sales_settings_parse_amount($sharedYearlyRaw);
    if ($sharedAmount > 0) {
        $upsert->execute([0, $period, $sharedAmount, $sharedAmount]);
    } elseif ($mode === 'all') {
        $delete->execute([0, $period]);
    }
    if ($sharedYearly > 0) {
        $upsert->execute([0, $year, $sharedYearly, $sharedYearly]);
    }
    if ($mode === 'all') {
        foreach (array_keys($allowed) as $userId) {
            $delete->execute([(int) $userId, $period]);
        }

        sales_settings_notify_targets($pdo, $month, $mode, $scope, $sharedAmount, $sharedYearly, $rows);

        return sales_settings_monthly_targets($pdo, $month);
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int) ($row['user_id'] ?? $row['id'] ?? 0);
        if ($userId < 1 || empty($allowed[$userId])) {
            continue;
        }
        $amount = sales_settings_parse_amount((string) ($row['amount'] ?? ''));
        if ($amount > 0) {
            $upsert->execute([$userId, $period, $amount, $amount]);
        } else {
            $delete->execute([$userId, $period]);
        }
        $yearly = sales_settings_parse_amount((string) ($row['yearly_amount'] ?? ''));
        if ($yearly > 0) {
            $upsert->execute([$userId, $year, $yearly, $yearly]);
        }
    }

    sales_settings_notify_targets($pdo, $month, $mode, $scope, $sharedAmount, $sharedYearly, $rows);

    return sales_settings_monthly_targets($pdo, $month);
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
    $employeeHeaderTitle = '';
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
