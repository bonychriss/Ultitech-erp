<?php

declare(strict_types=1);

/**
 * Expense spreadsheet import helpers (CSV / XLSX).
 */

/**
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<list<string>>}
 */
function expenses_import_read_upload(array $file): array
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
        return expenses_import_read_csv($tmp);
    }
    if ($ext === 'xlsx') {
        return expenses_import_read_xlsx($tmp);
    }

    return [
        'ok' => false,
        'message' => 'Unsupported file type. Upload a .xlsx or .csv file.',
    ];
}

/**
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<list<string>>}
 */
function expenses_import_read_csv(string $path): array
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

    return expenses_import_matrix_to_table($matrix);
}

/**
 * Minimal first-sheet XLSX reader (shared strings + inline strings).
 *
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<list<string>>}
 */
function expenses_import_read_xlsx(string $path): array
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

    return expenses_import_matrix_to_table($matrix);
}

/**
 * @return list<string>
 */
function expenses_import_parse_shared_strings(string $xml): array
{
    $out = [];
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return [];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    foreach ($doc->getElementsByTagName('si') as $si) {
        $text = '';
        foreach ($si->getElementsByTagName('t') as $t) {
            $text .= (string) $t->textContent;
        }
        $out[] = $text;
    }

    return $out;
}

function expenses_import_xlsx_first_sheet_path(ZipArchive $zip): ?string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbook) || !is_string($rels) || $workbook === '' || $rels === '') {
        return 'xl/worksheets/sheet1.xml';
    }

    $prev = libxml_use_internal_errors(true);
    $wb = new DOMDocument();
    $rl = new DOMDocument();
    $okWb = $wb->loadXML($workbook);
    $okRl = $rl->loadXML($rels);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$okWb || !$okRl) {
        return 'xl/worksheets/sheet1.xml';
    }

    $rid = '';
    foreach ($wb->getElementsByTagName('sheet') as $sheet) {
        if ($sheet instanceof DOMElement) {
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($rid === '') {
                $rid = $sheet->getAttribute('r:id');
            }
            break;
        }
    }

    $target = '';
    foreach ($rl->getElementsByTagName('Relationship') as $rel) {
        if ($rel instanceof DOMElement && $rel->getAttribute('Id') === $rid) {
            $target = str_replace('\\', '/', $rel->getAttribute('Target'));
            break;
        }
    }

    if ($target === '') {
        return 'xl/worksheets/sheet1.xml';
    }
    if (str_starts_with($target, '/')) {
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'xl/')) {
            return $target;
        }

        return 'xl/' . ltrim($target, '/');
    }

    return 'xl/' . ltrim($target, '/');
}

/**
 * @param list<string> $shared
 * @return list<list<string>>
 */
function expenses_import_parse_sheet_matrix(string $xml, array $shared): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return [];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $grid = [];
    $maxCol = 0;
    foreach ($doc->getElementsByTagName('c') as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        $ref = $cell->getAttribute('r');
        if ($ref === '' || !preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
            continue;
        }
        $col = expenses_import_col_letters_to_index($m[1]);
        $row = (int) $m[2];
        $maxCol = max($maxCol, $col);
        $type = $cell->getAttribute('t');
        $value = '';
        if ($type === 's') {
            $v = $cell->getElementsByTagName('v')->item(0);
            $idx = $v ? (int) $v->textContent : -1;
            $value = $shared[$idx] ?? '';
        } elseif ($type === 'inlineStr') {
            $t = $cell->getElementsByTagName('t')->item(0);
            $value = $t ? (string) $t->textContent : '';
        } else {
            $v = $cell->getElementsByTagName('v')->item(0);
            $value = $v ? (string) $v->textContent : '';
        }
        $grid[$row][$col] = trim($value);
    }

    if ($grid === []) {
        return [];
    }

    ksort($grid);
    $matrix = [];
    foreach ($grid as $rowCells) {
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $line[] = (string) ($rowCells[$c] ?? '');
        }
        $matrix[] = $line;
    }

    return $matrix;
}

function expenses_import_col_letters_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $n - 1);
}

/**
 * @param list<list<string>> $matrix
 * @return array{ok:bool,message?:string,headers?:list<string>,rows?:list<array{row:int,cells:list<string>}>,header_row?:int}
 */
