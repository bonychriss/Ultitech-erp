<?php

declare(strict_types=1);

/**
 * Revenue spreadsheet import helpers (CSV / XLSX).
 * Template: CUSTOMER NAME, PRODUCT NAME, DATE, TIN NUMBER, VRN, QUANTITY, AMOUNT
 */

require_once __DIR__ . '/../../expenses/includes/import_helpers.php';
require_once __DIR__ . '/revenue-create-lib.php';

/**
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<array{row:int,cells:list<string>}>,header_row?:int}
 */
function revenue_import_read_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed. Please choose a file and try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = (string) ($file['name'] ?? 'upload');
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'message' => 'Uploaded file was not found.'];
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        return revenue_import_read_csv($tmp);
    }
    if ($ext === 'xlsx') {
        return revenue_import_read_xlsx($tmp);
    }

    return [
        'ok' => false,
        'message' => 'Unsupported file type. Upload a .xlsx or .csv file.',
    ];
}

/**
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<array{row:int,cells:list<string>}>,header_row?:int}
 */
function revenue_import_read_csv(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return ['ok' => false, 'message' => 'Could not read CSV file.'];
    }

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $firstPos = ftell($fh);
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);

        return ['ok' => false, 'message' => 'CSV file is empty.'];
    }
    fseek($fh, $firstPos);

    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $matrix = [];
    while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
        $matrix[] = array_map(static fn ($c) => trim((string) $c), $cells);
    }
    fclose($fh);

    return revenue_import_matrix_to_table($matrix);
}

/**
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<array{row:int,cells:list<string>}>,header_row?:int}
 */
function revenue_import_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'ZipArchive is required to read Excel files.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'message' => 'Could not open Excel file.'];
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $shared = expenses_import_parse_shared_strings($sharedXml);
    }

    $sheetPath = expenses_import_xlsx_first_sheet_path($zip);
    $sheetXml = $sheetPath !== null ? $zip->getFromName($sheetPath) : false;
    $zip->close();

    if (!is_string($sheetXml) || $sheetXml === '') {
        return ['ok' => false, 'message' => 'Excel sheet data was not found.'];
    }

    $matrix = expenses_import_parse_sheet_matrix($sheetXml, $shared);

    return revenue_import_matrix_to_table($matrix);
}

/**
 * @param list<list<string>> $matrix
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<array{row:int,cells:list<string>}>,header_row?:int}
 */
function revenue_import_matrix_to_table(array $matrix): array
{
    $headerIdx = null;
    $headers = [];
    foreach ($matrix as $i => $row) {
        $normalized = array_map('expenses_import_normalize_header', $row);
        if (revenue_import_headers_look_valid($normalized)) {
            $headerIdx = $i;
            $headers = $normalized;
            break;
        }
    }

    if ($headerIdx === null) {
        return [
            'ok' => false,
            'message' => 'Could not find a header row with CUSTOMER NAME, PRODUCT NAME, DATE, QUANTITY, and AMOUNT columns.',
        ];
    }

    $width = count($headers);
    $rows = [];
    for ($i = $headerIdx + 1, $n = count($matrix); $i < $n; $i++) {
        $raw = $matrix[$i];
        $cells = [];
        $empty = true;
        for ($c = 0; $c < $width; $c++) {
            $val = trim((string) ($raw[$c] ?? ''));
            if ($val !== '') {
                $empty = false;
            }
            $cells[] = $val;
        }
        if ($empty) {
            continue;
        }
        $rows[] = [
            'row' => $i + 1,
            'cells' => $cells,
        ];
    }

    if ($rows === []) {
        return ['ok' => false, 'message' => 'No data rows found under the header.'];
    }

    return [
        'ok' => true,
        'headers' => $headers,
        'rows' => $rows,
        'header_row' => $headerIdx + 1,
    ];
}

/**
 * @return list<array{key:string,label:string,required:bool,hint:string}>
 */
function revenue_import_template_columns(): array
{
    return [
        ['key' => 'customer_name', 'label' => 'CUSTOMER NAME', 'required' => true, 'hint' => 'Customer / company name (created if new)'],
        ['key' => 'product_name', 'label' => 'PRODUCT NAME', 'required' => true, 'hint' => 'Product sold (created if new)'],
        ['key' => 'date', 'label' => 'DATE', 'required' => true, 'hint' => '7-Apr, 07/04/2026 or 2026-04-07'],
        ['key' => 'tin', 'label' => 'TIN NUMBER', 'required' => false, 'hint' => 'Customer TIN (saved on new/existing customers)'],
        ['key' => 'vrn', 'label' => 'VRN', 'required' => false, 'hint' => 'Customer VRN / VAT registration number'],
        ['key' => 'quantity', 'label' => 'QUANTITY', 'required' => true, 'hint' => 'Units sold'],
        ['key' => 'amount', 'label' => 'AMOUNT', 'required' => true, 'hint' => 'Line amount (VAT exclusive)'],
        ['key' => 'vat_rate', 'label' => 'VAT RATE', 'required' => false, 'hint' => '18, 10 or 0 (exempt). Default 18'],
    ];
}

