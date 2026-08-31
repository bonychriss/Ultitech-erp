<?php

/**
 * @return array<string, string> ISO-style code => label for select options
 */
function dispatch_route_currency_options(): array
{
    return [
        'TZS' => 'TZS',
        'USD' => 'USD ($)',
        'EUR' => 'EUR ()',
        'GBP' => 'GBP ()',
        'CNY' => 'CNY ()',
        'JPY' => 'JPY ()',
        'AUD' => 'AUD (A$)',
        'CAD' => 'CAD (C$)',
        'INR' => 'INR (?)',
        'AED' => 'AED',
        'HKD' => 'HKD (HK$)',
    ];
}

function normalize_dispatch_route_currency(string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $code));
    if (strlen($code) !== 3) {
        return 'TZS';
    }

    return array_key_exists($code, dispatch_route_currency_options()) ? $code : 'TZS';
}

/** Prefix for display before a formatted amount (e.g. "TZS ", "$"). */
function dispatch_route_currency_prefix(string $code): string
{
    $c = normalize_dispatch_route_currency($code);
    switch ($c) {
        case 'USD':
            return '$';
        case 'EUR':
            return '';
        case 'GBP':
            return '';
        case 'CNY':
        case 'JPY':
            return '';
        case 'TZS':
            return 'TZS ';
        case 'AUD':
            return 'A$';
        case 'CAD':
            return 'C$';
        case 'INR':
            return '?';
        case 'HKD':
            return 'HK$';
        default:
            return $c . ' ';
    }
}

function dispatch_route_format_price_display(float $amount, string $currencyCode): string
{
    return dispatch_route_currency_prefix($currencyCode) . number_format($amount, 2);
}

function dispatch_module_query(): string
{
    $qs = 'module=dispatch';
    $slug = function_exists('getRequestedCompanySlug') ? strtolower(trim(getRequestedCompanySlug())) : '';
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($slug !== '') {
        $qs .= '&company_slug=' . rawurlencode($slug);
    }
    return $qs;
}

function dispatch_module_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $slug = function_exists('getRequestedCompanySlug') ? strtolower(trim(getRequestedCompanySlug())) : '';
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }

    if ($slug !== '' && function_exists('company_url')) {
        $base = company_url($relativePath, $slug);
    } elseif (function_exists('app_url')) {
        $base = app_url('/' . $relativePath);
    } else {
        $base = '/' . $relativePath;
    }

    return $base . (strpos($base, '?') !== false ? '&' : '?') . dispatch_module_query();
}

function ensure_dispatch_notes_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_number VARCHAR(50) NOT NULL,
            dispatch_date DATE NOT NULL,
            dispatch_from VARCHAR(255) NULL,
            dispatch_to VARCHAR(255) NULL,
            route_price DECIMAL(12,2) NULL,
            address_to VARCHAR(255) NOT NULL,
            contents TEXT NOT NULL,
            created_by INT NOT NULL,
            signature_path VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_routes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            route_from VARCHAR(255) NOT NULL,
            route_to VARCHAR(255) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price_currency VARCHAR(3) NOT NULL DEFAULT 'TZS',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Throwable $e) {
        error_log('ensure_dispatch_notes_schema: ' . $e->getMessage());
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM dispatch_notes')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('dispatch_from', $cols, true)) {
            $pdo->exec('ALTER TABLE dispatch_notes ADD COLUMN dispatch_from VARCHAR(255) NULL AFTER dispatch_date');
        }
        if (!in_array('dispatch_to', $cols, true)) {
            $pdo->exec('ALTER TABLE dispatch_notes ADD COLUMN dispatch_to VARCHAR(255) NULL AFTER dispatch_from');
        }
        if (!in_array('route_price', $cols, true)) {
            $pdo->exec('ALTER TABLE dispatch_notes ADD COLUMN route_price DECIMAL(12,2) NULL AFTER dispatch_to');
        }
        if (!in_array('type', $cols, true)) {
            $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN type ENUM('dispatch', 'trip') NOT NULL DEFAULT 'dispatch' AFTER id");
        }
    } catch (Throwable $e) {
        error_log('ensure_dispatch_notes_schema columns: ' . $e->getMessage());
    }
}

