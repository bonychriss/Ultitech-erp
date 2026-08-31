<?php

declare(strict_types=1);

function pricelistDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/config.php';
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once dirname(__DIR__) . '/functions.php';
        $booted = true;
    }
}

function pricelistDeskRequireAccess(): void
{
    pricelistDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
    $_SESSION['active_module'] = 'sales';
}

function pricelistDeskModuleQuery(): string
{
    $module = strtolower(trim((string) ($_GET['module'] ?? 'sales')));

    return $module !== '' ? $module : 'sales';
}

function pricelistDeskWebBase(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    return sales_app_url('modules/sales');
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function pricelistDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/pricelist/frontend';
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
    $base = pricelistDeskWebBase();
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
    $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
    $assetVersion = (string) max((int) $cssVersion, (int) $jsVersion, (int) filemtime($distIndex));

    return [
        'distHtml' => $distHtml,
        'assetBase' => $base . '/pricelist/frontend/dist/assets/',
        'apiUrl' => $base . '/pricelist/api',
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => $assetVersion,
        'jsVersion' => $assetVersion,
    ];
}

function pricelistDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

/**
 * @return array<string, mixed>
 */
function pricelistDeskDefaultCompanySettings(): array
{
    return [
        'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'Ultimate General Trading',
        'company_address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : 'Dar es Salaam, Tanzania',
        'company_logo' => 'Untitled.jpg',
        'default_currency' => 'TZS',
        'company_phone' => '',
        'company_email' => '',
        'company_tin' => '',
        'company_vat' => '',
        'bank_details' => '',
        'company_website' => '',
        'include_catalogue' => 0,
    ];
}

/**
 * @return array{products:list<array<string,mixed>>,meta:array{last_updated_iso:?string}}
 */