/**
 * @return list<string>
 */
function revenue_import_template_headers(): array
{
    return array_map(static fn (array $c) => $c['label'], revenue_import_template_columns());
}

/**
 * @param list<string> $headers
 * @return array<string,int>
 */
function revenue_import_header_map(array $headers): array
{
    $map = [];
    foreach ($headers as $i => $header) {
        $h = expenses_import_normalize_header((string) $header);
        if (
            in_array($h, ['customer name', 'customer', 'client', 'client name', 'company', 'company name', 'buyer'], true)
            && !isset($map['customer_name'])
        ) {
            $map['customer_name'] = $i;
            continue;
        }
        if (
            in_array($h, ['product name', 'product', 'item', 'item name', 'goods', 'service', 'service name'], true)
            && !isset($map['product_name'])
        ) {
            $map['product_name'] = $i;
            continue;
        }
        if (in_array($h, ['date', 'entry date', 'revenue date', 'invoice date', 'txn date', 'transaction date'], true) && !isset($map['date'])) {
            $map['date'] = $i;
            continue;
        }
        if (in_array($h, ['tin number', 'tin', 'tin no', 'tin no.', 'tax identification number', 'tax number'], true) && !isset($map['tin'])) {
            $map['tin'] = $i;
            continue;
        }
        if (in_array($h, ['vrn', 'vrn number', 'vat number', 'vat no', 'vat registration', 'vat reg'], true) && !isset($map['vrn'])) {
            $map['vrn'] = $i;
            continue;
        }
        if (in_array($h, ['quantity', 'qty', 'units', 'unit', 'qnty'], true) && !isset($map['quantity'])) {
            $map['quantity'] = $i;
            continue;
        }
        if (in_array($h, ['amount', 'total', 'total amount', 'line amount', 'value', 'revenue', 'sales amount'], true) && !isset($map['amount'])) {
            $map['amount'] = $i;
            continue;
        }
        if (
            in_array($h, ['vat rate', 'vat %', 'vat percent', 'vat percentage', 'tax rate', 'vat'], true)
            && !isset($map['vat_rate'])
        ) {
            $map['vat_rate'] = $i;
        }
    }

    return $map;
}

/**
 * @param list<string> $headers
 */
function revenue_import_headers_look_valid(array $headers): bool
{
    $map = revenue_import_header_map($headers);

    return isset($map['customer_name'], $map['product_name'], $map['date'], $map['quantity'], $map['amount']);
}

function revenue_import_parse_vat_rate(string $raw): string
{
    $v = strtolower(trim($raw));
    if ($v === '') {
        return '18';
    }
    if (in_array($v, ['exempt', 'nil', 'none', 'n/a', 'na', '0', '0%'], true)) {
        return '0';
    }
    $num = expenses_import_parse_number(str_replace('%', '', $v));
    if ($num === null) {
        return '18';
    }
    $n = (int) round($num);
    if ($n < 0 || $n > 100) {
        return '18';
    }

    return (string) $n;
}

/**
 * @param list<string> $headers
 * @param list<array{row?:int,cells?:list<string>}|list<string>> $rows
 * @return list<array<string,mixed>>
 */
