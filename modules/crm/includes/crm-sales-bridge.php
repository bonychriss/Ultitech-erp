<?php

declare(strict_types=1);

function crmSalesBridgeLoadDeps(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once dirname(__DIR__, 3) . '/includes/functions.php';
    require_once dirname(__DIR__, 2) . '/sales/functions.php';
    require_once dirname(__DIR__, 2) . '/sales/customers/includes/catalogue-lib.php';
    require_once __DIR__ . '/crm-engine.php';
    $loaded = true;
}

/**
 * @return array{country:string,city:string,address:string}
 */
function crmSalesBridgeDefaultLocation(): array
{
    crmSalesBridgeLoadDeps();

    $country = 'Tanzania';
    $city = 'Dar es Salaam';
    $countries = customerDeskCountryOptions();
    if ($countries !== []) {
        $country = (string) ($countries[0]['value'] ?? $country);
    }
    $citiesByCountry = customerDeskCitiesByCountry();
    if (isset($citiesByCountry[$country][0])) {
        $city = (string) $citiesByCountry[$country][0];
    }

    return [
        'country' => $country,
        'city' => $city,
        'address' => '-',
    ];
}

function crmSalesBridgeIsFullCustomerForm(array $data): bool
{
    return array_key_exists('company_name', $data)
        || array_key_exists('contact_person', $data)
        || array_key_exists('address', $data);
}

/**
 * @return array<string, mixed>
 */