function dispatch_next_number(PDO $pdo): string
{
    if (!$pdo->inTransaction()) {
        ensure_dispatch_notes_schema($pdo);
    }

    $year = date('y');
    $prefix = "DN-$year-";
    try {
        $stmt = $pdo->prepare(
            'SELECT MAX(CAST(SUBSTRING(dispatch_number, ?) AS UNSIGNED))
             FROM dispatch_notes
             WHERE dispatch_number LIKE ?'
        );
        $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
        $maxNum = $stmt->fetchColumn();
        $nextNum = ($maxNum ? (int) $maxNum : 0) + 1;

        return $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return 'DN-' . date('y') . '-' . str_pad('1', 4, '0', STR_PAD_LEFT);
    }
}

/**
 * Create a dispatch note from delivery form fields (no invoice).
 *
 * @param array{pickup?:string,destination?:string,route_cost?:float|null,client_name?:string,description?:string} $delivery
 * @return array{ok:bool,error?:string,dispatchId?:int,dispatchNumber?:string}
 */
function dispatch_create_note_from_delivery(PDO $pdo, array $delivery, int $userId): array
{
    // DDL auto-commits in MySQL  never run schema ensures mid-transaction.
    if (!$pdo->inTransaction()) {
        ensure_dispatch_notes_schema($pdo);
        ensure_dispatch_routes_price_currency($pdo);
    }

    $from = trim((string) ($delivery['pickup'] ?? ''));
    $to = trim((string) ($delivery['destination'] ?? ''));
    if ($to === '') {
        return ['ok' => false, 'error' => 'Destination is required for dispatch.'];
    }
    if ($from === '') {
        $from = 'Not specified';
    }

    $contents = trim((string) ($delivery['description'] ?? ''));
    if ($contents === '') {
        $client = trim((string) ($delivery['client_name'] ?? ''));
        $contents = $client !== '' ? 'Delivery for ' . $client : 'Delivery order';
    }

    $routePrice = $delivery['route_cost'] ?? null;
    if ($routePrice === null && $from !== 'Not specified') {
        try {
            $stmtPrice = $pdo->prepare(
                'SELECT price FROM dispatch_routes WHERE route_from = ? AND route_to = ? LIMIT 1'
            );
            $stmtPrice->execute([$from, $to]);
            $found = $stmtPrice->fetchColumn();
            if ($found !== false && $found !== null && $found !== '') {
                $routePrice = (float) $found;
            }
        } catch (Throwable $e) {
            // Optional lookup only.
        }
    }

    $signaturePath = null;
    try {
        $stmtSig = $pdo->prepare('SELECT signature_path FROM users WHERE id = ?');
        $stmtSig->execute([$userId]);
        $signaturePath = $stmtSig->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $signaturePath = null;
    }

    $dispatchNumber = dispatch_next_number($pdo);
    $dispatchDate = date('Y-m-d');
    $addressTo = $to;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO dispatch_notes
            (dispatch_number, dispatch_date, dispatch_from, dispatch_to, route_price, address_to, contents, created_by, signature_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dispatchNumber,
            $dispatchDate,
            $from,
            $to,
            $routePrice,
            $addressTo,
            $contents,
            $userId,
            $signaturePath,
        ]);

        return [
            'ok' => true,
            'dispatchId' => (int) $pdo->lastInsertId(),
            'dispatchNumber' => $dispatchNumber,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to create dispatch note: ' . $e->getMessage()];
    }
}

function ensure_dispatch_routes_price_currency(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'dispatch_routes'")->fetchColumn()) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM dispatch_routes')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('price_currency', $cols, true)) {
            $pdo->exec("ALTER TABLE dispatch_routes ADD COLUMN price_currency VARCHAR(3) NOT NULL DEFAULT 'TZS' AFTER price");
        }
    } catch (Throwable $e) {
        error_log('ensure_dispatch_routes_price_currency: ' . $e->getMessage());
    }
}