function revenue_import_map_rows(array $headers, array $rows, int $defaultYear, int $headerRowNumber = 1): array
{
    $map = revenue_import_header_map($headers);
    $out = [];

    foreach ($rows as $i => $entry) {
        if (isset($entry['cells']) && is_array($entry['cells'])) {
            $cells = $entry['cells'];
            $excelRow = (int) ($entry['row'] ?? ($headerRowNumber + 1 + $i));
        } else {
            $cells = is_array($entry) ? $entry : [];
            $excelRow = $headerRowNumber + 1 + $i;
        }

        $customer = isset($map['customer_name']) ? trim((string) ($cells[$map['customer_name']] ?? '')) : '';
        $product = isset($map['product_name']) ? trim((string) ($cells[$map['product_name']] ?? '')) : '';
        $dateRaw = isset($map['date']) ? trim((string) ($cells[$map['date']] ?? '')) : '';
        $tin = isset($map['tin']) ? trim((string) ($cells[$map['tin']] ?? '')) : '';
        $vrn = isset($map['vrn']) ? trim((string) ($cells[$map['vrn']] ?? '')) : '';
        $qtyRaw = isset($map['quantity']) ? trim((string) ($cells[$map['quantity']] ?? '')) : '';
        $amountRaw = isset($map['amount']) ? trim((string) ($cells[$map['amount']] ?? '')) : '';
        $vatRaw = isset($map['vat_rate']) ? trim((string) ($cells[$map['vat_rate']] ?? '')) : '';

        $date = expenses_import_parse_date($dateRaw, $defaultYear);
        $quantity = expenses_import_parse_number($qtyRaw);
        $amount = expenses_import_parse_number($amountRaw);

        $error = '';
        if ($customer === '') {
            $error = 'Missing customer name';
        } elseif ($product === '') {
            $error = 'Missing product name';
        } elseif ($date === null) {
            $error = 'Invalid date' . ($dateRaw !== '' ? " ({$dateRaw})" : '');
        } elseif ($quantity === null || $quantity <= 0) {
            $error = 'Invalid quantity';
        } elseif ($amount === null || $amount <= 0) {
            $error = 'Invalid amount';
        }

        $unitPrice = 0.0;
        if ($error === '' && $quantity !== null && $quantity > 0 && $amount !== null) {
            $unitPrice = round($amount / $quantity, 4);
        }

        $out[] = [
            'row' => $excelRow,
            'customer_name' => $customer,
            'product_name' => $product,
            'date' => $date ?? '',
            'date_raw' => $dateRaw,
            'tin' => $tin,
            'vrn' => $vrn,
            'quantity' => $quantity ?? 0.0,
            'amount' => $amount ?? 0.0,
            'vat_rate' => revenue_import_parse_vat_rate($vatRaw),
            'unit_price' => $unitPrice,
            'ok' => $error === '',
            'error' => $error,
            'will_create_customer' => false,
            'will_create_product' => false,
            'customer_match' => '',
            'product_match' => '',
        ];
    }

    return $out;
}

/**
 * Annotate mapped rows with whether customer/product already exist.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function revenue_import_annotate_matches(PDO $pdo, array $rows): array
{
    $customersExist = revenue_import_table_exists($pdo, 'customers');
    $productsExist = revenue_import_table_exists($pdo, 'products');

    foreach ($rows as &$row) {
        if (empty($row['ok'])) {
            continue;
        }

        $customerName = (string) ($row['customer_name'] ?? '');
        $productName = (string) ($row['product_name'] ?? '');
        $tin = (string) ($row['tin'] ?? '');

        $customerId = 0;
        if ($customersExist && $customerName !== '') {
            $customerId = revenue_import_find_customer_id($pdo, $customerName, $tin);
        }
        $row['will_create_customer'] = $customerId <= 0;
        $row['customer_match'] = $customerId > 0 ? 'Existing customer' : 'New customer';

        $productId = 0;
        if ($productsExist && $productName !== '') {
            $productId = revenue_import_find_product_id($pdo, $productName);
        }
        $row['will_create_product'] = $productId <= 0;
        $row['product_match'] = $productId > 0 ? 'Existing product' : 'New product';
    }
    unset($row);

    return $rows;
}

function revenue_import_table_exists(PDO $pdo, string $table): bool
{
    if (function_exists('tableExists')) {
        return tableExists($table, $pdo);
    }
    try {
        $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

        return (bool) ($st && $st->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function revenue_import_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $pdo->quote($column));
        $cache[$key] = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function revenue_import_find_customer_id(PDO $pdo, string $name, string $tin = ''): int
{
    $name = trim($name);
    $tin = trim($tin);
    if ($name === '' && $tin === '') {
        return 0;
    }

    try {
        if ($tin !== '' && revenue_import_has_column($pdo, 'customers', 'tin')) {
            $st = $pdo->prepare('SELECT id FROM customers WHERE tin = ? LIMIT 1');
            $st->execute([$tin]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($name !== '') {
            if (revenue_import_has_column($pdo, 'customers', 'company_name')) {
                $st = $pdo->prepare('SELECT id FROM customers WHERE LOWER(TRIM(company_name)) = LOWER(?) LIMIT 1');
                $st->execute([$name]);
                $id = (int) ($st->fetchColumn() ?: 0);
                if ($id > 0) {
                    return $id;
                }
            }
            if (revenue_import_has_column($pdo, 'customers', 'customer_name')) {
                $st = $pdo->prepare('SELECT id FROM customers WHERE LOWER(TRIM(customer_name)) = LOWER(?) LIMIT 1');
                $st->execute([$name]);
                $id = (int) ($st->fetchColumn() ?: 0);
                if ($id > 0) {
                    return $id;
                }
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

function revenue_import_find_product_id(PDO $pdo, string $name): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1');
        $st->execute([$name]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function revenue_import_ensure_revenue_customer(PDO $pdo, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `revenue_customers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `customer_name` varchar(255) NOT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `email` varchar(100) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $st = $pdo->prepare('SELECT id FROM revenue_customers WHERE LOWER(TRIM(customer_name)) = LOWER(?) LIMIT 1');
        $st->execute([$name]);
        if ((int) ($st->fetchColumn() ?: 0) > 0) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO revenue_customers (customer_name) VALUES (?)');
        $ins->execute([$name]);
    } catch (Throwable $e) {
        error_log('revenue_import_ensure_revenue_customer: ' . $e->getMessage());
    }
}

/**
 * @return array{id:int,created:bool,error?:string}
 */