function expenses_import_matrix_to_table(array $matrix): array
{
    $headerIdx = null;
    $headers = [];
    foreach ($matrix as $i => $row) {
        $normalized = array_map('expenses_import_normalize_header', $row);
        if (expenses_import_headers_look_valid($normalized)) {
            $headerIdx = $i;
            $headers = $normalized;
            break;
        }
    }

    if ($headerIdx === null) {
        return [
            'ok' => false,
            'message' => 'Could not find a header row with DATE, DESCRIPTION, and AMOUNT columns.',
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

function expenses_import_normalize_header(string $header): string
{
    $h = strtolower(trim($header));
    $h = str_replace(["\xc2\xa0", '_'], [' ', ' '], $h);
    $h = preg_replace('/\s+/', ' ', $h) ?? $h;

    return $h;
}

/**
 * @param list<string> $headers
 */
function expenses_import_headers_look_valid(array $headers): bool
{
    $map = expenses_import_header_map($headers);

    return isset($map['date'], $map['amount'])
        && (isset($map['expense_account']) || isset($map['description']));
}

/**
 * @param list<string> $headers
 * @return array<string,int>
 */
function expenses_import_header_map(array $headers): array
{
    $map = [];
    foreach ($headers as $i => $header) {
        $h = expenses_import_normalize_header((string) $header);
        if (in_array($h, ['date', 'expense date', 'txn date', 'transaction date'], true) && !isset($map['date'])) {
            $map['date'] = $i;
            continue;
        }
        if (in_array($h, ['amount', 'total', 'total amount', 'gross', 'gross amount'], true) && !isset($map['amount'])) {
            $map['amount'] = $i;
            continue;
        }
        if (
            in_array($h, ['vat exclusive', 'vat excl', 'vat exclusive amount', 'exclusive', 'net', 'net amount', 'amount excl vat'], true)
            && !isset($map['vat_exclusive'])
        ) {
            $map['vat_exclusive'] = $i;
            continue;
        }
        // Finance sheets often labelled this "DESCRIPTION" but values are expense sub-accounts.
        if (
            in_array($h, [
                'expense sub-account',
                'expense sub account',
                'expense subaccount',
                'sub-account',
                'sub account',
                'subaccount',
                'expense account',
                'account',
                'account name',
                'category',
                'expense category',
                'expense type',
                'gl account',
                'ledger account',
                'chart of account',
                'coa',
                'description',
                'desc',
                'details',
                'particulars',
                'narration',
            ], true)
            && !isset($map['expense_account'])
        ) {
            $map['expense_account'] = $i;
            // Keep a description pointer on the same column for narration / legacy sheets.
            if (!isset($map['description'])) {
                $map['description'] = $i;
            }
            continue;
        }
        if (
            in_array($h, ['paid from', 'paid from account', 'payment account', 'source account', 'bank account', 'cash account', 'bank/cash account', 'bank cash account', 'funded from', 'paid by account'], true)
            && !isset($map['source_account'])
        ) {
            $map['source_account'] = $i;
            continue;
        }
        if (
            in_array($h, ['payment method', 'payment mode', 'paid via', 'pay method', 'method', 'mode of payment'], true)
            && !isset($map['payment_method'])
        ) {
            $map['payment_method'] = $i;
            continue;
        }
        if (in_array($h, ['currency', 'currency code', 'ccy'], true) && !isset($map['currency'])) {
            $map['currency'] = $i;
            continue;
        }
        if (in_array($h, ['payee', 'paid to', 'vendor', 'supplier', 'payee name', 'beneficiary'], true) && !isset($map['payee'])) {
            $map['payee'] = $i;
            continue;
        }
        if (in_array($h, ['reference', 'ref', 'ref no', 'reference no', 'receipt no', 'invoice no', 'document no'], true) && !isset($map['reference'])) {
            $map['reference'] = $i;
        }
    }

    return $map;
}

/**
 * Column headers the importer understands, in template order.
 *
 * @return list<array{key:string,label:string,required:bool,hint:string}>
 */
function expenses_import_template_columns(): array
{
    return [
        ['key' => 'date', 'label' => 'DATE', 'required' => true, 'hint' => '7-Apr, 07/04/2026 or 2026-04-07'],
        ['key' => 'expense_account', 'label' => 'EXPENSE ACCOUNT', 'required' => true, 'hint' => 'Chart of Accounts expense account name (e.g. Fuel, Transport)'],
        ['key' => 'amount', 'label' => 'AMOUNT', 'required' => true, 'hint' => 'Gross amount paid (VAT inclusive)'],
        ['key' => 'vat_exclusive', 'label' => 'VAT EXCLUSIVE', 'required' => false, 'hint' => 'Net amount; VAT = AMOUNT - VAT EXCLUSIVE'],
    ];
}

/**
 * @return list<string>
 */
function expenses_import_template_headers(): array
{
    return array_map(static fn (array $c) => $c['label'], expenses_import_template_columns());
}

function expenses_import_normalize_account_key(string $name): string
{
    $n = strtolower(trim($name));
    $n = str_replace("\xc2\xa0", ' ', $n);
    $n = preg_replace('/\s+/', ' ', $n) ?? $n;

    return trim($n);
}

/**
 * Variants of an account name so "5100 - FUEL", "Expenses / FUEL", "fuel" and "5100" all match.
 *
 * @return list<string>
 */
function expenses_import_account_key_variants(string $name): array
{
    $base = expenses_import_normalize_account_key($name);
    if ($base === '') {
        return [];
    }

    $keys = [$base => true];
    $pieces = [$base];

    if (str_contains($base, '/')) {
        foreach (explode('/', $base) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $keys[$part] = true;
                $pieces[] = $part;
            }
        }
    }

    foreach ($pieces as $piece) {
        if (preg_match('/^([0-9]{2,10})\s*[-\x{2013}\x{2014}:.]\s*(.+)$/u', $piece, $m)) {
            $keys[trim($m[1])] = true;
            $keys[trim($m[2])] = true;
        }
    }

    foreach (array_keys($keys) as $key) {
        $loose = preg_replace('/[^a-z0-9]/', '', (string) $key) ?? '';
        if ($loose !== '') {
            $keys['~' . $loose] = true;
        }
    }

    return array_map('strval', array_keys($keys));
}

/**
 * @param list<array<string,mixed>> $options
 * @return array<string,array{id:int,hits:int}>
 */
function expenses_import_build_account_index(array $options): array
{
    $index = [];
    foreach ($options as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $names = [];
        foreach (['label', 'name'] as $field) {
            $value = trim((string) ($option[$field] ?? ''));
            if ($value !== '') {
                $names[$value] = true;
            }
        }
        foreach (array_keys($names) as $name) {
            foreach (expenses_import_account_key_variants($name) as $key) {
                if (!isset($index[$key])) {
                    $index[$key] = ['id' => $id, 'hits' => 1];
                } elseif ($index[$key]['id'] !== $id) {
                    $index[$key]['hits']++;
                }
            }
        }
    }

    return $index;
}

/**
 * @param array<string,array{id:int,hits:int}> $index
 * @return array{id:int,ambiguous:bool}
 */
function expenses_import_lookup_account(array $index, string $raw): array
{
    foreach (expenses_import_account_key_variants($raw) as $key) {
        if (!isset($index[$key])) {
            continue;
        }
        if ($index[$key]['hits'] > 1) {
            return ['id' => 0, 'ambiguous' => true];
        }

        return ['id' => $index[$key]['id'], 'ambiguous' => false];
    }

    return ['id' => 0, 'ambiguous' => false];
}

/**
 * Account lookups + labels shared by preview and commit so both resolve names identically.
 *
 * @return array{
 *   expense_index:array<string,array{id:int,hits:int}>,
 *   payment_index:array<string,array{id:int,hits:int}>,
 *   expense_labels:array<int,string>,
 *   payment_labels:array<int,string>,
 *   payment_kinds:array<int,string>,
 *   expense_options:list<array<string,mixed>>,
 *   payment_options:list<array<string,mixed>>
 * }
 */
function expenses_import_build_account_context(PDO $pdo): array
{
    expenses_balances_bootstrap();

    $expenseOptions = expenses_fetch_expense_sub_accounts($pdo);
    $paymentOptions = expenses_fetch_payment_accounts($pdo);

    $expenseLabels = [];
    foreach ($expenseOptions as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id > 0) {
            $expenseLabels[$id] = (string) ($option['label'] ?? $option['name'] ?? '');
        }
    }

    $paymentLabels = [];
    $paymentKinds = [];
    foreach ($paymentOptions as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id > 0) {
            $paymentLabels[$id] = (string) ($option['label'] ?? $option['name'] ?? '');
            $paymentKinds[$id] = strtolower((string) ($option['kind'] ?? ''));
        }
    }

    return [
        'expense_index' => expenses_import_build_account_index($expenseOptions),
        'payment_index' => expenses_import_build_account_index($paymentOptions),
        'expense_labels' => $expenseLabels,
        'payment_labels' => $paymentLabels,
        'payment_kinds' => $paymentKinds,
        'expense_options' => $expenseOptions,
        'payment_options' => $paymentOptions,
    ];
}