function pricelistDeskFetchProducts(PDO $pdo): array
{
    $products = [];
    $meta = ['last_updated_iso' => null];

    try {
        $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $productCols = is_array($productCols) ? $productCols : [];

        if (in_array('unit_price', $productCols, true)) {
            $priceExpr = 'p.unit_price AS selling_price';
        } elseif (in_array('selling_price', $productCols, true)) {
            $priceExpr = 'p.selling_price';
        } else {
            $priceExpr = '0 AS selling_price';
        }

        if (in_array('main_image', $productCols, true) && in_array('image', $productCols, true)) {
            $imgExpr = 'COALESCE(p.main_image, p.image) AS main_image';
        } elseif (in_array('main_image', $productCols, true)) {
            $imgExpr = 'p.main_image AS main_image';
        } elseif (in_array('image', $productCols, true)) {
            $imgExpr = 'p.image AS main_image';
        } else {
            $imgExpr = 'NULL AS main_image';
        }

        $joins = '';
        $extraSelect = '';
        if (in_array('category_id', $productCols, true)) {
            $joins .= ' LEFT JOIN categories cat ON p.category_id = cat.id ';
            $extraSelect .= ", COALESCE(cat.name, '') AS category_name";
        } else {
            $extraSelect .= ", '' AS category_name";
        }

        $brandJoined = false;
        if (in_array('brand_id', $productCols, true)) {
            try {
                $pdo->query('SELECT 1 FROM brands LIMIT 1');
                $joins .= ' LEFT JOIN brands br ON p.brand_id = br.id ';
                $extraSelect .= ", COALESCE(br.name, '') AS brand_name";
                $brandJoined = true;
            } catch (Throwable $e) {
                /* no brands table */
            }
        }
        if (!$brandJoined && in_array('brand', $productCols, true)) {
            $extraSelect .= ", COALESCE(p.brand, '') AS brand_name";
            $brandJoined = true;
        }
        if (!$brandJoined) {
            $extraSelect .= ", '' AS brand_name";
        }

        if (in_array('updated_at', $productCols, true)) {
            $extraSelect .= ', p.updated_at AS row_updated_at';
        } elseif (in_array('modified_at', $productCols, true)) {
            $extraSelect .= ', p.modified_at AS row_updated_at';
        } else {
            $extraSelect .= ', NULL AS row_updated_at';
        }

        if (in_array('is_published', $productCols, true)) {
            $extraSelect .= ', p.is_published AS row_is_published';
        } else {
            $extraSelect .= ', NULL AS row_is_published';
        }
        if (in_array('status', $productCols, true)) {
            $extraSelect .= ', p.status AS row_status';
        } else {
            $extraSelect .= ', NULL AS row_status';
        }

        $products = $pdo->query("
            SELECT p.id, p.product_code, p.name, p.description, $priceExpr, $imgExpr
                $extraSelect
            FROM products p
            $joins
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $latestTs = null;
        foreach ($products as &$row) {
            $ts = null;
            if (!empty($row['row_updated_at'])) {
                $ts = strtotime((string) $row['row_updated_at']);
            }
            if ($ts && ($latestTs === null || $ts > $latestTs)) {
                $latestTs = $ts;
            }

            $row['is_catalog_active'] = true;
            if (isset($row['row_is_published']) && $row['row_is_published'] !== null && $row['row_is_published'] !== '') {
                $row['is_catalog_active'] = (int) $row['row_is_published'] === 1;
            } elseif (!empty($row['row_status'])) {
                $st = strtolower(trim((string) $row['row_status']));
                $row['is_catalog_active'] = in_array($st, ['active', 'published', '1', 'yes', 'true'], true);
            }

            $productId = (int) ($row['id'] ?? 0);
            $mainImage = trim((string) ($row['main_image'] ?? ''));
            $row['image_url'] = sales_product_image_url($productId, $mainImage !== '' ? $mainImage : null, 'medium');
            $row['selling_price'] = (float) ($row['selling_price'] ?? 0);
            unset($row['row_updated_at'], $row['row_is_published'], $row['row_status'], $row['main_image']);
        }
        unset($row);

        if ($latestTs) {
            $meta['last_updated_iso'] = gmdate('c', $latestTs);
        }
    } catch (Throwable $e) {
        $products = [];
    }

    return ['products' => $products, 'meta' => $meta];
}

/**
 * @return array<string, mixed>
 */
function pricelistInitData(): array
{
    global $pdo;

    $module = pricelistDeskModuleQuery();

    try {
        $companySettings = $pdo->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $companySettings = false;
    }
    if (!$companySettings) {
        $companySettings = pricelistDeskDefaultCompanySettings();
    }

    $fetch = pricelistDeskFetchProducts($pdo);
    $currency = (string) ($companySettings['default_currency'] ?? 'TZS');
    $logoFile = (string) ($companySettings['company_logo'] ?? 'Untitled.jpg');
    $logoPath = function_exists('app_url')
        ? app_url('/assets/images/' . ltrim($logoFile, '/'))
        : '/assets/images/' . ltrim($logoFile, '/');

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $currentUser = ['full_name' => '', 'signature_path' => ''];
    if ($userId > 0) {
        $userStmt = $pdo->prepare('SELECT full_name, signature_path FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $row = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $currentUser = [
                'full_name' => (string) ($row['full_name'] ?? ''),
                'signature_path' => (string) ($row['signature_path'] ?? ''),
            ];
        }
    }

    if ($currentUser['signature_path'] !== '' && function_exists('app_url')) {
        $sig = ltrim(str_replace('\\', '/', $currentUser['signature_path']), '/');
        $currentUser['signature_url'] = app_url('/' . $sig);
    } else {
        $currentUser['signature_url'] = $currentUser['signature_path'] !== ''
            ? '/' . ltrim($currentUser['signature_path'], '/')
            : '';
    }

    return [
        'module' => $module,
        'products' => $fetch['products'],
        'company' => $companySettings,
        'currency' => $currency,
        'logo_url' => $logoPath,
        'current_user' => $currentUser,
        'meta' => $fetch['meta'],
        'urls' => [
            'dashboard' => sales_module_url('dashboard/index.php', ['module' => $module]),
            'settings' => sales_module_url('settings/index.php', ['module' => $module]),
        ],
    ];
}

function pricelistRenderReactShell(): void
{
    $assets = pricelistDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Price list</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Price list</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/pricelist/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Price list';
    $employeeHeaderTitle = 'Price list';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $cfg = [
        'module' => pricelistDeskModuleQuery(),
    ];

    $pricelistHeadMarkup = '<link rel="stylesheet" crossorigin href="'
        . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8')
        . '">'
        . "\n" . '<script>window.__PRICELIST_DESK_API_BASE__ = '
        . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES)
        . ';window.__PRICELIST_DESK_CFG__ = '
        . json_encode($cfg, JSON_UNESCAPED_SLASHES)
        . ';</script>';

    require dirname(__FILE__) . '/pricelist-react-shell.php';
    exit;
}