function revenue_import_upsert_customer(PDO $pdo, string $name, string $tin, string $vrn): array
{
    $name = trim($name);
    $tin = trim($tin);
    $vrn = trim($vrn);

    if ($name === '') {
        return ['id' => 0, 'created' => false, 'error' => 'Customer name required'];
    }

    revenue_import_ensure_revenue_customer($pdo, $name);

    if (!revenue_import_table_exists($pdo, 'customers')) {
        return ['id' => 0, 'created' => true];
    }

    if (is_file(dirname(__DIR__, 3) . '/modules/sales/functions.php')) {
        require_once dirname(__DIR__, 3) . '/modules/sales/functions.php';
    }
    if (function_exists('ensureCustomerColumnsExist')) {
        try {
            ensureCustomerColumnsExist();
        } catch (Throwable $e) {
            // continue with best-effort insert
        }
    }

    $existingId = revenue_import_find_customer_id($pdo, $name, $tin);
    if ($existingId > 0) {
        try {
            $sets = [];
            $vals = [];
            if ($tin !== '' && revenue_import_has_column($pdo, 'customers', 'tin')) {
                $sets[] = 'tin = ?';
                $vals[] = $tin;
            }
            if ($vrn !== '' && revenue_import_has_column($pdo, 'customers', 'vrn')) {
                $sets[] = 'vrn = ?';
                $vals[] = $vrn;
            }
            if ($tin !== '' || $vrn !== '') {
                if (revenue_import_has_column($pdo, 'customers', 'tax_number')) {
                    $sets[] = 'tax_number = ?';
                    $vals[] = trim($tin . ($vrn !== '' ? ' / ' . $vrn : ''));
                }
            }
            if ($sets !== []) {
                $vals[] = $existingId;
                $pdo->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            }
        } catch (Throwable $e) {
            error_log('revenue_import_upsert_customer update: ' . $e->getMessage());
        }

        return ['id' => $existingId, 'created' => false];
    }

    try {
        $code = 'CUST-' . date('Y') . '-IMP-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        if (is_file(dirname(__DIR__, 3) . '/modules/sales/customers/includes/catalogue-lib.php')) {
            require_once dirname(__DIR__, 3) . '/modules/sales/customers/includes/catalogue-lib.php';
            if (function_exists('customerAddGenerateNextCode')) {
                try {
                    $code = customerAddGenerateNextCode($pdo);
                } catch (Throwable $e) {
                    // keep generated code
                }
            }
        }

        $cols = ['company_name'];
        $vals = [$name];

        if (revenue_import_has_column($pdo, 'customers', 'customer_code')) {
            $cols[] = 'customer_code';
            $vals[] = $code;
        }
        if (revenue_import_has_column($pdo, 'customers', 'contact_person')) {
            $cols[] = 'contact_person';
            $vals[] = $name;
        }
        if (revenue_import_has_column($pdo, 'customers', 'email')) {
            $cols[] = 'email';
            $vals[] = 'import+' . preg_replace('/[^a-z0-9]+/i', '', strtolower(substr($name, 0, 24))) . '@placeholder.local';
        }
        if (revenue_import_has_column($pdo, 'customers', 'phone')) {
            $cols[] = 'phone';
            $vals[] = '-';
        }
        if (revenue_import_has_column($pdo, 'customers', 'address')) {
            $cols[] = 'address';
            $vals[] = 'Imported via revenue import';
        }
        if (revenue_import_has_column($pdo, 'customers', 'city')) {
            $cols[] = 'city';
            $vals[] = 'Dar es Salaam';
        }
        if (revenue_import_has_column($pdo, 'customers', 'country')) {
            $cols[] = 'country';
            $vals[] = 'Tanzania';
        }
        if (revenue_import_has_column($pdo, 'customers', 'tax_number')) {
            $cols[] = 'tax_number';
            $vals[] = trim($tin . ($vrn !== '' ? ' / ' . $vrn : ''));
        }
        if (revenue_import_has_column($pdo, 'customers', 'tin')) {
            $cols[] = 'tin';
            $vals[] = $tin;
        }
        if (revenue_import_has_column($pdo, 'customers', 'vrn')) {
            $cols[] = 'vrn';
            $vals[] = $vrn;
        }
        if (revenue_import_has_column($pdo, 'customers', 'customer_type')) {
            $cols[] = 'customer_type';
            $vals[] = 'retail';
        }
        if (revenue_import_has_column($pdo, 'customers', 'payment_terms')) {
            $cols[] = 'payment_terms';
            $vals[] = 'Net 30';
        }
        if (revenue_import_has_column($pdo, 'customers', 'currency')) {
            $cols[] = 'currency';
            $vals[] = 'TZS';
        }
        if (revenue_import_has_column($pdo, 'customers', 'credit_limit')) {
            $cols[] = 'credit_limit';
            $vals[] = 0;
        }
        if (revenue_import_has_column($pdo, 'customers', 'notes')) {
            $cols[] = 'notes';
            $vals[] = 'Created from revenue import';
        }
        if (revenue_import_has_column($pdo, 'customers', 'created_by')) {
            $cols[] = 'created_by';
            $vals[] = (int) ($_SESSION['user_id'] ?? 1);
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $quoted = implode(', ', array_map(static fn ($c) => '`' . $c . '`', $cols));
        $pdo->prepare("INSERT INTO customers ({$quoted}) VALUES ({$placeholders})")->execute($vals);

        return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
    } catch (Throwable $e) {
        error_log('revenue_import_upsert_customer insert: ' . $e->getMessage());

        return ['id' => 0, 'created' => false, 'error' => $e->getMessage()];
    }
}