/**
 * @return string '' when the value is present but not understood.
 */
function expenses_import_parse_payment_method(string $raw): string
{
    $m = strtolower(trim($raw));
    if ($m === '') {
        return '';
    }
    $m = str_replace(['-', '_', '/'], ' ', $m);
    $m = preg_replace('/\s+/', ' ', $m) ?? $m;

    $mobile = ['mobile', 'mpesa', 'm pesa', 'tigo', 'pesa', 'airtel', 'halopesa', 'mixx', 'yas', 'azampesa', 'wallet'];
    foreach ($mobile as $needle) {
        if (str_contains($m, $needle)) {
            return 'mobile_money';
        }
    }

    $bank = ['bank', 'transfer', 'eft', 'cheque', 'check', 'swift', 'tiss', 'card', 'pos'];
    foreach ($bank as $needle) {
        if (str_contains($m, $needle)) {
            return 'bank_transfer';
        }
    }

    if (str_contains($m, 'cash')) {
        return 'cash';
    }

    return '';
}

/**
 * erp_expenses stores only 'cash' and 'mobile_money'; bank transfers share the mobile_money value
 * (see expenses_payment_method_label), so keep imports on the same convention.
 */
function expenses_import_normalize_stored_method(string $method): string
{
    $m = strtolower(trim($method));
    if ($m === '' || $m === 'cash') {
        return 'cash';
    }
    if (in_array($m, ['bank_transfer', 'bank', 'mobile', 'mobile_money'], true)) {
        return 'mobile_money';
    }

    $parsed = expenses_import_parse_payment_method($m);

    return $parsed === 'cash' || $parsed === '' ? 'cash' : 'mobile_money';
}

function expenses_import_method_for_account_kind(string $kind): string
{
    return match (strtolower($kind)) {
        'bank' => 'bank_transfer',
        'mobile' => 'mobile_money',
        'cash' => 'cash',
        default => '',
    };
}

/**
 * @return string '' when the value is present but not a usable currency code.
 */
function expenses_import_parse_currency(string $raw): string
{
    $c = strtoupper(trim($raw));
    if ($c === '') {
        return '';
    }
    $c = preg_replace('/[^A-Z]/', '', $c) ?? $c;
    if ($c === '') {
        return '';
    }
    $aliases = [
        'TSH' => 'TZS',
        'TZSH' => 'TZS',
        'SHILLING' => 'TZS',
        'USDOLLAR' => 'USD',
        'DOLLAR' => 'USD',
        'EURO' => 'EUR',
        'POUND' => 'GBP',
    ];
    $c = $aliases[$c] ?? $c;
    if (!preg_match('/^[A-Z]{3}$/', $c)) {
        return '';
    }

    if (function_exists('expenses_currency_display_code')) {
        return expenses_currency_display_code($c);
    }

    return $c;
}