function crmSalesBridgeCustomerFormDefaults(): array
{
    crmSalesBridgeLoadDeps();

    $defaults = customerAddDefaultForm();
    unset($defaults['customer_code'], $defaults['notes']);

    return $defaults;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmSalesBridgeNormalizeCustomerInput(array $data): array
{
    crmSalesBridgeLoadDeps();

    $companyName = trim((string) ($data['company_name'] ?? ''));
    $contactPerson = trim((string) ($data['contact_person'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $country = trim((string) ($data['country'] ?? ''));
    $tin = trim((string) ($data['tin'] ?? ''));
    $vrn = trim((string) ($data['vrn'] ?? ''));
    $customerType = trim((string) ($data['customer_type'] ?? 'retail'));
    $paymentTerms = trim((string) ($data['payment_terms'] ?? 'Net 30'));
    $currency = trim((string) ($data['currency'] ?? 'TZS'));
    $creditLimit = (float) ($data['credit_limit'] ?? 0);
    $status = crmEngineNormalizeStatus((string) ($data['status'] ?? 'lead'));
    $source = trim((string) ($data['source'] ?? ''));

    if (
        $companyName === '' || $contactPerson === '' || $email === ''
        || $phone === '' || $address === '' || $city === '' || $country === ''
        || $source === ''
    ) {
        throw new InvalidArgumentException('All required fields must be filled (including Source).');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email format.');
    }

    if (!customerDeskIsAllowedCityForCountry($city, $country)) {
        throw new InvalidArgumentException('Please select a valid city for the chosen country.');
    }

    if (!customerDeskIsAllowedCountry($country)) {
        throw new InvalidArgumentException('Please select a valid country.');
    }

    if (!customerDeskIsAllowedPaymentTerm($paymentTerms)) {
        throw new InvalidArgumentException('Please select a valid payment term.');
    }

    if (!customerDeskIsAllowedCurrency($currency)) {
        throw new InvalidArgumentException('Please select a valid currency.');
    }

    return [
        'company_name' => $companyName,
        'contact_person' => $contactPerson,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'city' => $city,
        'country' => $country,
        'tin' => $tin,
        'vrn' => $vrn,
        'customer_type' => $customerType,
        'payment_terms' => $paymentTerms,
        'currency' => $currency,
        'credit_limit' => $creditLimit,
        'status' => $status,
        'source' => $source,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmSalesBridgeToCrmContactPayload(array $data): array
{
    if (crmSalesBridgeIsFullCustomerForm($data)) {
        $normalized = crmSalesBridgeNormalizeCustomerInput($data);

        return [
            'name' => $normalized['contact_person'],
            'organization' => $normalized['company_name'],
            'email' => $normalized['email'],
            'phone' => $normalized['phone'],
            'status' => $normalized['status'],
            'source' => $normalized['source'],
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
    }

    return crmSalesBridgeNormalizeContactInput($data);
}

/**
 * @param array<string, mixed> $contact
 * @return array<string, mixed>
 */
function crmSalesBridgeFormFromContact(array $contact): array
{
    crmSalesBridgeLoadDeps();

    $defaults = crmSalesBridgeCustomerFormDefaults();
    $customerId = (int) ($contact['customer_id'] ?? 0);

    if ($customerId > 0) {
        $salesDb = customersDeskSalesDb();
        $stmt = $salesDb->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($customer)) {
            return array_merge($defaults, [
                'company_name' => (string) ($customer['company_name'] ?? ''),
                'contact_person' => (string) ($customer['contact_person'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? ''),
                'address' => (string) ($customer['address'] ?? ''),
                'city' => (string) ($customer['city'] ?? ''),
                'country' => (string) ($customer['country'] ?? ''),
                'tin' => (string) ($customer['tin'] ?? ''),
                'vrn' => (string) ($customer['vrn'] ?? ''),
                'customer_type' => (string) ($customer['customer_type'] ?? 'retail'),
                'payment_terms' => (string) ($customer['payment_terms'] ?? 'Net 30'),
                'currency' => (string) ($customer['currency'] ?? 'TZS'),
                'credit_limit' => number_format((float) ($customer['credit_limit'] ?? 0), 2, '.', ''),
                'status' => crmEngineNormalizeStatus((string) ($contact['status'] ?? 'lead')),
                'source' => (string) ($contact['source'] ?? ''),
            ]);
        }
    }

    return array_merge($defaults, [
        'company_name' => (string) ($contact['organization'] ?? ''),
        'contact_person' => (string) ($contact['name'] ?? ''),
        'email' => (string) ($contact['email'] ?? ''),
        'phone' => (string) ($contact['phone'] ?? ''),
        'status' => crmEngineNormalizeStatus((string) ($contact['status'] ?? 'lead')),
        'source' => (string) ($contact['source'] ?? ''),
    ]);
}

/**
 * @param array<string, mixed> $data
 */
function crmSalesBridgeInsertFullCustomer(array $data, int $userId): int
{
    crmSalesBridgeLoadDeps();

    $normalized = crmSalesBridgeNormalizeCustomerInput($data);
    $salesDb = customersDeskSalesDb();
    ensureCustomerColumnsExist();

    $customerCode = customerAddGenerateNextCode($salesDb);
    $taxNumber = $normalized['tin'] . ($normalized['vrn'] !== '' ? ' / ' . $normalized['vrn'] : '');

    $stmt = $salesDb->prepare('
        INSERT INTO customers (
            customer_code, company_name, contact_person, email, phone,
            address, city, country, tax_number, tin, vrn, customer_type,
            payment_terms, currency, credit_limit, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $customerCode,
        $normalized['company_name'],
        $normalized['contact_person'],
        $normalized['email'],
        $normalized['phone'],
        $normalized['address'],
        $normalized['city'],
        $normalized['country'],
        $taxNumber,
        $normalized['tin'] !== '' ? $normalized['tin'] : null,
        $normalized['vrn'] !== '' ? $normalized['vrn'] : null,
        $normalized['customer_type'],
        $normalized['payment_terms'],
        $normalized['currency'],
        $normalized['credit_limit'],
        'Source: ' . $normalized['source'],
        $userId > 0 ? $userId : ($_SESSION['user_id'] ?? 1),
    ]);

    return (int) $salesDb->lastInsertId();
}

/**
 * @param array<string, mixed> $data
 */
function crmSalesBridgeUpdateFullCustomer(int $customerId, array $data, int $userId): void
{
    crmSalesBridgeLoadDeps();

    if ($customerId <= 0) {
        return;
    }

    $normalized = crmSalesBridgeNormalizeCustomerInput($data);
    $salesDb = customersDeskSalesDb();
    $taxNumber = $normalized['tin'] . ($normalized['vrn'] !== '' ? ' / ' . $normalized['vrn'] : '');

    $stmt = $salesDb->prepare('
        UPDATE customers
        SET company_name = ?, contact_person = ?, email = ?, phone = ?,
            address = ?, city = ?, country = ?, tax_number = ?, tin = ?, vrn = ?,
            customer_type = ?, payment_terms = ?, currency = ?, credit_limit = ?, notes = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $normalized['company_name'],
        $normalized['contact_person'],
        $normalized['email'],
        $normalized['phone'],
        $normalized['address'],
        $normalized['city'],
        $normalized['country'],
        $taxNumber,
        $normalized['tin'] !== '' ? $normalized['tin'] : null,
        $normalized['vrn'] !== '' ? $normalized['vrn'] : null,
        $normalized['customer_type'],
        $normalized['payment_terms'],
        $normalized['currency'],
        $normalized['credit_limit'],
        'Source: ' . $normalized['source'],
        $customerId,
    ]);
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmSalesBridgeNormalizeContactInput(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $organization = trim((string) ($data['organization'] ?? ''));

    return [
        'name' => $name,
        'organization' => $organization,
        'email' => trim((string) ($data['email'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
        'status' => crmEngineNormalizeStatus((string) ($data['status'] ?? 'lead')),
        'source' => trim((string) ($data['source'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
        'company_name' => $organization !== '' ? $organization : $name,
        'contact_person' => $name,
    ];
}

function crmSalesBridgeInsertCustomer(array $data, int $userId): int
{
    crmSalesBridgeLoadDeps();

    if (crmSalesBridgeIsFullCustomerForm($data)) {
        return crmSalesBridgeInsertFullCustomer($data, $userId);
    }

    $normalized = crmSalesBridgeNormalizeContactInput($data);
    if ($normalized['name'] === '') {
        throw new InvalidArgumentException('Contact name is required.');
    }

    $location = crmSalesBridgeDefaultLocation();
    $salesDb = customersDeskSalesDb();
    ensureCustomerColumnsExist();

    $email = $normalized['email'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email format.');
    }

    $customerCode = customerAddGenerateNextCode($salesDb);
    $notes = $normalized['notes'];
    if ($normalized['source'] !== '') {
        $sourceLine = 'Source: ' . $normalized['source'];
        $notes = $notes !== '' ? ($notes . "\n" . $sourceLine) : $sourceLine;
    }

    $stmt = $salesDb->prepare('
        INSERT INTO customers (
            customer_code, company_name, contact_person, email, phone,
            address, city, country, tax_number, tin, vrn, customer_type,
            payment_terms, currency, credit_limit, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $customerCode,
        $normalized['company_name'],
        $normalized['contact_person'],
        $email,
        $normalized['phone'],
        $location['address'],
        $location['city'],
        $location['country'],
        '',
        null,
        null,
        'retail',
        'Net 30',
        'TZS',
        0,
        $notes !== '' ? $notes : null,
        $userId > 0 ? $userId : ($_SESSION['user_id'] ?? 1),
    ]);

    return (int) $salesDb->lastInsertId();
}

function crmSalesBridgeUpdateCustomer(int $customerId, array $data, int $userId): void
{
    crmSalesBridgeLoadDeps();

    if ($customerId <= 0) {
        return;
    }

    if (crmSalesBridgeIsFullCustomerForm($data)) {
        crmSalesBridgeUpdateFullCustomer($customerId, $data, $userId);

        return;
    }

    $normalized = crmSalesBridgeNormalizeContactInput($data);
    if ($normalized['name'] === '') {
        throw new InvalidArgumentException('Contact name is required.');
    }

    $salesDb = customersDeskSalesDb();
    $email = $normalized['email'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email format.');
    }

    $notes = $normalized['notes'];
    if ($normalized['source'] !== '') {
        $sourceLine = 'Source: ' . $normalized['source'];
        $notes = $notes !== '' ? ($notes . "\n" . $sourceLine) : $sourceLine;
    }

    $stmt = $salesDb->prepare('
        UPDATE customers
        SET company_name = ?, contact_person = ?, email = ?, phone = ?, notes = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $normalized['company_name'],
        $normalized['contact_person'],
        $email,
        $normalized['phone'],
        $notes !== '' ? $notes : null,
        $customerId,
    ]);
}

/**
 * @param array<string, mixed> $data
 */
function crmSalesBridgeLinkExistingCustomer(PDO $pdo, int $companyId, int $userId, int $customerId, array $data): void
{
    crmSalesBridgeLoadDeps();
    crmEngineEnsureSchema($pdo);

    if ($customerId <= 0 || $companyId <= 0) {
        return;
    }

    $check = $pdo->prepare('SELECT id FROM crm_contacts WHERE company_id = ? AND customer_id = ? LIMIT 1');
    $check->execute([$companyId, $customerId]);
    if ($check->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $contactPerson = trim((string) ($data['contact_person'] ?? $data['name'] ?? ''));
    $companyName = trim((string) ($data['company_name'] ?? $data['organization'] ?? ''));
    if ($contactPerson === '') {
        return;
    }

    crmEngineCreateContact($pdo, $companyId, $userId, [
        'customer_id' => $customerId,
        'name' => $contactPerson,
        'organization' => $companyName !== '' ? $companyName : null,
        'email' => trim((string) ($data['email'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
        'status' => crmEngineNormalizeStatus((string) ($data['status'] ?? 'customer')),
        'source' => trim((string) ($data['source'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
    ]);
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmSalesBridgeCreateFromContactForm(PDO $pdo, int $companyId, int $userId, array $data): array
{
    crmSalesBridgeLoadDeps();
    crmEngineEnsureSchema($pdo);

    $customerId = crmSalesBridgeInsertCustomer($data, $userId);
    $crmPayload = crmSalesBridgeToCrmContactPayload($data);
    $contact = crmEngineCreateContact($pdo, $companyId, $userId, array_merge($crmPayload, [
        'customer_id' => $customerId,
    ]));

    return $contact;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmSalesBridgeUpdateFromContactForm(PDO $pdo, int $companyId, int $userId, int $contactId, array $data): array
{
    crmSalesBridgeLoadDeps();

    $existing = crmEngineGetContact($pdo, $companyId, $contactId);
    if ($existing === null) {
        throw new InvalidArgumentException('Contact not found.');
    }

    if ($userId > 0) {
        crmSalesBridgeAssertUserContactAccess($pdo, $existing, $userId);
    }

    $customerId = (int) ($existing['customer_id'] ?? 0);
    if ($customerId > 0) {
        crmSalesBridgeUpdateCustomer($customerId, $data, $userId);
    } else {
        $customerId = crmSalesBridgeInsertCustomer($data, $userId);
    }

    $crmPayload = crmSalesBridgeToCrmContactPayload($data);
    $crmPayload['customer_id'] = $customerId;

    return crmEngineUpdateContact($pdo, $companyId, $contactId, $crmPayload);
}

function crmSalesBridgeContactStatuses(): array
{
    return [
        ['value' => 'lead', 'label' => 'Lead'],
        ['value' => 'prospect', 'label' => 'Prospect'],
        ['value' => 'customer', 'label' => 'Client'],
        ['value' => 'inactive', 'label' => 'Inactive'],
    ];
}

function crmSalesBridgeContactDefaults(): array
{
    return [
        'name' => '',
        'organization' => '',
        'status' => 'lead',
        'email' => '',
        'phone' => '',
        'source' => '',
        'notes' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function crmSalesBridgeCreateCustomerFromSalesForm(array $input): array
{
    crmSalesBridgeLoadDeps();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        return ['error' => 'Database connection is not available.'];
    }

    $module = customerCatalogueModuleQuery();
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($companyId <= 0) {
        return ['error' => 'Company context is required.'];
    }

    try {
        crmSalesBridgeCreateFromContactForm($pdo, $companyId, $userId, $input);

        return [
            'ok' => true,
            'redirect_url' => sales_module_url('customers/index.php', [
                'msg' => 'created',
                'module' => $module,
            ]),
        ];
    } catch (InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    } catch (Throwable $e) {
        return ['error' => 'Error adding contact: ' . $e->getMessage()];
    }
}

function crmSalesBridgeParseSourceFromNotes(?string $notes): string
{
    if ($notes === null || trim($notes) === '') {
        return '';
    }

    if (preg_match('/Source:\s*(.+?)(?:\R|$)/i', $notes, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function crmSalesBridgeCustomerCrmStatus(array $customer): string
{
    $raw = strtolower(trim((string) ($customer['status'] ?? '')));
    if (in_array($raw, ['lead', 'prospect', 'customer', 'inactive'], true)) {
        return $raw;
    }

    return 'customer';
}

function crmSalesBridgeFormatInvoiceAmount(float $amount, string $currency = ''): string
{
    if ($amount <= 0) {
        return '-';
    }

    $formatted = number_format($amount, 2, '.', ',');
    $currency = trim($currency);

    return $currency !== '' ? ($currency . ' ' . $formatted) : $formatted;
}

/**
 * @return array<int, array<string, mixed>>
 */
function crmSalesBridgeFetchAllCustomers(): array
{
    crmSalesBridgeLoadDeps();

    $salesDb = customersDeskSalesDb();
    ensureCustomerColumnsExist();

    $where = 'WHERE 1=1';
    $params = [];

    if (function_exists('salesCompanyScopeSql')) {
        $scope = salesCompanyScopeSql('customers');
        if (!empty($scope[0])) {
            $where .= $scope[0];
            $params = array_merge($params, $scope[1]);
        }
    }

    $stmt = $salesDb->prepare("SELECT * FROM customers $where ORDER BY company_name ASC, id ASC");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function crmSalesBridgeEnsureAllContactsSynced(PDO $pdo, int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }

    crmSalesBridgeLoadDeps();
    crmEngineEnsureSchema($pdo);

    foreach (crmSalesBridgeFetchAllCustomers() as $customer) {
        $customerId = (int) ($customer['id'] ?? 0);
        if ($customerId <= 0) {
            continue;
        }

        $ownerId = (int) ($customer['created_by'] ?? 0);
        if ($ownerId <= 0) {
            $ownerId = (int) ($_SESSION['user_id'] ?? 0);
        }

        $source = crmSalesBridgeParseSourceFromNotes((string) ($customer['notes'] ?? ''));
        $contactPerson = trim((string) ($customer['contact_person'] ?? ''));
        if ($contactPerson === '') {
            $contactPerson = trim((string) ($customer['company_name'] ?? ''));
        }
        if ($contactPerson === '') {
            $contactPerson = 'Client #' . $customerId;
        }

        $payload = [
            'company_name' => (string) ($customer['company_name'] ?? ''),
            'contact_person' => $contactPerson,
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'status' => crmSalesBridgeCustomerCrmStatus($customer),
            'source' => $source,
            'notes' => (string) ($customer['notes'] ?? ''),
        ];

        $check = $pdo->prepare('SELECT id, created_by FROM crm_contacts WHERE company_id = ? AND customer_id = ? LIMIT 1');
        $check->execute([$companyId, $customerId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($ownerId > 0 && (int) ($existing['created_by'] ?? 0) <= 0) {
                $update = $pdo->prepare('UPDATE crm_contacts SET created_by = ? WHERE company_id = ? AND id = ?');
                $update->execute([$ownerId, $companyId, (int) $existing['id']]);
            }
            continue;
        }

        crmSalesBridgeLinkExistingCustomer($pdo, $companyId, $ownerId, $customerId, $payload);
    }
}

/**
 * @return array<int, array{invoice_total: float, currency: string}>
 */
function crmSalesBridgeCustomerInvoiceTotalsMap(): array
{
    crmSalesBridgeLoadDeps();

    $salesDb = customersDeskSalesDb();

    try {
        if (function_exists('tableExists') && !tableExists('invoices', $salesDb)) {
            return [];
        }

        $sql = '
            SELECT
                i.customer_id,
                COALESCE(SUM(i.total_amount), 0) AS invoice_total,
                MAX(c.currency) AS currency
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE LOWER(TRIM(COALESCE(i.status, \'\'))) <> \'cancelled\'
        ';
        $params = [];

        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }

        $sql .= ' GROUP BY i.customer_id';

        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }

            $map[$customerId] = [
                'invoice_total' => (float) ($row['invoice_total'] ?? 0),
                'currency' => trim((string) ($row['currency'] ?? '')),
            ];
        }

        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<int, array<string, mixed>> $contacts
 * @return array<int, array<string, mixed>>
 */
function crmSalesBridgeAttachInvoiceTotals(array $contacts): array
{
    if ($contacts === []) {
        return $contacts;
    }

    $totals = crmSalesBridgeCustomerInvoiceTotalsMap();

    foreach ($contacts as &$contact) {
        $customerId = (int) ($contact['customer_id'] ?? 0);
        $summary = $customerId > 0 ? ($totals[$customerId] ?? null) : null;

        $contact['invoice_total'] = $summary ? (float) ($summary['invoice_total'] ?? 0) : 0.0;
        $contact['invoice_currency'] = $summary ? (string) ($summary['currency'] ?? '') : '';
        $contact['invoice_amount'] = crmSalesBridgeFormatInvoiceAmount(
            (float) $contact['invoice_total'],
            (string) $contact['invoice_currency']
        );
    }
    unset($contact);

    return $contacts;
}

/**
 * @return array<string, mixed>
 */
function crmSalesBridgeFetchCustomerSalesDetail(int $customerId): array
{
    crmSalesBridgeLoadDeps();

    $module = 'sales';
    $emptyTotals = [
        'quotes_total' => 0.0,
        'quotes_count' => 0,
        'quotes_total_formatted' => '-',
        'invoices_total' => 0.0,
        'invoices_count' => 0,
        'invoices_total_formatted' => '-',
        'currency' => 'TZS',
    ];

    if ($customerId <= 0) {
        return [
            'linked' => false,
            'quotes' => [],
            'invoices' => [],
            'totals' => $emptyTotals,
            'urls' => [],
        ];
    }

    $salesDb = customersDeskSalesDb();
    $currency = 'TZS';

    try {
        $custStmt = $salesDb->prepare('SELECT currency FROM customers WHERE id = ? LIMIT 1');
        $custStmt->execute([$customerId]);
        $customerRow = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($customerRow) {
            $rowCurrency = trim((string) ($customerRow['currency'] ?? ''));
            if ($rowCurrency !== '') {
                $currency = $rowCurrency;
            }
        }
    } catch (Throwable $e) {
        // keep default currency
    }

    $quotes = [];
    $quotesTotal = 0.0;
    $supportsOrderTypeSplit = function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices();

    try {
        $hasOrders = !function_exists('tableExists') || tableExists('sales_orders', $salesDb);
        if ($hasOrders) {
            $hasOrderType = function_exists('columnExists') && columnExists('sales_orders', 'order_type', $salesDb);
            $orderTypeSelect = $hasOrderType ? 'so.order_type' : "'spare' AS order_type";
            $sql = "
                SELECT so.id, so.order_number, so.quote_date, so.created_at, so.total_amount, so.status, so.currency,
                       {$orderTypeSelect}, u.full_name AS salesperson
                FROM sales_orders so
                LEFT JOIN users u ON so.created_by = u.id
                WHERE so.customer_id = ?
                  AND LOWER(TRIM(COALESCE(so.status, ''))) IN ('draft', 'quotation')
            ";
            $params = [$customerId];

            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($sql, $params, 'sales_orders', 'so');
            }

            $sql .= ' ORDER BY COALESCE(so.quote_date, so.created_at) DESC, so.id DESC';

            $stmt = $salesDb->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $amount = (float) ($row['total_amount'] ?? 0);
                $rowCurrency = trim((string) ($row['currency'] ?? ''));
                if ($rowCurrency === '') {
                    $rowCurrency = $currency;
                }

                $quotesTotal += $amount;
                $orderId = (int) ($row['id'] ?? 0);
                $date = trim((string) ($row['quote_date'] ?? ''));
                if ($date === '') {
                    $date = trim((string) ($row['created_at'] ?? ''));
                }

                $orderType = strtolower(trim((string) ($row['order_type'] ?? 'spare')));
                if ($orderType === '') {
                    $orderType = 'spare';
                }

                $quotes[] = [
                    'id' => $orderId,
                    'number' => (string) ($row['order_number'] ?? ''),
                    'date' => $date,
                    'status' => (string) ($row['status'] ?? ''),
                    'order_type' => $orderType,
                    'salesperson' => (string) ($row['salesperson'] ?? ''),
                    'amount' => $amount,
                    'amount_formatted' => crmSalesBridgeFormatInvoiceAmount($amount, $rowCurrency),
                    'view_url' => function_exists('sales_module_url')
                        ? sales_module_url('orders/view.php', ['id' => $orderId, 'module' => $module])
                        : '',
                    'download_url' => function_exists('sales_module_url')
                        ? sales_module_url('orders/print.php', ['id' => $orderId, 'download' => 1, 'module' => $module])
                        : '',
                ];
            }
        }
    } catch (Throwable $e) {
        $quotes = [];
        $quotesTotal = 0.0;
    }

    $invoices = [];
    $invoicesTotal = 0.0;

    try {
        $hasInvoices = !function_exists('tableExists') || tableExists('invoices', $salesDb);
        if ($hasInvoices) {
            $hasInvoiceCreatedBy = function_exists('columnExists') && columnExists('invoices', 'created_by', $salesDb);
            $salespersonSelect = $hasInvoiceCreatedBy ? 'u.full_name AS salesperson' : "'' AS salesperson";
            $userJoin = $hasInvoiceCreatedBy ? 'LEFT JOIN users u ON i.created_by = u.id' : '';
            $sql = "
                SELECT i.id, i.invoice_number, i.invoice_date, i.created_at, i.total_amount, i.status, i.currency, i.balance_due,
                       {$salespersonSelect}
                FROM invoices i
                {$userJoin}
                WHERE i.customer_id = ?
                  AND LOWER(TRIM(COALESCE(i.status, ''))) <> 'cancelled'
            ";
            $params = [$customerId];

            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($sql, $params, 'invoices', 'i');
            }

            $sql .= ' ORDER BY COALESCE(i.invoice_date, i.created_at) DESC, i.id DESC';

            $stmt = $salesDb->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $amount = (float) ($row['total_amount'] ?? 0);
                $rowCurrency = trim((string) ($row['currency'] ?? ''));
                if ($rowCurrency === '') {
                    $rowCurrency = $currency;
                }

                $invoicesTotal += $amount;
                $invoiceId = (int) ($row['id'] ?? 0);
                $date = trim((string) ($row['invoice_date'] ?? ''));
                if ($date === '') {
                    $date = trim((string) ($row['created_at'] ?? ''));
                }

                $invoices[] = [
                    'id' => $invoiceId,
                    'number' => (string) ($row['invoice_number'] ?? ''),
                    'date' => $date,
                    'status' => (string) ($row['status'] ?? ''),
                    'salesperson' => (string) ($row['salesperson'] ?? ''),
                    'amount' => $amount,
                    'amount_formatted' => crmSalesBridgeFormatInvoiceAmount($amount, $rowCurrency),
                    'balance_due' => (float) ($row['balance_due'] ?? 0),
                    'view_url' => function_exists('sales_module_url')
                        ? sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => $module])
                        : '',
                    'download_url' => function_exists('sales_module_url')
                        ? sales_module_url('invoices/print.php', ['id' => $invoiceId, 'download' => 1, 'module' => $module])
                        : '',
                ];
            }
        }
    } catch (Throwable $e) {
        $invoices = [];
        $invoicesTotal = 0.0;
    }

    return [
        'linked' => true,
        'quotes' => $quotes,
        'invoices' => $invoices,
        'supports_order_type_split' => $supportsOrderTypeSplit,
        'totals' => [
            'quotes_total' => $quotesTotal,
            'quotes_count' => count($quotes),
            'quotes_total_formatted' => crmSalesBridgeFormatInvoiceAmount($quotesTotal, $currency),
            'invoices_total' => $invoicesTotal,
            'invoices_count' => count($invoices),
            'invoices_total_formatted' => crmSalesBridgeFormatInvoiceAmount($invoicesTotal, $currency),
            'currency' => $currency,
        ],
        'urls' => [
            'order_view' => function_exists('sales_module_url')
                ? sales_module_url('orders/view.php', ['module' => $module])
                : '',
            'invoice_view' => function_exists('sales_module_url')
                ? sales_module_url('invoices/view.php', ['module' => $module])
                : '',
            'new_quote' => function_exists('sales_module_url')
                ? sales_module_url('orders/create.php', ['customer_id' => $customerId, 'mode' => 'new', 'module' => $module])
                : '',
        ],
    ];
}

/**
 * Customer ids owned by a user: direct customer ownership plus customers
 * referenced on that user's quotations/orders and invoices.
 *
 * @return array<int, int>
 */
function crmSalesBridgeFetchUserCustomerIds(int $userId): array
{
    crmSalesBridgeLoadDeps();

    if ($userId <= 0) {
        return [];
    }

    $salesDb = customersDeskSalesDb();
    $ids = [];

    foreach (crmSalesBridgeFetchUserCustomers($userId) as $customer) {
        $customerId = (int) ($customer['id'] ?? 0);
        if ($customerId > 0) {
            $ids[$customerId] = $customerId;
        }
    }

    try {
        if (!function_exists('tableExists') || tableExists('sales_orders', $salesDb)) {
            $sql = '
                SELECT DISTINCT so.customer_id
                FROM sales_orders so
                WHERE so.created_by = ?
                  AND so.customer_id IS NOT NULL
                  AND so.customer_id > 0
            ';
            $params = [$userId];

            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($sql, $params, 'sales_orders', 'so');
            }

            $stmt = $salesDb->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $customerId) {
                $customerId = (int) $customerId;
                if ($customerId > 0) {
                    $ids[$customerId] = $customerId;
                }
            }
        }
    } catch (Throwable $e) {
        // Keep customer ownership results when sales orders are unavailable.
    }

    try {
        if (!function_exists('tableExists') || tableExists('invoices', $salesDb)) {
            $hasInvoiceCreatedBy = function_exists('columnExists') && columnExists('invoices', 'created_by', $salesDb);
            if ($hasInvoiceCreatedBy) {
                $sql = '
                    SELECT DISTINCT i.customer_id
                    FROM invoices i
                    WHERE i.created_by = ?
                      AND i.customer_id IS NOT NULL
                      AND i.customer_id > 0
                      AND LOWER(TRIM(COALESCE(i.status, \'\'))) <> \'cancelled\'
                ';
                $params = [$userId];

                if (function_exists('salesAppendCompanyScope')) {
                    salesAppendCompanyScope($sql, $params, 'invoices', 'i');
                }

                $stmt = $salesDb->prepare($sql);
                $stmt->execute($params);

                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $customerId) {
                    $customerId = (int) $customerId;
                    if ($customerId > 0) {
                        $ids[$customerId] = $customerId;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Keep prior ownership results when invoices are unavailable.
    }

    return array_values($ids);
}

/**
 * @param array<int|string> $customerIds
 * @return array<int, array<string, mixed>>
 */
function crmSalesBridgeFetchCustomersByIds(array $customerIds): array
{
    crmSalesBridgeLoadDeps();

    $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds), static fn (int $id): bool => $id > 0)));
    if ($customerIds === []) {
        return [];
    }

    $salesDb = customersDeskSalesDb();
    ensureCustomerColumnsExist();

    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $where = "WHERE id IN ($placeholders)";
    $params = $customerIds;

    if (function_exists('salesCompanyScopeSql')) {
        $scope = salesCompanyScopeSql('customers');
        if (!empty($scope[0])) {
            $where .= $scope[0];
            $params = array_merge($params, $scope[1]);
        }
    }

    $stmt = $salesDb->prepare("SELECT * FROM customers $where ORDER BY company_name ASC, id ASC");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function crmSalesBridgeFetchUserCustomers(int $userId): array
{
    crmSalesBridgeLoadDeps();

    if ($userId <= 0) {
        return [];
    }

    $salesDb = customersDeskSalesDb();
    ensureCustomerColumnsExist();

    $where = 'WHERE created_by = ?';
    $params = [$userId];

    if (function_exists('salesCompanyScopeSql')) {
        $scope = salesCompanyScopeSql('customers');
        if (!empty($scope[0])) {
            $where .= $scope[0];
            $params = array_merge($params, $scope[1]);
        }
    }

    $stmt = $salesDb->prepare("SELECT * FROM customers $where ORDER BY id ASC");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function crmSalesBridgeEnsureUserContactsSynced(PDO $pdo, int $companyId, int $userId): void
{
    if ($companyId <= 0 || $userId <= 0) {
        return;
    }

    crmSalesBridgeLoadDeps();
    crmEngineEnsureSchema($pdo);

    $ownedCustomerIds = array_fill_keys(crmSalesBridgeFetchUserCustomerIds($userId), true);

    foreach (crmSalesBridgeFetchCustomersByIds(array_keys($ownedCustomerIds)) as $customer) {
        $customerId = (int) ($customer['id'] ?? 0);
        if ($customerId <= 0) {
            continue;
        }

        $source = crmSalesBridgeParseSourceFromNotes((string) ($customer['notes'] ?? ''));
        $contactPerson = trim((string) ($customer['contact_person'] ?? ''));
        if ($contactPerson === '') {
            $contactPerson = trim((string) ($customer['company_name'] ?? ''));
        }
        if ($contactPerson === '') {
            $contactPerson = 'Client #' . $customerId;
        }

        $payload = [
            'company_name' => (string) ($customer['company_name'] ?? ''),
            'contact_person' => $contactPerson,
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'status' => crmSalesBridgeCustomerCrmStatus($customer),
            'source' => $source,
            'notes' => (string) ($customer['notes'] ?? ''),
        ];

        $check = $pdo->prepare('SELECT id, created_by FROM crm_contacts WHERE company_id = ? AND customer_id = ? LIMIT 1');
        $check->execute([$companyId, $customerId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ((int) ($existing['created_by'] ?? 0) !== $userId) {
                $update = $pdo->prepare('UPDATE crm_contacts SET created_by = ? WHERE company_id = ? AND id = ?');
                $update->execute([$userId, $companyId, (int) $existing['id']]);
            }
            continue;
        }

        crmSalesBridgeLinkExistingCustomer($pdo, $companyId, $userId, $customerId, $payload);
    }

    $orphanStmt = $pdo->prepare('
        SELECT id, customer_id, created_by
        FROM crm_contacts
        WHERE company_id = ?
          AND customer_id IS NOT NULL
          AND (created_by IS NULL OR created_by = 0 OR created_by <> ?)
    ');
    $orphanStmt->execute([$companyId, $userId]);
    $orphans = $orphanStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($orphans === []) {
        return;
    }

    $update = $pdo->prepare('UPDATE crm_contacts SET created_by = ? WHERE company_id = ? AND id = ?');
    foreach ($orphans as $row) {
        $customerId = (int) ($row['customer_id'] ?? 0);
        if ($customerId > 0 && isset($ownedCustomerIds[$customerId])) {
            $update->execute([$userId, $companyId, (int) $row['id']]);
        }
    }
}

function crmSalesBridgeUserOwnsContact(PDO $pdo, array $contact, int $userId): bool
{
    if ($userId <= 0) {
        return true;
    }

    if ((int) ($contact['created_by'] ?? 0) === $userId) {
        return true;
    }

    $customerId = (int) ($contact['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return false;
    }

    static $customerIdCache = [];
    if (!isset($customerIdCache[$userId])) {
        $customerIdCache[$userId] = array_fill_keys(crmSalesBridgeFetchUserCustomerIds($userId), true);
    }

    return isset($customerIdCache[$userId][$customerId]);
}

function crmSalesBridgeAssertUserContactAccess(PDO $pdo, array $contact, ?int $userId): void
{
    if ($userId === null || $userId <= 0) {
        return;
    }

    if (!crmSalesBridgeUserOwnsContact($pdo, $contact, $userId)) {
        throw new InvalidArgumentException('Contact not found.');
    }
}