function revenue_import_ensure_category(PDO $pdo, string $name = 'General'): int
{
    $name = trim($name) !== '' ? trim($name) : 'General';
    if (!revenue_import_table_exists($pdo, 'categories')) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $st->execute([$name]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{id:int,created:bool,error?:string}
 */
function revenue_import_upsert_product(PDO $pdo, string $name, float $unitPrice): array
{
    $name = trim($name);
    if ($name === '') {
        return ['id' => 0, 'created' => false, 'error' => 'Product name required'];
    }

    if (!revenue_import_table_exists($pdo, 'products')) {
        return ['id' => 0, 'created' => true];
    }

    $existingId = revenue_import_find_product_id($pdo, $name);
    if ($existingId > 0) {
        if ($unitPrice > 0 && revenue_import_has_column($pdo, 'products', 'unit_price')) {
            try {
                $pdo->prepare('UPDATE products SET unit_price = ? WHERE id = ?')->execute([$unitPrice, $existingId]);
            } catch (Throwable $e) {
                // ignore price update failures
            }
        }

        return ['id' => $existingId, 'created' => false];
    }

    try {
        $year = date('Y');
        $prefix = "PRD-{$year}-";
        $stmtMax = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?"
        );
        $stmtMax->execute([$prefix . '%']);
        $next = ((int) ($stmtMax->fetchColumn() ?: 0)) + 1;
        $code = $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);

        $categoryId = revenue_import_ensure_category($pdo, 'Imported Revenue');
        $cols = ['product_code', 'name', 'unit_price'];
        $vals = [$code, $name, $unitPrice];

        if ($categoryId > 0 && revenue_import_has_column($pdo, 'products', 'category_id')) {
            $cols[] = 'category_id';
            $vals[] = $categoryId;
        }
        if (revenue_import_has_column($pdo, 'products', 'description')) {
            $cols[] = 'description';
            $vals[] = 'Created from revenue import';
        }
        if (revenue_import_has_column($pdo, 'products', 'item_type')) {
            $cols[] = 'item_type';
            $vals[] = 'general';
        }
        if (revenue_import_has_column($pdo, 'products', 'unit_of_measure')) {
            $cols[] = 'unit_of_measure';
            $vals[] = 'pcs';
        }
        if (revenue_import_has_column($pdo, 'products', 'currency')) {
            $cols[] = 'currency';
            $vals[] = 'TZS';
        }
        if (revenue_import_has_column($pdo, 'products', 'status')) {
            $cols[] = 'status';
            $vals[] = 'active';
        }
        if (revenue_import_has_column($pdo, 'products', 'reorder_level')) {
            $cols[] = 'reorder_level';
            $vals[] = 0;
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $quoted = implode(', ', array_map(static fn ($c) => '`' . $c . '`', $cols));
        $pdo->prepare("INSERT INTO products ({$quoted}) VALUES ({$placeholders})")->execute($vals);
        $productId = (int) $pdo->lastInsertId();

        if ($productId > 0 && revenue_import_table_exists($pdo, 'stock')) {
            try {
                $pdo->prepare(
                    'INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, ?)
                     ON DUPLICATE KEY UPDATE location = VALUES(location)'
                )->execute([$productId, 'Warehouse']);
            } catch (Throwable $e) {
                // stock table optional
            }
        }

        return ['id' => $productId, 'created' => true];
    } catch (Throwable $e) {
        error_log('revenue_import_upsert_product: ' . $e->getMessage());

        return ['id' => 0, 'created' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a revenue entry for import without requiring an attachment.
 *
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function revenue_import_create_entry(PDO $pdo, array $row, array $options): array
{
    $post = [
        'entry_date' => (string) ($row['date'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'narration' => sprintf(
            'Import: %s x %s',
            (string) ($row['product_name'] ?? ''),
            rtrim(rtrim(number_format((float) ($row['quantity'] ?? 0), 4, '.', ''), '0'), '.')
        ),
        'payment_mode' => (string) ($options['payment_mode'] ?? 'Account Receivable'),
        'amount_exclusive' => (float) ($row['amount'] ?? 0),
        'tax_treatment' => (string) ($options['tax_treatment'] ?? 'Exclusive'),
        'vat_rate' => (string) ($row['vat_rate'] ?? $options['vat_rate'] ?? '18'),
        'currency' => (string) ($options['currency'] ?? 'TZS'),
        'exchange_rate' => (float) ($options['exchange_rate'] ?? 1),
        'revenue_sub_account_id' => (int) ($options['revenue_sub_account_id'] ?? 0),
        'account_id' => (int) ($options['account_id'] ?? 0),
    ];

    // Temporarily skip attachment requirement by providing a placeholder path after validation path.
    // We call a dedicated import save that mirrors create but allows empty attachment.
    return revenue_import_save_entry($pdo, $post, $row);
}

/**
 * @param array<string,mixed> $post
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function revenue_import_save_entry(PDO $pdo, array $post, array $row): array
{
    require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
    require_once dirname(__DIR__, 3) . '/includes/accounting_service.php';
    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    require_once dirname(__DIR__, 3) . '/includes/accounting_settings.php';
    require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    require_once dirname(__DIR__, 3) . '/includes/invoice_gl_posting.php';

    revenue_ensure_account_schema($pdo);

    $errors = [];
    $entryDate = trim((string) ($post['entry_date'] ?? ''));
    $customerName = trim((string) ($post['customer_name'] ?? ''));
    $narration = trim((string) ($post['narration'] ?? ''));
    $paymentMode = trim((string) ($post['payment_mode'] ?? 'Account Receivable'));
    $amountTotalRaw = (float) ($post['amount_exclusive'] ?? 0);
    $taxTreatment = (string) ($post['tax_treatment'] ?? 'Exclusive');
    $vatRateRaw = (float) ($post['vat_rate'] ?? 18);

    if ($entryDate === '') {
        $errors[] = 'Revenue date is required.';
    }
    if ($customerName === '') {
        $errors[] = 'Customer is required.';
    }
    if ($amountTotalRaw <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }

    $amountExclusive = 0.0;
    $vatAmount = 0.0;
    $amountTotal = 0.0;

    if ($taxTreatment === 'Inclusive') {
        $amountTotal = round($amountTotalRaw, 2);
        $vatAmount = round($amountTotal * ($vatRateRaw / (100 + $vatRateRaw)), 2);
        $amountExclusive = round($amountTotal - $vatAmount, 2);
    } elseif ($taxTreatment === 'Exclusive') {
        $amountExclusive = round($amountTotalRaw, 2);
        $vatAmount = round($amountExclusive * ($vatRateRaw / 100), 2);
        $amountTotal = round($amountExclusive + $vatAmount, 2);
    } else {
        $amountExclusive = round($amountTotalRaw, 2);
        $vatAmount = 0.0;
        $amountTotal = $amountExclusive;
    }

    $totalPaid = 0.0;
    $paymentStatus = 'Unpaid';
    $immediatePaymentModes = ['Cash', 'Bank', 'Mobile'];
    if (in_array($paymentMode, $immediatePaymentModes, true)) {
        $totalPaid = $amountTotal;
        $paymentStatus = 'Paid';
    }

    $attachment = 'uploads/revenue/import-placeholder.txt';

    $allowedCurrencies = array_keys(revenue_create_allowed_currencies());
    $currency = strtoupper(trim((string) ($post['currency'] ?? 'TZS')));
    if (!in_array($currency, $allowedCurrencies, true)) {
        $currency = 'TZS';
    }
    $exchangeRate = (float) ($post['exchange_rate'] ?? 1);
    if ($currency === 'TZS') {
        $exchangeRate = 1.0;
    }

    $revenueSubAccountId = (int) ($post['revenue_sub_account_id'] ?? 0);
    $resolvedRevenueAccounts = null;
    if ($revenueSubAccountId > 0) {
        $resolvedRevenueAccounts = revenue_resolve_balances_sub_account_for_posting($pdo, $revenueSubAccountId, 83);
    }
    if ($resolvedRevenueAccounts === null) {
        $init = revenue_build_create_init($pdo);
        $defaultSub = (int) ($init['default_sub_account_id'] ?? 0);
        if ($defaultSub > 0) {
            $resolvedRevenueAccounts = revenue_resolve_balances_sub_account_for_posting($pdo, $defaultSub, 83);
            $revenueSubAccountId = $defaultSub;
        }
    }
    if ($resolvedRevenueAccounts === null) {
        $errors[] = 'No revenue sub-account is available. Set one up in Balances first.';
    }

    $depositAccountId = !empty($post['account_id']) ? (int) $post['account_id'] : 0;
    if (in_array($paymentMode, $immediatePaymentModes, true) && $depositAccountId <= 0) {
        $errors[] = 'Deposit account is required for Cash/Bank/Mobile imports.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    $revenueGlAccountId = (int) $resolvedRevenueAccounts['account_id'];
    $revenueGlCategoryId = (int) $resolvedRevenueAccounts['category_id'];
    $revenueEntryAccountCol = function_exists('resolveExistingColumn')
        ? resolveExistingColumn('revenue_entries', 'account_id', ['bank_account_id', 'gl_account_id', 'financial_account_id'])
        : (revenue_import_has_column($pdo, 'revenue_entries', 'account_id') ? 'account_id' : null);

    try {
        if (!function_exists('generateRevenueVoucherNumber')) {
            require_once dirname(__DIR__, 3) . '/includes/revenue_sync.php';
        }
        $voucherNumber = generateRevenueVoucherNumber($pdo);
        $pdo->beginTransaction();

        $hasRevenueAccountCol = revenue_import_has_column($pdo, 'revenue_entries', 'revenue_account_id');
        $hasRevenueCategoryCol = revenue_import_has_column($pdo, 'revenue_entries', 'revenue_category_id');
        $hasCurrencyCol = revenue_import_has_column($pdo, 'revenue_entries', 'currency');
        $hasExchangeRateCol = revenue_import_has_column($pdo, 'revenue_entries', 'exchange_rate');

        $insertCols = [
            'voucher_number', 'entry_date', 'customer_name', 'narration', 'payment_mode',
            'amount_exclusive', 'vat_amount', 'amount_total', 'total_paid', 'payment_status',
            'approval_status', 'attachment',
        ];
        $insertVals = [
            $voucherNumber, $entryDate, $customerName, $narration, $paymentMode,
            $amountExclusive, $vatAmount, $amountTotal, $totalPaid, $paymentStatus,
            'Ratified', $attachment,
        ];

        if ($revenueEntryAccountCol) {
            $insertCols[] = $revenueEntryAccountCol;
            $insertVals[] = $depositAccountId > 0 ? $depositAccountId : null;
        }
        if ($hasRevenueCategoryCol) {
            $insertCols[] = 'revenue_category_id';
            $insertVals[] = $revenueGlCategoryId;
        }
        if ($hasRevenueAccountCol) {
            $insertCols[] = 'revenue_account_id';
            $insertVals[] = $revenueGlAccountId;
        }
        if ($hasCurrencyCol) {
            $insertCols[] = 'currency';
            $insertVals[] = $currency;
        }
        if ($hasExchangeRateCol) {
            $insertCols[] = 'exchange_rate';
            $insertVals[] = round($exchangeRate, 6);
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare(
            'INSERT INTO revenue_entries (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($insertVals);
        $entryId = (int) $pdo->lastInsertId();

        if (function_exists('invoice_gl_post_revenue_recognition')) {
            invoice_gl_post_revenue_recognition(
                $pdo,
                $entryId,
                $voucherNumber,
                $entryDate,
                $customerName,
                $narration,
                $amountTotal,
                $amountExclusive,
                $vatAmount,
                $revenueGlAccountId ?: null
            );
        }

        if (in_array($paymentMode, $immediatePaymentModes, true) && $depositAccountId > 0) {
            if (function_exists('invoice_gl_post_revenue_payment')) {
                invoice_gl_post_revenue_payment(
                    $pdo,
                    $entryId,
                    $voucherNumber,
                    $entryDate,
                    $amountTotal,
                    $depositAccountId
                );
            }
            if (function_exists('recordTransaction')) {
                $description = "Revenue: $voucherNumber - $customerName ($narration)";
                recordTransaction($depositAccountId, 'credit', $amountTotal, $description, 'revenue_entry', $entryId, $entryDate);
            }
        }

        $pdo->commit();

        return [
            'ok' => true,
            'entry_id' => $entryId,
            'voucher_number' => $voucherNumber,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'quantity' => (float) ($row['quantity'] ?? 0),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('revenue_import_save_entry: ' . $e->getMessage());

        return ['ok' => false, 'errors' => ['Could not save entry. ' . $e->getMessage()]];
    }
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function revenue_import_commit_rows(PDO $pdo, array $rows, array $options): array
{
    $imported = 0;
    $customersCreated = 0;
    $productsCreated = 0;
    $failed = [];
    $ids = [];

    foreach ($rows as $row) {
        if (empty($row['ok'])) {
            continue;
        }

        $customerResult = revenue_import_upsert_customer(
            $pdo,
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['tin'] ?? ''),
            (string) ($row['vrn'] ?? '')
        );
        if (!empty($customerResult['error']) && (int) ($customerResult['id'] ?? 0) <= 0 && revenue_import_table_exists($pdo, 'customers')) {
            $failed[] = [
                'row' => (int) ($row['row'] ?? 0),
                'error' => 'Customer: ' . (string) $customerResult['error'],
            ];
            continue;
        }
        if (!empty($customerResult['created'])) {
            $customersCreated++;
        }

        $productResult = revenue_import_upsert_product(
            $pdo,
            (string) ($row['product_name'] ?? ''),
            (float) ($row['unit_price'] ?? 0)
        );
        if (!empty($productResult['error']) && (int) ($productResult['id'] ?? 0) <= 0 && revenue_import_table_exists($pdo, 'products')) {
            $failed[] = [
                'row' => (int) ($row['row'] ?? 0),
                'error' => 'Product: ' . (string) $productResult['error'],
            ];
            continue;
        }
        if (!empty($productResult['created'])) {
            $productsCreated++;
        }

        $created = revenue_import_create_entry($pdo, $row, $options);
        if (empty($created['ok'])) {
            $failed[] = [
                'row' => (int) ($row['row'] ?? 0),
                'error' => implode(' ', $created['errors'] ?? ['Save failed']),
            ];
            continue;
        }

        $imported++;
        if (!empty($created['entry_id'])) {
            $ids[] = (int) $created['entry_id'];
        }
    }

    if ($imported === 0 && $failed !== []) {
        return [
            'ok' => false,
            'message' => 'No rows were imported. ' . ($failed[0]['error'] ?? 'Check your file and try again.'),
            'failed' => $failed,
            'imported' => 0,
            'customers_created' => $customersCreated,
            'products_created' => $productsCreated,
        ];
    }

    return [
        'ok' => true,
        'imported' => $imported,
        'customers_created' => $customersCreated,
        'products_created' => $productsCreated,
        'failed' => $failed,
        'ids' => $ids,
        'message' => sprintf(
            'Imported %d revenue entr%s. %d new customer%s, %d new product%s.',
            $imported,
            $imported === 1 ? 'y' : 'ies',
            $customersCreated,
            $customersCreated === 1 ? '' : 's',
            $productsCreated,
            $productsCreated === 1 ? '' : 's'
        ),
    ];
}