function expenses_import_parse_number(string $raw): ?float
{
    $v = trim($raw);
    if ($v === '') {
        return null;
    }
    // Excel may give plain numbers; strip currency/thousands separators.
    $v = str_replace(["\xc2\xa0", ' '], '', $v);
    $v = preg_replace('/[^0-9,.\-]/', '', $v) ?? $v;
    if ($v === '' || $v === '-' || $v === '.' || $v === ',') {
        return null;
    }
    if (str_contains($v, ',') && str_contains($v, '.')) {
        $v = str_replace(',', '', $v);
    } elseif (str_contains($v, ',') && !str_contains($v, '.')) {
        // 20000,00 (EU) vs 20,000 (US thousands): treat as thousands if 3 digits after comma.
        if (preg_match('/^\-?\d{1,3}(,\d{3})+$/', $v)) {
            $v = str_replace(',', '', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }
    }
    if (!is_numeric($v)) {
        return null;
    }

    return round((float) $v, 2);
}

/**
 * Parse dates like 7-Apr, 07/04/2026, Excel serials, Y-m-d.
 */
function expenses_import_parse_date(string $raw, int $defaultYear): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw)) {
        $serial = (float) $raw;
        if ($serial > 20000 && $serial < 80000) {
            // Excel serial date (days since 1899-12-30).
            $ts = (int) (($serial - 25569) * 86400);
            if ($ts > 0) {
                return gmdate('Y-m-d', $ts);
            }
        }
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $raw, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    // 7-Apr / 07 Apr / 7-April / Apr-7
    $months = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    if (preg_match('/^(\d{1,2})[\s\-\/.]+([A-Za-z]+)(?:[\s\-\/.]+(\d{2,4}))?$/', $raw, $m)) {
        $day = (int) $m[1];
        $monKey = strtolower($m[2]);
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $defaultYear;
        if ($year < 100) {
            $year += 2000;
        }
        $month = $months[$monKey] ?? 0;
        if ($month > 0 && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    if (preg_match('/^([A-Za-z]+)[\s\-\/.]+(\d{1,2})(?:[\s\-\/.]+(\d{2,4}))?$/', $raw, $m)) {
        $monKey = strtolower($m[1]);
        $day = (int) $m[2];
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $defaultYear;
        if ($year < 100) {
            $year += 2000;
        }
        $month = $months[$monKey] ?? 0;
        if ($month > 0 && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 * @return list<array{row:int,date:string,description:string,amount:float,vat_exclusive:float,tax_amount:float,ok:bool,error:string}>
 */
/**
 * @param list<string> $headers
 * @param list<array{row?:int,cells?:list<string>}|list<string>> $rows
 * @return list<array{row:int,date:string,description:string,amount:float,vat_exclusive:float,tax_amount:float,ok:bool,error:string}>
 */
function expenses_import_map_rows(
    array $headers,
    array $rows,
    int $defaultYear,
    int $headerRowNumber = 1,
    ?array $accountCtx = null
): array {
    $map = expenses_import_header_map($headers);
    $expenseIndex = $accountCtx['expense_index'] ?? [];
    $paymentIndex = $accountCtx['payment_index'] ?? [];
    $expenseLabels = $accountCtx['expense_labels'] ?? [];
    $paymentLabels = $accountCtx['payment_labels'] ?? [];
    $paymentKinds = $accountCtx['payment_kinds'] ?? [];
    $out = [];
    foreach ($rows as $i => $entry) {
        if (isset($entry['cells']) && is_array($entry['cells'])) {
            $cells = $entry['cells'];
            $excelRow = (int) ($entry['row'] ?? ($headerRowNumber + 1 + $i));
        } else {
            $cells = is_array($entry) ? $entry : [];
            $excelRow = $headerRowNumber + 1 + $i;
        }
        $dateRaw = (string) ($cells[$map['date']] ?? '');
        $amountRaw = (string) ($cells[$map['amount']] ?? '');
        $vatRaw = isset($map['vat_exclusive']) ? (string) ($cells[$map['vat_exclusive']] ?? '') : '';

        // EXPENSE ACCOUNT (or legacy DESCRIPTION / EXPENSE SUB-ACCOUNT) is the COA expense account name.
        $accountRaw = '';
        if (isset($map['expense_account'])) {
            $accountRaw = trim((string) ($cells[$map['expense_account']] ?? ''));
        }
        if ($accountRaw === '' && isset($map['description'])) {
            $accountRaw = trim((string) ($cells[$map['description']] ?? ''));
        }

        $date = expenses_import_parse_date($dateRaw, $defaultYear);
        $amount = expenses_import_parse_number($amountRaw);
        $vatExclusive = $vatRaw !== '' ? expenses_import_parse_number($vatRaw) : $amount;

        $sourceRaw = isset($map['source_account']) ? trim((string) ($cells[$map['source_account']] ?? '')) : '';
        $methodRaw = isset($map['payment_method']) ? trim((string) ($cells[$map['payment_method']] ?? '')) : '';
        $currencyRaw = isset($map['currency']) ? trim((string) ($cells[$map['currency']] ?? '')) : '';
        $payee = isset($map['payee']) ? trim((string) ($cells[$map['payee']] ?? '')) : '';
        $reference = isset($map['reference']) ? trim((string) ($cells[$map['reference']] ?? '')) : '';

        $error = '';
        if ($date === null) {
            $error = 'Invalid date' . ($dateRaw !== '' ? " ({$dateRaw})" : '');
        } elseif ($accountRaw === '') {
            $error = 'Missing expense account';
        } elseif ($amount === null || $amount <= 0) {
            $error = 'Invalid amount';
        } elseif ($vatExclusive === null || $vatExclusive < 0) {
            $error = 'Invalid VAT exclusive amount';
        } elseif ($vatExclusive !== null && $amount !== null && $vatExclusive > $amount + 0.009) {
            $error = 'VAT exclusive cannot be greater than amount';
        }

        $accountId = 0;
        if ($accountRaw !== '') {
            $hit = expenses_import_lookup_account($expenseIndex, $accountRaw);
            $accountId = $hit['id'];
            if ($accountId <= 0 && $error === '' && !empty($hit['ambiguous'])) {
                $error = "Expense account \"{$accountRaw}\" matches more than one account";
            }
            // Unknown name: leave needs_account so AI / the user can pick in the preview.
        }

        // Narration defaults to the account name (what finance used to call description).
        $description = $accountId > 0
            ? (string) ($expenseLabels[$accountId] ?? $accountRaw)
            : $accountRaw;

        $sourceAccountId = 0;
        if ($sourceRaw !== '') {
            $hit = expenses_import_lookup_account($paymentIndex, $sourceRaw);
            $sourceAccountId = $hit['id'];
            if ($sourceAccountId <= 0 && $error === '') {
                $error = $hit['ambiguous']
                    ? "Payment account \"{$sourceRaw}\" matches more than one account"
                    : "Unknown payment account \"{$sourceRaw}\"";
            }
        }

        $paymentMethod = expenses_import_parse_payment_method($methodRaw);
        if ($methodRaw !== '' && $paymentMethod === '' && $error === '') {
            $error = "Unknown payment method \"{$methodRaw}\"";
        }
        if ($paymentMethod === '' && $sourceAccountId > 0) {
            $paymentMethod = expenses_import_method_for_account_kind((string) ($paymentKinds[$sourceAccountId] ?? ''));
        }

        $currency = expenses_import_parse_currency($currencyRaw);
        if ($currencyRaw !== '' && $currency === '' && $error === '') {
            $error = "Unknown currency \"{$currencyRaw}\"";
        }

        $tax = 0.0;
        if ($error === '' && $amount !== null && $vatExclusive !== null) {
            $tax = max(0, round($amount - $vatExclusive, 2));
        }

        $out[] = [
            'row' => $excelRow,
            'date' => $date ?? '',
            'date_raw' => $dateRaw,
            'description' => $description,
            'payee' => $payee,
            'reference' => $reference,
            'amount' => $amount ?? 0.0,
            'vat_exclusive' => $vatExclusive ?? 0.0,
            'tax_amount' => $tax,
            'account_raw' => $accountRaw,
            'account_id' => $accountId,
            'account_label' => $accountId > 0 ? (string) ($expenseLabels[$accountId] ?? $accountRaw) : '',
            'source_raw' => $sourceRaw,
            'source_account_id' => $sourceAccountId,
            'source_label' => $sourceAccountId > 0 ? (string) ($paymentLabels[$sourceAccountId] ?? $sourceRaw) : '',
            'payment_method' => $paymentMethod,
            'payment_method_raw' => $methodRaw,
            'currency' => $currency,
            'currency_raw' => $currencyRaw,
            'needs_account' => $accountId <= 0,
            'needs_source' => $sourceAccountId <= 0,
            'ok' => $error === '',
            'error' => $error,
            'ai_reason' => $accountId > 0 ? 'Matched expense account from sheet' : '',
            'ai_confidence' => $accountId > 0 ? 1.0 : 0.0,
        ];
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows Mapped rows with ok=true
 * @return array{ok:bool,message?:string,imported?:int,ids?:list<int>}
 */
function expenses_import_commit_rows(
    PDO $pdo,
    array $rows,
    int $accountId,
    ?int $mainAccountId,
    int $sourceAccountId,
    string $paymentMethod,
    string $currencyCode,
    bool $postToLedger,
    int $userId,
    ?array $accountCtx = null
): array {
    expenses_ensure_schema($pdo);
    expenses_balances_bootstrap();

    // Import always saves drafts - balances stay unchanged until the user posts later.
    $postToLedger = false;

    $accountCtx ??= expenses_import_build_account_context($pdo);
    $expenseIndex = $accountCtx['expense_index'] ?? [];
    $paymentIndex = $accountCtx['payment_index'] ?? [];
    $paymentKinds = $accountCtx['payment_kinds'] ?? [];
    $expenseLabels = $accountCtx['expense_labels'] ?? [];

    if ($sourceAccountId <= 0) {
        return ['ok' => false, 'message' => 'Select the Paid from (bank/cash) account for this import.'];
    }

    $fallbackMethod = expenses_import_normalize_stored_method($paymentMethod);
    $fallbackCurrency = function_exists('expenses_currency_display_code')
        ? expenses_currency_display_code($currencyCode)
        : $currencyCode;

    $valid = array_values(array_filter($rows, static fn ($r) => !empty($r['ok'])));
    if ($valid === []) {
        return ['ok' => false, 'message' => 'No valid rows to import.'];
    }

    $sourceRow = expenses_resolve_financial_account($pdo, $sourceAccountId);
    if (!$sourceRow) {
        return ['ok' => false, 'message' => 'Payment account was not found.'];
    }
    if (expenses_is_petty_cash_account_row($sourceRow)) {
        return ['ok' => false, 'message' => 'Petty cash payments belong in the Petty Cash module, not Expenses.'];
    }

    $expenseAccountCache = [];
    $prepared = [];

    foreach ($valid as $row) {
        $rowNo = (int) ($row['row'] ?? 0);
        $label = $rowNo > 0 ? "Row {$rowNo}: " : '';

        $rowAccountId = (int) ($row['account_id'] ?? 0);
        $rowAccountRaw = trim((string) ($row['account_raw'] ?? ''));

        if ($rowAccountId <= 0 && $rowAccountRaw !== '') {
            $hit = expenses_import_lookup_account($expenseIndex, $rowAccountRaw);
            $rowAccountId = $hit['id'];
            if ($rowAccountId <= 0) {
                return ['ok' => false, 'message' => $label . "expense account \"{$rowAccountRaw}\" could not be matched."];
            }
        }

        if ($rowAccountId <= 0 && $accountId > 0) {
            $rowAccountId = $accountId;
        }

        if ($rowAccountId <= 0) {
            return ['ok' => false, 'message' => $label . 'pick an expense account (could not match this row from the sheet).'];
        }

        if (!array_key_exists($rowAccountId, $expenseAccountCache)) {
            // Accept any account offered in the import expense picker (including top-level COA rows).
            if (array_key_exists($rowAccountId, $expenseLabels)) {
                $expenseAccountCache[$rowAccountId] = true;
            } else {
                $expenseAccountCache[$rowAccountId] = expenses_validate_expense_sub_account(
                    $pdo,
                    $rowAccountId,
                    null
                );
            }
        }
        if (!$expenseAccountCache[$rowAccountId]) {
            return ['ok' => false, 'message' => $label . 'expense account selection is invalid.'];
        }

        $rowSourceId = $sourceAccountId;
        $rowSourceRaw = trim((string) ($row['source_raw'] ?? ''));
        if ($rowSourceRaw !== '') {
            $hit = expenses_import_lookup_account($paymentIndex, $rowSourceRaw);
            if ($hit['id'] > 0) {
                $rowSourceId = $hit['id'];
                $srcCheck = expenses_resolve_financial_account($pdo, $rowSourceId);
                if (!$srcCheck) {
                    return ['ok' => false, 'message' => $label . 'payment account was not found.'];
                }
                if (expenses_is_petty_cash_account_row($srcCheck)) {
                    return ['ok' => false, 'message' => $label . 'petty cash payments belong in the Petty Cash module, not Expenses.'];
                }
            }
        }

        $rowMethod = (string) ($row['payment_method'] ?? '');
        if ($rowMethod === '') {
            $rowMethod = expenses_import_method_for_account_kind((string) ($paymentKinds[$rowSourceId] ?? ''));
        }
        $rowMethod = $rowMethod !== ''
            ? expenses_import_normalize_stored_method($rowMethod)
            : $fallbackMethod;

        $rowCurrency = trim((string) ($row['currency'] ?? ''));
        if ($rowCurrency === '') {
            $rowCurrency = $fallbackCurrency;
        } elseif (function_exists('expenses_currency_display_code')) {
            $rowCurrency = expenses_currency_display_code($rowCurrency);
        }

        $payee = trim((string) ($row['payee'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        if ($reference !== '' && stripos($description, $reference) === false) {
            $description = $description . ' (Ref: ' . $reference . ')';
        }

        $prepared[] = [
            'date' => (string) ($row['date'] ?? ''),
            'description' => $description,
            'payee' => $payee !== '' ? $payee : null,
            'amount' => (float) ($row['amount'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            'account_id' => $rowAccountId,
            'source_account_id' => $rowSourceId,
            'payment_method' => $rowMethod,
            'currency_code' => $rowCurrency,
            'account_label' => (string) ($expenseLabels[$rowAccountId] ?? ''),
        ];
    }

    $valid = $prepared;

    $ids = [];
    $pdo->beginTransaction();
    try {
        $seq = 0;
        $dateStamp = date('Ymd');
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM erp_expenses WHERE expense_number LIKE ?");
        $countStmt->execute(["EXP-{$dateStamp}-%"]);
        $seq = (int) $countStmt->fetchColumn();

        foreach ($valid as $row) {
            $seq++;
            $expenseNumber = 'EXP-' . $dateStamp . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $amount = (float) $row['amount'];
            $tax = (float) ($row['tax_amount'] ?? 0);
            $date = (string) $row['date'];
            $description = (string) $row['description'];
            $payee = $row['payee'] ?? null;
            $rowAccountId = (int) $row['account_id'];
            $rowSourceId = (int) $row['source_account_id'];
            $rowMethod = (string) $row['payment_method'];
            $rowCurrency = (string) $row['currency_code'];

            if ($postToLedger) {
                $stmt = $pdo->prepare(
                    "INSERT INTO erp_expenses
                    (expense_number, date, payee, account_id, source_account_id, amount, tax_amount, currency_code, payment_method, description, status, is_posted, source_type, created_by, approved_by, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 0, 'receipt', ?, ?, NOW())"
                );
                $stmt->execute([
                    $expenseNumber,
                    $date,
                    $payee,
                    $rowAccountId,
                    $rowSourceId,
                    $amount,
                    $tax,
                    $rowCurrency,
                    $rowMethod,
                    $description,
                    $userId,
                    $userId,
                ]);
                $expenseId = (int) $pdo->lastInsertId();
                $postResult = expenses_post_erp_expense_row($pdo, $expenseId);
                if (empty($postResult['success'])) {
                    throw new RuntimeException((string) ($postResult['message'] ?? 'Could not post imported expense.'));
                }
                expenses_mark_expense_posted($pdo, $expenseId);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO erp_expenses
                    (expense_number, date, payee, account_id, source_account_id, amount, tax_amount, currency_code, payment_method, description, status, is_posted, source_type, created_by, attachment)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 0, 'receipt', ?, NULL)"
                );
                $stmt->execute([
                    $expenseNumber,
                    $date,
                    $payee,
                    $rowAccountId,
                    $rowSourceId,
                    $amount,
                    $tax,
                    $rowCurrency,
                    $rowMethod,
                    $description,
                    $userId,
                ]);
                $expenseId = (int) $pdo->lastInsertId();
            }
            $ids[] = $expenseId;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $count = count($ids);

    return [
        'ok' => true,
        'imported' => $count,
        'ids' => $ids,
        'message' => "Imported {$count} expense draft" . ($count === 1 ? '' : 's')
            . '. Balances are unchanged until you post.',
    ];
}

/**
 * Heuristic: match description words against expense account names.
 *
 * @param list<array<string,mixed>> $expenseOptions
 * @return array{id:int,label:string,confidence:float,reason:string}
 */
function expenses_import_heuristic_classify_description(string $description, array $expenseOptions): array
{
    $desc = expenses_import_normalize_account_key($description);
    if ($desc === '' || $expenseOptions === []) {
        return ['id' => 0, 'label' => '', 'confidence' => 0.0, 'reason' => ''];
    }

    $bestId = 0;
    $bestLabel = '';
    $bestScore = 0.0;
    $bestReason = '';

    foreach ($expenseOptions as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $label = trim((string) ($option['label'] ?? $option['name'] ?? ''));
        $name = trim((string) ($option['name'] ?? ''));
        $candidates = array_unique(array_filter([$label, $name]));

        foreach ($candidates as $candidate) {
            $norm = expenses_import_normalize_account_key($candidate);
            if ($norm === '') {
                continue;
            }

            $score = 0.0;
            $reason = '';

            if ($desc === $norm || str_contains($desc, $norm) || str_contains($norm, $desc)) {
                $score = 0.85;
                $reason = 'Matched account name in description';
            } else {
                // Compare significant tokens (skip short noise + leading codes).
                $descTokens = preg_split('/[^a-z0-9]+/', $desc) ?: [];
                $acctTokens = preg_split('/[^a-z0-9]+/', $norm) ?: [];
                $acctTokens = array_values(array_filter($acctTokens, static function ($t) {
                    return strlen($t) >= 3 && !preg_match('/^\d+$/', $t);
                }));
                $hits = 0;
                foreach ($acctTokens as $token) {
                    if (in_array($token, $descTokens, true) || str_contains($desc, $token)) {
                        $hits++;
                    }
                }
                if ($hits > 0 && $acctTokens !== []) {
                    $score = 0.55 + (0.25 * ($hits / count($acctTokens)));
                    $reason = 'Keyword match on account name';
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $id;
                $bestLabel = $label !== '' ? $label : $name;
                $bestReason = $reason;
            }
        }
    }

    if ($bestId <= 0 || $bestScore < 0.55) {
        return ['id' => 0, 'label' => '', 'confidence' => 0.0, 'reason' => ''];
    }

    return [
        'id' => $bestId,
        'label' => $bestLabel,
        'confidence' => round(min(0.95, $bestScore), 2),
        'reason' => $bestReason,
    ];
}

/**
 * @param list<array<string,mixed>> $expenseOptions
 * @return array<int,string>
 */
function expenses_import_expense_catalog_map(array $expenseOptions): array
{
    $map = [];
    foreach ($expenseOptions as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = (string) ($option['label'] ?? $option['name'] ?? '');
    }

    return $map;
}

/**
 * Ask AI to map descriptions to expense account ids. Falls back to heuristics when AI is unavailable.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{ok:bool,via_ai:bool,message?:string,rows:list<array<string,mixed>>}
 */
function expenses_import_ai_classify_rows(PDO $pdo, array $rows, ?array $accountCtx = null): array
{
    expenses_balances_bootstrap();
    $accountCtx ??= expenses_import_build_account_context($pdo);
    $expenseOptions = $accountCtx['expense_options'] ?? [];
    $catalog = expenses_import_expense_catalog_map($expenseOptions);
    $labels = $accountCtx['expense_labels'] ?? $catalog;

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $copy = $row;
        $copy['ai_reason'] = (string) ($copy['ai_reason'] ?? '');
        $copy['ai_confidence'] = isset($copy['ai_confidence']) ? (float) $copy['ai_confidence'] : 0.0;
        $out[] = $copy;
    }

    if ($out === [] || $catalog === []) {
        return [
            'ok' => true,
            'via_ai' => false,
            'message' => $catalog === [] ? 'No expense accounts found in the Chart of Accounts.' : null,
            'rows' => $out,
        ];
    }

    $needs = [];
    foreach ($out as $i => $row) {
        if (empty($row['ok'])) {
            continue;
        }
        if ((int) ($row['account_id'] ?? 0) > 0) {
            continue;
        }
        $needs[$i] = $row;
    }

    if ($needs === []) {
        return ['ok' => true, 'via_ai' => false, 'rows' => $out];
    }

    $aiHelpers = dirname(__DIR__, 3) . '/includes/ai_helpers.php';
    if (is_file($aiHelpers)) {
        require_once $aiHelpers;
    }
    if (!function_exists('balances_ai_is_connected')) {
        $balancesFn = dirname(__DIR__) . '/../balances/functions.php';
        // balances_integration already bootstraps balances; connection helper lives there.
    }

    $viaAi = false;
    $aiAvailable = function_exists('balances_ai_is_connected')
        && balances_ai_is_connected()
        && function_exists('ai_openai_request');

    if ($aiAvailable) {
        $catalogLines = [];
        foreach ($catalog as $id => $label) {
            $catalogLines[] = $id . ': ' . $label;
        }
        $catalogText = implode("\n", $catalogLines);
        $batchSize = 40;
        $indices = array_keys($needs);
        $chunks = array_chunk($indices, $batchSize);
        $promptTokens = 0;
        $completionTokens = 0;

        try {
            foreach ($chunks as $chunkIndices) {
                $items = [];
                foreach ($chunkIndices as $idx) {
                    $items[] = [
                        'row' => (int) ($out[$idx]['row'] ?? 0),
                        'description' => (string) ($out[$idx]['description'] ?? ''),
                        'amount' => (float) ($out[$idx]['amount'] ?? 0),
                    ];
                }

                $messages = [
                    [
                        'role' => 'system',
                        'content' => 'You map expense account names from a spreadsheet onto a Chart of Accounts. '
                            . 'Each row "description" field is the expense account name the finance team wrote. '
                            . 'Reply with ONLY a JSON array (no markdown). Each element: '
                            . '{"row":number,"account_id":number|null,"confidence":0-1,"reason":"short"}. '
                            . 'Use only account_id values from the provided catalog. '
                            . 'Prefer exact or close name matches. If unsure, set account_id to null.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Expense accounts (id: name):\n{$catalogText}\n\n"
                            . "Map these spreadsheet expense account names:\n" . json_encode($items, JSON_UNESCAPED_UNICODE),
                    ],
                ];

                $result = ai_openai_request($messages);
                $promptTokens += (int) ($result['prompt_tokens'] ?? 0);
                $completionTokens += (int) ($result['completion_tokens'] ?? 0);
                $content = trim((string) ($result['content'] ?? ''));
                $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
                $content = preg_replace('/\s*```$/', '', $content) ?? $content;

                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $byRow = [];
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $rowNo = (int) ($item['row'] ?? 0);
                    if ($rowNo > 0) {
                        $byRow[$rowNo] = $item;
                    }
                }

                foreach ($chunkIndices as $idx) {
                    $rowNo = (int) ($out[$idx]['row'] ?? 0);
                    $item = $byRow[$rowNo] ?? null;
                    if (!is_array($item)) {
                        continue;
                    }
                    $accountId = (int) ($item['account_id'] ?? 0);
                    if ($accountId <= 0 || !isset($catalog[$accountId])) {
                        continue;
                    }
                    $out[$idx]['account_id'] = $accountId;
                    $out[$idx]['account_label'] = (string) ($labels[$accountId] ?? $catalog[$accountId]);
                    $out[$idx]['description'] = $out[$idx]['account_label'];
                    $out[$idx]['needs_account'] = false;
                    $out[$idx]['ai_confidence'] = max(0, min(1, (float) ($item['confidence'] ?? 0.7)));
                    $out[$idx]['ai_reason'] = trim((string) ($item['reason'] ?? 'AI suggestion'));
                    $viaAi = true;
                }
            }

            if ($viaAi && function_exists('ai_log_usage')) {
                $companyId = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
                $userId = (int) ($_SESSION['user_id'] ?? 0);
                $cost = function_exists('ai_estimate_cost')
                    ? ai_estimate_cost($promptTokens, $completionTokens, '')
                    : 0.0;
                ai_log_usage($companyId, $userId, 'expenses', 'import_classify', $promptTokens, $completionTokens, $cost);
            }
        } catch (Throwable $e) {
            error_log('expenses_import_ai_classify_rows: ' . $e->getMessage());
            $viaAi = false;
        }
    }

    // Heuristic fallback for anything still unmapped.
    foreach ($out as $i => $row) {
        if (empty($row['ok']) || (int) ($row['account_id'] ?? 0) > 0) {
            continue;
        }
        $needle = trim((string) ($row['account_raw'] ?? ''));
        if ($needle === '') {
            $needle = trim((string) ($row['description'] ?? ''));
        }
        $hit = expenses_import_heuristic_classify_description($needle, $expenseOptions);
        if ($hit['id'] <= 0) {
            continue;
        }
        $out[$i]['account_id'] = $hit['id'];
        $out[$i]['account_label'] = $hit['label'];
        $out[$i]['description'] = $hit['label'] !== '' ? $hit['label'] : (string) ($row['account_raw'] ?? $row['description'] ?? '');
        $out[$i]['needs_account'] = false;
        $out[$i]['ai_confidence'] = $hit['confidence'];
        $out[$i]['ai_reason'] = $hit['reason'] !== '' ? $hit['reason'] : 'Matched expense account';
    }

    $stillMissing = 0;
    foreach ($out as $row) {
        if (!empty($row['ok']) && (int) ($row['account_id'] ?? 0) <= 0) {
            $stillMissing++;
        }
    }

    $message = null;
    if (!$viaAi && !($aiAvailable ?? false)) {
        $message = 'AI is not configured; accounts were suggested from description keywords where possible.';
    } elseif ($stillMissing > 0) {
        $message = "{$stillMissing} row" . ($stillMissing === 1 ? '' : 's')
            . ' still need an expense account - pick one in the preview.';
    }

    return [
        'ok' => true,
        'via_ai' => $viaAi,
        'message' => $message,
        'rows' => array_values($out),
    ];
}
