<?php

declare(strict_types=1);

function customerCatalogueDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 4) . '/includes/config.php';
        require_once dirname(__DIR__, 4) . '/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/functions.php';
        $booted = true;
    }
}

function customerCatalogueDeskRequireAccess(): void
{
    customerCatalogueDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function customerCatalogueModuleQuery(): string
{
    $module = strtolower(trim((string) ($_GET['module'] ?? 'sales')));

    return $module !== '' ? $module : 'sales';
}

function customerCatalogueWebBase(): string
{
    return customersDeskWebBase();
}

function customersDeskWebBase(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    if (function_exists('sales_module_url')) {
        $customersUrl = sales_module_url('customers/index.php');
        return rtrim(preg_replace('#/index\.php$#', '', $customersUrl), '/');
    }

    return function_exists('app_url')
        ? app_url('/modules/sales/customers')
        : '/modules/sales/customers';
}

function customersDeskParseCustomerId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

function customersDeskParsePage(array $query = [], int $default = 1): int
{
    $page = isset($query['page']) ? (int) $query['page'] : $default;

    return max(1, $page);
}

function customersDeskParseSearch(array $query = []): string
{
    return trim((string) ($query['search'] ?? ''));
}

/**
 * @return array<string, mixed>
 */
function customerIndexInitData(): array
{
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $search = customersDeskParseSearch($_GET);
    $showCreatedToast = (($_GET['msg'] ?? '') === 'created');

    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;

    $where = 'WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $where .= ' AND (company_name LIKE ? OR customer_code LIKE ? OR contact_person LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('customers') : ['', []];
    if (!empty($scope[0])) {
        $where .= $scope[0];
        $params = array_merge($params, $scope[1]);
    }

    $countStmt = $salesDb->prepare("SELECT COUNT(*) FROM customers $where");
    $countStmt->execute($params);
    $totalRecords = (int) $countStmt->fetchColumn();

    $listStmt = $salesDb->prepare("
        SELECT *
        FROM customers
        $where
        ORDER BY company_name ASC
    ");
    $listStmt->execute($params);
    $customers = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'module' => $module,
        'search' => $search,
        'total_records' => $totalRecords,
        'show_created_toast' => $showCreatedToast,
        'customers' => array_map(static function ($customer) {
            return [
                'id' => (int) ($customer['id'] ?? 0),
                'customer_code' => (string) ($customer['customer_code'] ?? ''),
                'company_name' => (string) ($customer['company_name'] ?? ''),
                'contact_person' => (string) ($customer['contact_person'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? ''),
                'customer_type' => (string) ($customer['customer_type'] ?? ''),
            ];
        }, $customers),
        'urls' => [
            'index' => sales_module_url('customers/index.php', ['module' => $module]),
            'add' => sales_module_url('customers/add.php', ['module' => $module]),
            'edit' => sales_module_url('customers/edit.php', ['module' => $module]),
            'view' => sales_module_url('customers/view.php', ['module' => $module]),
            'crm' => function_exists('company_url')
                ? company_url('modules/crm/my-clients/index') . '?module=crm'
                : (function_exists('app_url') ? app_url('/modules/crm/my-clients/index') . '?module=crm' : '/modules/crm/my-clients/index.php?module=crm'),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function customerViewInitData(): array
{
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $customerId = customersDeskParseCustomerId($_GET);
    if ($customerId <= 0) {
        throw new RuntimeException('Customer id is required.');
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $recentOrders = [];
    try {
        $stmtOrders = $pdo->prepare('SELECT * FROM sales_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmtOrders->execute([$customerId]);
        $recentOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recentOrders = [];
    }

    $addressParts = array_filter([
        trim((string) ($customer['address'] ?? '')),
        trim((string) ($customer['city'] ?? '')),
        trim((string) ($customer['country'] ?? '')),
    ], static fn ($part) => $part !== '');

    return [
        'module' => $module,
        'customer' => [
            'id' => (int) ($customer['id'] ?? 0),
            'customer_code' => (string) ($customer['customer_code'] ?? ''),
            'company_name' => (string) ($customer['company_name'] ?? ''),
            'contact_person' => (string) ($customer['contact_person'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'address' => (string) ($customer['address'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'country' => (string) ($customer['country'] ?? ''),
            'address_line' => $addressParts !== [] ? implode(', ', $addressParts) : '',
            'customer_type' => (string) ($customer['customer_type'] ?? ''),
            'status' => (string) ($customer['status'] ?? ''),
            'currency' => (string) ($customer['currency'] ?? 'TZS'),
            'current_balance' => (float) ($customer['current_balance'] ?? 0),
            'credit_limit' => (float) ($customer['credit_limit'] ?? 0),
            'payment_terms' => (string) ($customer['payment_terms'] ?? ''),
            'tin' => (string) ($customer['tin'] ?? ''),
            'vrn' => (string) ($customer['vrn'] ?? ''),
        ],
        'recent_orders' => array_map(static function ($order) {
            return [
                'id' => (int) ($order['id'] ?? 0),
                'order_number' => (string) ($order['order_number'] ?? ''),
                'status' => (string) ($order['status'] ?? ''),
                'total_amount' => (float) ($order['total_amount'] ?? 0),
                'created_at' => (string) ($order['created_at'] ?? ''),
            ];
        }, $recentOrders),
        'urls' => [
            'customers_index' => sales_module_url('customers/index.php', ['module' => $module]),
            'edit' => sales_module_url('customers/edit.php', ['id' => $customerId, 'module' => $module]),
            'new_quote' => sales_module_url('orders/create.php', ['customer_id' => $customerId, 'mode' => 'new', 'module' => $module]),
            'order_view' => sales_module_url('orders/view.php', ['module' => $module]),
        ],
    ];
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function customersDeskLoadReactAssets(): ?array
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
    $base = customersDeskWebBase();
    $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
    $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
    $assetVersion = (string) max((int) $cssVersion, (int) $jsVersion, (int) filemtime($distIndex));

    return [
        'distHtml' => $distHtml,
        'assetBase' => $base . '/frontend/dist/assets/',
        'apiUrl' => $base . '/api',
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => $assetVersion,
        'jsVersion' => $assetVersion,
    ];
}

function customerCatalogueDeskLoadReactAssets(): ?array
{
    return customersDeskLoadReactAssets();
}

function customerCatalogueDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
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

function customersDeskShellHeadExtras(): string
{
    return customerCatalogueDeskShellHeadExtras();
}

/**
 * @param array<string, mixed> $cfg
 */
function customersDeskBuildHeadMarkup(array $assets, array $cfg, string $deskPage): string
{
    return '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__CUSTOMERS_DESK_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__CUSTOMERS_DESK_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__CUSTOMERS_DESK_PAGE__ = ' . json_encode($deskPage, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__CUSTOMER_CATALOGUE_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__CUSTOMER_CATALOGUE_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';</script>';
}

function customersDeskRenderReactShell(string $deskPage, string $pageTitle, string $bodyClass, string $noscriptMessage): void
{
    $assets = customersDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($pageTitle) . '</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>' . htmlspecialchars($pageTitle) . '</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales/customers/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = $pageTitle;
    $employeeHeaderTitle = $pageTitle;
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $customersDeskPage = $deskPage;
    $customersBodyClass = $bodyClass;
    $customersNoscriptMessage = $noscriptMessage;

    $cfg = [
        'module' => customerCatalogueModuleQuery(),
    ];
    if ($deskPage === 'view' || $deskPage === 'edit') {
        $customerId = customersDeskParseCustomerId($_GET);
        if ($customerId > 0) {
            $cfg['customer_id'] = $customerId;
        }
    }

    $customersHeadMarkup = customersDeskBuildHeadMarkup($assets, $cfg, $deskPage);

    require dirname(__FILE__) . '/customers-desk-react-shell.php';
    exit;
}

function customerViewRenderReactShell(): void
{
    customersDeskRenderReactShell(
        'view',
        'Customer profile',
        'page-customer-view-desk',
        'Enable JavaScript to view this customer profile.'
    );
}

function customerIndexRenderReactShell(): void
{
    customersDeskRenderReactShell(
        'index',
        'Customers',
        'page-customer-index-desk',
        'Enable JavaScript to manage customers.'
    );
}

function customersDeskSalesDb(): PDO
{
    global $pdo;

    return function_exists('sales_pdo') ? sales_pdo() : $pdo;
}

function customerAddGenerateNextCode(?PDO $salesDb = null): string
{
    $salesDb = $salesDb ?? customersDeskSalesDb();
    $currentYear = date('Y');
    $prefix = "CUST-$currentYear-";
    $nextNum = 1;

    try {
        $stmt = $salesDb->prepare('SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$prefix . '%']);
        $lastCode = $stmt->fetchColumn();

        if ($lastCode && preg_match('/\((\d+)\)$/', (string) $lastCode, $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }

        $attempts = 0;
        while ($attempts < 5000) {
            $candidate = $prefix . '(' . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT) . ')';
            $checkStmt = $salesDb->prepare('SELECT COUNT(*) FROM customers WHERE customer_code = ?');
            $checkStmt->execute([$candidate]);
            if ((int) $checkStmt->fetchColumn() === 0) {
                return $candidate;
            }
            $nextNum++;
            $attempts++;
        }
    } catch (Throwable $e) {
        error_log('customerAddGenerateNextCode: ' . $e->getMessage());
    }

    return $prefix . '(001)';
}

/**
 * @return array<string, list<string>>
 */
function customerDeskCitiesByCountry(): array
{
    return [
        'Tanzania' => [
            'Arusha',
            'Bagamoyo',
            'Bukoba',
            'Dar es Salaam',
            'Dodoma',
            'Geita',
            'Iringa',
            'Kigoma',
            'Kilimanjaro',
            'Lindi',
            'Mbeya',
            'Morogoro',
            'Moshi',
            'Mtwara',
            'Musoma',
            'Mwanza',
            'Njombe',
            'Pwani',
            'Rukwa',
            'Ruvuma',
            'Shinyanga',
            'Singida',
            'Songea',
            'Sumbawanga',
            'Tabora',
            'Tanga',
            'Zanzibar',
        ],
        'Kenya' => [
            'Nairobi',
            'Mombasa',
            'Kisumu',
            'Nakuru',
            'Eldoret',
            'Thika',
            'Malindi',
            'Other',
        ],
        'Uganda' => [
            'Kampala',
            'Entebbe',
            'Jinja',
            'Gulu',
            'Mbarara',
            'Other',
        ],
        'Rwanda' => ['Kigali', 'Butare', 'Gisenyi', 'Other'],
        'Burundi' => ['Bujumbura', 'Gitega', 'Other'],
        'South Sudan' => ['Juba', 'Wau', 'Other'],
        'Ethiopia' => ['Addis Ababa', 'Dire Dawa', 'Mekelle', 'Other'],
        'Somalia' => ['Mogadishu', 'Hargeisa', 'Other'],
        'Mozambique' => ['Maputo', 'Beira', 'Nampula', 'Other'],
        'Malawi' => ['Lilongwe', 'Blantyre', 'Mzuzu', 'Other'],
        'Zambia' => ['Lusaka', 'Ndola', 'Kitwe', 'Other'],
        'Zimbabwe' => ['Harare', 'Bulawayo', 'Other'],
        'South Africa' => ['Johannesburg', 'Cape Town', 'Durban', 'Pretoria', 'Other'],
        'Democratic Republic of the Congo' => ['Kinshasa', 'Lubumbashi', 'Goma', 'Other'],
        'United Arab Emirates' => ['Dubai', 'Abu Dhabi', 'Sharjah', 'Other'],
        'India' => ['Mumbai', 'Delhi', 'Bangalore', 'Chennai', 'Other'],
        'China' => ['Beijing', 'Shanghai', 'Guangzhou', 'Shenzhen', 'Other'],
        'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Other'],
        'United States' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Other'],
        'Germany' => ['Berlin', 'Munich', 'Frankfurt', 'Hamburg', 'Other'],
        'France' => ['Paris', 'Lyon', 'Marseille', 'Other'],
        'Netherlands' => ['Amsterdam', 'Rotterdam', 'The Hague', 'Other'],
        'Belgium' => ['Brussels', 'Antwerp', 'Ghent', 'Other'],
        'Canada' => ['Toronto', 'Vancouver', 'Montreal', 'Other'],
        'Australia' => ['Sydney', 'Melbourne', 'Brisbane', 'Other'],
        'Other' => ['Other'],
    ];
}

/**
 * @return list<string>
 */
function customerDeskCountryList(): array
{
    return array_keys(customerDeskCitiesByCountry());
}

/**
 * @return array<string, string>
 */
function customerDeskCountryFlagMap(): array
{
    return [
        'Tanzania' => 'tz',
        'Kenya' => 'ke',
        'Uganda' => 'ug',
        'Rwanda' => 'rw',
        'Burundi' => 'bi',
        'South Sudan' => 'ss',
        'Ethiopia' => 'et',
        'Somalia' => 'so',
        'Mozambique' => 'mz',
        'Malawi' => 'mw',
        'Zambia' => 'zm',
        'Zimbabwe' => 'zw',
        'South Africa' => 'za',
        'Democratic Republic of the Congo' => 'cd',
        'United Arab Emirates' => 'ae',
        'India' => 'in',
        'China' => 'cn',
        'United Kingdom' => 'gb',
        'United States' => 'us',
        'Germany' => 'de',
        'France' => 'fr',
        'Netherlands' => 'nl',
        'Belgium' => 'be',
        'Canada' => 'ca',
        'Australia' => 'au',
        'Other' => 'un',
    ];
}

/**
 * @return list<array{value:string,label:string,flag:string}>
 */
function customerDeskCountryOptions(): array
{
    $flags = customerDeskCountryFlagMap();

    return array_map(static function (string $country) use ($flags) {
        return [
            'value' => $country,
            'label' => $country,
            'flag' => $flags[$country] ?? 'un',
        ];
    }, customerDeskCountryList());
}

/**
 * @param list<string> $values
 * @return list<array{value:string,label:string}>
 */
function customerDeskSelectOptions(array $values): array
{
    return array_map(static fn ($value) => [
        'value' => $value,
        'label' => $value,
    ], $values);
}

/**
 * @return list<string>
 */
function customerDeskPaymentTermList(): array
{
    return [
        'Immediate',
        'Net 7',
        'Net 10',
        'Net 15',
        'Net 21',
        'Net 30',
        'Net 45',
        'Net 60',
        'Net 90',
        'Net 120',
    ];
}

/**
 * @return list<array{value:string,label:string}>
 */
function customerDeskPaymentTermOptions(): array
{
    return customerDeskSelectOptions(customerDeskPaymentTermList());
}

function customerDeskIsAllowedPaymentTerm(string $paymentTerms): bool
{
    return in_array($paymentTerms, customerDeskPaymentTermList(), true);
}

/**
 * @return list<array{value:string,label:string,name:string,flag:string}>
 */
function customerDeskCurrencyOptions(): array
{
    $options = sales_invoice_currency_options();
    $result = [];

    foreach ($options as $code => $meta) {
        $result[] = [
            'value' => $code,
            'label' => $code,
            'name' => (string) ($meta['name'] ?? $code),
            'flag' => (string) ($meta['flag'] ?? 'un'),
        ];
    }

    return $result;
}

function customerDeskIsAllowedCurrency(string $currency): bool
{
    $code = strtoupper(trim($currency));

    return $code !== '' && array_key_exists($code, sales_invoice_currency_options());
}

function customerDeskIsAllowedCityForCountry(string $city, string $country): bool
{
    $map = customerDeskCitiesByCountry();
    if (!isset($map[$country])) {
        return false;
    }

    return in_array($city, $map[$country], true);
}

function customerDeskIsAllowedCountry(string $country): bool
{
    return array_key_exists($country, customerDeskCitiesByCountry());
}

/**
 * @return array<string, string>
 */
function customerAddDefaultForm(): array
{
    return [
        'customer_code' => '',
        'company_name' => '',
        'contact_person' => '',
        'email' => '',
        'phone' => '',
        'tin' => '',
        'vrn' => '',
        'address' => '',
        'city' => '',
        'country' => '',
        'customer_type' => 'retail',
        'status' => 'lead',
        'payment_terms' => 'Net 30',
        'currency' => 'TZS',
        'credit_limit' => '0.00',
        'source' => '',
        'notes' => '',
    ];
}

/**
 * @return array<string, mixed>
 */
function customerDeskFormOptions(): array
{
    return [
        'customer_types' => [
            ['value' => 'retail', 'label' => 'Retail'],
            ['value' => 'wholesale', 'label' => 'Wholesale'],
            ['value' => 'corporate', 'label' => 'Corporate'],
            ['value' => 'government', 'label' => 'Government'],
        ],
        'payment_terms' => customerDeskPaymentTermOptions(),
        'currencies' => customerDeskCurrencyOptions(),
        'countries' => customerDeskCountryOptions(),
        'cities_by_country' => array_map(
            static fn (array $cities) => customerDeskSelectOptions($cities),
            customerDeskCitiesByCountry()
        ),
    ];
}

/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $form
 * @return array<string, mixed>
 */
function customerDeskAugmentFormOptions(array $options, array $form): array
{
    $paymentValues = array_column($options['payment_terms'] ?? [], 'value');
    $paymentTerm = trim((string) ($form['payment_terms'] ?? ''));
    if ($paymentTerm !== '' && !in_array($paymentTerm, $paymentValues, true)) {
        $options['payment_terms'][] = [
            'value' => $paymentTerm,
            'label' => $paymentTerm,
        ];
    }

    $currencyValues = array_column($options['currencies'] ?? [], 'value');
    $currency = strtoupper(trim((string) ($form['currency'] ?? '')));
    if ($currency !== '' && !in_array($currency, $currencyValues, true)) {
        $options['currencies'][] = [
            'value' => $currency,
            'label' => $currency,
            'name' => $currency,
            'flag' => 'un',
        ];
    }

    $countryValues = array_column($options['countries'] ?? [], 'value');
    $country = trim((string) ($form['country'] ?? ''));
    if ($country !== '' && !in_array($country, $countryValues, true)) {
        $flags = customerDeskCountryFlagMap();
        $options['countries'][] = [
            'value' => $country,
            'label' => $country,
            'flag' => $flags[$country] ?? 'un',
        ];
    }

    $city = trim((string) ($form['city'] ?? ''));
    if ($country !== '' && $city !== '') {
        $cities = $options['cities_by_country'][$country] ?? [];
        $cityValues = array_column($cities, 'value');
        if (!in_array($city, $cityValues, true)) {
            $options['cities_by_country'][$country][] = [
                'value' => $city,
                'label' => $city,
            ];
        }
    }

    return $options;
}

/**
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function customerEditFormFromCustomer(array $customer): array
{
    return [
        'id' => (int) ($customer['id'] ?? 0),
        'customer_code' => (string) ($customer['customer_code'] ?? ''),
        'company_name' => (string) ($customer['company_name'] ?? ''),
        'contact_person' => (string) ($customer['contact_person'] ?? ''),
        'email' => (string) ($customer['email'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'tin' => (string) ($customer['tin'] ?? ''),
        'vrn' => (string) ($customer['vrn'] ?? ''),
        'address' => (string) ($customer['address'] ?? ''),
        'city' => (string) ($customer['city'] ?? ''),
        'country' => (string) ($customer['country'] ?? ''),
        'customer_type' => (string) ($customer['customer_type'] ?? 'retail'),
        'payment_terms' => (string) ($customer['payment_terms'] ?? 'Net 30'),
        'currency' => (string) ($customer['currency'] ?? 'TZS'),
        'credit_limit' => number_format((float) ($customer['credit_limit'] ?? 0), 2, '.', ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function customerEditInitData(): array
{
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $customerId = customersDeskParseCustomerId($_GET);
    if ($customerId <= 0) {
        throw new RuntimeException('Customer id is required.');
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $defaults = customerEditFormFromCustomer($customer);
    $options = customerDeskAugmentFormOptions(customerDeskFormOptions(), $defaults);

    return [
        'module' => $module,
        'customer_id' => $customerId,
        'defaults' => $defaults,
        'options' => $options,
        'urls' => [
            'index' => sales_module_url('customers/index.php', ['module' => $module]),
            'view' => sales_module_url('customers/view.php', ['id' => $customerId, 'module' => $module]),
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function customerEditUpdateFromInput(array $input): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $salesDb = customersDeskSalesDb();
    $customerId = (int) ($input['id'] ?? 0);
    if ($customerId <= 0) {
        return ['error' => 'Customer id is required.'];
    }

    $stmtExisting = $salesDb->prepare('SELECT * FROM customers WHERE id = ?');
    $stmtExisting->execute([$customerId]);
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return ['error' => 'Customer not found.'];
    }

    $companyName = trim((string) ($input['company_name'] ?? ''));
    $contactPerson = trim((string) ($input['contact_person'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $address = trim((string) ($input['address'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $country = trim((string) ($input['country'] ?? ''));
    $tin = trim((string) ($input['tin'] ?? ''));
    $vrn = trim((string) ($input['vrn'] ?? ''));
    $customerType = trim((string) ($input['customer_type'] ?? 'retail'));
    $paymentTerms = trim((string) ($input['payment_terms'] ?? 'Net 30'));
    $currency = trim((string) ($input['currency'] ?? 'TZS'));
    $creditLimit = (float) ($input['credit_limit'] ?? 0);

    if (
        $companyName === '' || $contactPerson === '' || $email === ''
        || $phone === '' || $address === '' || $city === '' || $country === ''
    ) {
        return ['error' => 'All fields are required (Address, City, Country).'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Invalid email format.'];
    }

    $existingCity = trim((string) ($existing['city'] ?? ''));
    $existingCountry = trim((string) ($existing['country'] ?? ''));
    if (!customerDeskIsAllowedCityForCountry($city, $country)
        && !($city === $existingCity && $country === $existingCountry)) {
        return ['error' => 'Please select a valid city for the chosen country.'];
    }

    if (!customerDeskIsAllowedCountry($country)
        && $country !== $existingCountry) {
        return ['error' => 'Please select a valid country.'];
    }

    if (!customerDeskIsAllowedPaymentTerm($paymentTerms)
        && $paymentTerms !== trim((string) ($existing['payment_terms'] ?? ''))) {
        return ['error' => 'Please select a valid payment term.'];
    }

    if (!customerDeskIsAllowedCurrency($currency)
        && strtoupper($currency) !== strtoupper(trim((string) ($existing['currency'] ?? '')))) {
        return ['error' => 'Please select a valid currency.'];
    }

    try {
        ensureCustomerColumnsExist();

        $stmt = $salesDb->prepare('
            UPDATE customers SET
                company_name = ?, contact_person = ?, email = ?, phone = ?,
                address = ?, city = ?, country = ?, tax_number = ?,
                tin = ?, vrn = ?, customer_type = ?, payment_terms = ?,
                currency = ?, credit_limit = ?
            WHERE id = ?
        ');

        $stmt->execute([
            $companyName,
            $contactPerson,
            $email,
            $phone,
            $address,
            $city,
            $country,
            $tin . ($vrn !== '' ? " / $vrn" : ''),
            $tin !== '' ? $tin : null,
            $vrn !== '' ? $vrn : null,
            $customerType,
            $paymentTerms,
            $currency,
            $creditLimit,
            $customerId,
        ]);

        return [
            'ok' => true,
            'redirect_url' => sales_module_url('customers/view.php', [
                'id' => $customerId,
                'status' => 'updated',
                'module' => $module,
            ]),
        ];
    } catch (Throwable $e) {
        return ['error' => 'Error updating customer: ' . $e->getMessage()];
    }
}

function customerEditRenderReactShell(): void
{
    customersDeskRenderReactShell(
        'edit',
        'Edit customer',
        'page-customer-edit-desk page-customer-add-desk',
        'Enable JavaScript to edit this customer.'
    );
}

/**
 * @return array<string, mixed>
 */
function customerAddInitData(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $salesDb = customersDeskSalesDb();
    $nextCode = customerAddGenerateNextCode($salesDb);
    $defaults = customerAddDefaultForm();
    $defaults['customer_code'] = $nextCode;
    require_once dirname(__DIR__, 3) . '/crm/includes/crm-sales-bridge.php';

    return [
        'module' => $module,
        'defaults' => $defaults,
        'next_customer_code' => $nextCode,
        'options' => customerDeskFormOptions(),
        'statuses' => crmSalesBridgeContactStatuses(),
        'urls' => [
            'index' => sales_module_url('customers/index.php', ['module' => $module]),
            'crm' => function_exists('company_url')
                ? company_url('modules/crm/my-clients/index') . '?module=crm'
                : (function_exists('app_url') ? app_url('/modules/crm/my-clients/index') . '?module=crm' : '/modules/crm/my-clients/index.php?module=crm'),
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function customerAddCreateFromInput(array $input): array
{
    if (array_key_exists('name', $input)) {
        require_once dirname(__DIR__, 3) . '/crm/includes/crm-sales-bridge.php';

        return crmSalesBridgeCreateCustomerFromSalesForm($input);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'sales';

    $module = customerCatalogueModuleQuery();
    $salesDb = customersDeskSalesDb();

    $companyName = trim((string) ($input['company_name'] ?? ''));
    $contactPerson = trim((string) ($input['contact_person'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $address = trim((string) ($input['address'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $country = trim((string) ($input['country'] ?? ''));
    $tin = trim((string) ($input['tin'] ?? ''));
    $vrn = trim((string) ($input['vrn'] ?? ''));
    $customerType = trim((string) ($input['customer_type'] ?? 'retail'));
    $status = trim((string) ($input['status'] ?? 'lead'));
    $source = trim((string) ($input['source'] ?? ''));
    $paymentTerms = trim((string) ($input['payment_terms'] ?? 'Net 30'));
    $currency = trim((string) ($input['currency'] ?? 'TZS'));
    $notes = trim((string) ($input['notes'] ?? ''));
    $creditLimit = (float) ($input['credit_limit'] ?? 0);

    if (
        $companyName === '' || $contactPerson === '' || $email === ''
        || $phone === '' || $address === '' || $city === '' || $country === ''
        || $source === ''
    ) {
        return ['error' => 'All required fields must be filled (including Source).'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Invalid email format.'];
    }

    if (!customerDeskIsAllowedCityForCountry($city, $country)) {
        return ['error' => 'Please select a valid city for the chosen country.'];
    }

    if (!customerDeskIsAllowedCountry($country)) {
        return ['error' => 'Please select a valid country.'];
    }

    if (!customerDeskIsAllowedPaymentTerm($paymentTerms)) {
        return ['error' => 'Please select a valid payment term.'];
    }

    if (!customerDeskIsAllowedCurrency($currency)) {
        return ['error' => 'Please select a valid currency.'];
    }

    try {
        ensureCustomerColumnsExist();
        $customerCode = customerAddGenerateNextCode($salesDb);
        $notes = $notes !== '' ? ($notes . "\nSource: " . $source) : ('Source: ' . $source);

        $stmt = $salesDb->prepare('
            INSERT INTO customers (
                customer_code, company_name, contact_person, email, phone,
                address, city, country, tax_number, tin, vrn, customer_type,
                payment_terms, currency, credit_limit, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $customerCode,
            $companyName,
            $contactPerson,
            $email,
            $phone,
            $address,
            $city,
            $country,
            $tin . ($vrn !== '' ? " / $vrn" : ''),
            $tin,
            $vrn,
            $customerType,
            $paymentTerms,
            $currency,
            $creditLimit,
            $notes,
            $_SESSION['user_id'] ?? 1,
        ]);

        $customerId = (int) $salesDb->lastInsertId();

        try {
            require_once dirname(__DIR__, 3) . '/crm/includes/crm-sales-bridge.php';
            global $pdo;
            if ($pdo instanceof PDO) {
                $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
                $userId = (int) ($_SESSION['user_id'] ?? 0);
                if ($companyId > 0) {
                    crmSalesBridgeLinkExistingCustomer($pdo, $companyId, $userId, $customerId, [
                        'company_name' => $companyName,
                        'contact_person' => $contactPerson,
                        'email' => $email,
                        'phone' => $phone,
                        'notes' => $notes,
                        'status' => $status,
                        'source' => $source,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Customer saved; CRM link is best-effort.
        }

        return [
            'ok' => true,
            'customer_code' => $customerCode,
            'redirect_url' => sales_module_url('customers/index.php', [
                'msg' => 'created',
                'module' => $module,
            ]),
        ];
    } catch (Throwable $e) {
        return ['error' => 'Error adding customer: ' . $e->getMessage()];
    }
}

function customerAddRenderReactShell(): void
{
    customersDeskRenderReactShell(
        'add',
        'Add client',
        'page-customer-add-desk',
        'Enable JavaScript to add a customer.'
    );
}

/**
 * @return array{return_url:string,doc_type:string,doc_label:string,add_selected_label:string,multi_select:bool}
 */
function customerCatalogueParseContext(array $query = []): array
{
    $returnUrl = trim((string) ($query['return'] ?? ''));
    $docType = strtolower(trim((string) ($query['doc'] ?? 'quote')));
    $multiSelect = ($docType === 'statement');

    $docLabel = match ($docType) {
        'invoice' => 'Invoice',
        'statement' => 'Statement',
        'purchase' => 'Purchase Order',
        default => 'Quotation',
    };

    if ($returnUrl === '') {
        $returnUrl = $docType === 'invoice'
            ? sales_module_url('invoices/create.php')
            : sales_module_url('orders/create.php', ['mode' => 'new']);
    }

    if (strpos($returnUrl, '://') === false) {
        if ($returnUrl !== '' && $returnUrl[0] !== '/') {
            $returnUrl = '/' . $returnUrl;
        }
        $returnUrl = str_replace('/staff/', '/', $returnUrl);
        $base = defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') : '';
        if ($base !== '' && $returnUrl !== '' && strpos($returnUrl, $base . '/') !== 0 && $returnUrl !== $base) {
            $returnUrl = $base . $returnUrl;
        }
    }

    $addSelectedLabel = match ($docType) {
        'invoice' => 'invoice',
        'statement' => 'statement',
        'purchase' => 'purchase order',
        default => 'quotation',
    };

    return [
        'return_url' => $returnUrl,
        'doc_type' => $docType,
        'doc_label' => $docLabel,
        'add_selected_label' => $addSelectedLabel,
        'multi_select' => $multiSelect,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function customerCatalogueFetchCustomers(PDO $pdo): array
{
    $customers = [];
    $popularity = [];

    try {
        $customers = $pdo->query("
            SELECT id, customer_code, company_name, contact_person, email, phone, address, status
            FROM customers
            WHERE LOWER(TRIM(COALESCE(status, 'active'))) = 'active'
            ORDER BY company_name
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $popStmt = $pdo->query("
            SELECT customer_id, COUNT(id) AS invoice_count
            FROM invoices
            WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY customer_id
        ");
        foreach ($popStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $popularity[(int) $row['customer_id']] = (int) $row['invoice_count'];
        }
    } catch (Throwable $e) {
        return [];
    }

    foreach ($customers as &$custRow) {
        $cid = (int) ($custRow['id'] ?? 0);
        $custRow['invoice_count'] = (int) ($popularity[$cid] ?? 0);
    }
    unset($custRow);

    return $customers;
}

/**
 * @return array<string, mixed>
 */
function customerCatalogueInitData(): array
{
    global $pdo;
    $module = customerCatalogueModuleQuery();
    $context = customerCatalogueParseContext($_GET);
    $customers = customerCatalogueFetchCustomers($pdo);

    return [
        'module' => $module,
        'customers' => array_map(static function ($c) {
            return [
                'id' => (int) ($c['id'] ?? 0),
                'customer_code' => (string) ($c['customer_code'] ?? ''),
                'company_name' => (string) ($c['company_name'] ?? ''),
                'contact_person' => (string) ($c['contact_person'] ?? ''),
                'email' => (string) ($c['email'] ?? ''),
                'phone' => (string) ($c['phone'] ?? ''),
                'address' => (string) ($c['address'] ?? ''),
                'invoice_count' => (int) ($c['invoice_count'] ?? 0),
            ];
        }, $customers),
        'context' => $context,
        'urls' => [
            'return' => $context['return_url'],
            'customer_view' => sales_module_url('customers/view.php', ['module' => $module]),
        ],
    ];
}

function customerCatalogueRenderReactShell(): void
{
    customersDeskRenderReactShell(
        'catalogue',
        'Customer catalogue',
        'page-customer-catalogue-desk',
        'Enable JavaScript to browse the customer catalogue.'
    );
}
