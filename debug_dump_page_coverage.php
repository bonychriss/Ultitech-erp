<?php
/**
 * Full dump-vs-system inventory, compare, and missing-only SQL generator.
 * Usage: /debug_dump_page_coverage.php?key=ultitech-debug
 * Optional:
 *   - source_db=ultimate_trading_voucher
 *   - dump=C:\xampp\htdocs\public_html\database\ultimate_trading_voucher (3).sql
 */

declare(strict_types=1);

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

const DDPC_DEFAULT_KEY = 'ultitech-debug';
const DDPC_VERSION = '2.1.0';

if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}

function ddpc_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddpc_norm(?string $value): string
{
    return strtolower(trim((string) $value));
}

function ddpc_qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function ddpc_expected_key(): string
{
    $expected = DDPC_DEFAULT_KEY;
    foreach ([__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/includes/env.php'] as $probe) {
        if (!is_file($probe)) {
            continue;
        }
        $DEBUG_KEY = null;
        include $probe;
        if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
            $expected = trim((string) $DEBUG_KEY);
            break;
        }
    }
    return $expected;
}

function ddpc_open_db(string $dbName): ?PDO
{
    if ($dbName === '') {
        return null;
    }
    $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
    $user = defined('DB_USER') ? (string) DB_USER : 'root';
    $pass = defined('DB_PASS') ? (string) DB_PASS : '';
    $hostCandidates = array_values(array_unique(array_filter([$host, '127.0.0.1', 'localhost'])));

    foreach ($hostCandidates as $h) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $h . ';dbname=' . $dbName . ';charset=utf8mb4',
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 8,
                ]
            );
            return $pdo;
        } catch (Throwable $e) {
            // try next host
        }
    }
    return null;
}

function ddpc_fetch_all_table_counts(PDO $pdo): array
{
    $out = [];
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $tbl) {
            $tbl = (string) $tbl;
            try {
                $st = $pdo->query('SELECT COUNT(*) FROM ' . ddpc_qi($tbl));
                $out[$tbl] = (int) ($st ? $st->fetchColumn() : 0);
            } catch (Throwable $e) {
                $out[$tbl] = -1; // table exists but count failed
            }
        }
    } catch (Throwable $e) {
        // keep empty
    }
    ksort($out);
    return $out;
}

function ddpc_parse_dump(string $sqlPath): array
{
    $coreTables = ['payment_vouchers', 'invoices', 'sales_orders'];
    $stats = [
        'payment_vouchers' => ['count' => 0, 'ids' => [], 'numbers' => []],
        'invoices' => ['count' => 0, 'ids' => [], 'numbers' => []],
        'sales_orders' => ['count' => 0, 'ids' => [], 'numbers' => [], 'quotation_ids' => [], 'quotation_count' => 0],
    ];
    $tableRows = [];

    $fh = @fopen($sqlPath, 'rb');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Cannot open dump file: ' . $sqlPath, 'stats' => $stats, 'table_rows' => $tableRows];
    }

    $activeTable = '';
    $activeIsCore = false;
    $colMap = [];

    while (($line = fgets($fh)) !== false) {
        $trim = trim($line);

        if ($activeTable === '') {
            if (preg_match('/^INSERT INTO\s+`([^`]+)`\s+\((.+)\)\s+VALUES$/i', $trim, $m)) {
                $activeTable = strtolower(trim((string) $m[1]));
                $activeIsCore = in_array($activeTable, $coreTables, true);
                if (!isset($tableRows[$activeTable])) {
                    $tableRows[$activeTable] = 0;
                }

                $colMap = [];
                if ($activeIsCore && preg_match_all('/`([^`]+)`/', (string) $m[2], $colMatches)) {
                    foreach (($colMatches[1] ?? []) as $i => $c) {
                        $colMap[strtolower((string) $c)] = (int) $i;
                    }
                }
            }
            continue;
        }  


        

        if ($trim === '') {
            continue;
        }

        $raw = rtrim($trim, ",;");
        $isEnd = (substr($trim, -1) === ';');
        $isTuple = (bool) preg_match('/^\(.*\)$/', $raw);

        if ($isTuple) {
            $tableRows[$activeTable] = (int) ($tableRows[$activeTable] ?? 0) + 1;
        }

        if ($isTuple && $activeIsCore) {
            $payload = substr($raw, 1, -1);
            $values = str_getcsv($payload, ',', "'", '\\');
            if (is_array($values) && $values !== []) {
                $stats[$activeTable]['count']++;

                $id = 0;
                $idIdx = $colMap['id'] ?? null;
                if ($idIdx !== null && isset($values[$idIdx])) {
                    $id = (int) trim((string) $values[$idIdx], " \t\n\r\0\x0B'");
                    if ($id > 0) {
                        $stats[$activeTable]['ids'][$id] = true;
                    }
                }

                $numberCol = $activeTable === 'payment_vouchers' ? 'voucher_no' : ($activeTable === 'invoices' ? 'invoice_number' : 'order_number');
                if (isset($colMap[$numberCol])) {
                    $num = trim((string) ($values[$colMap[$numberCol]] ?? ''), " \t\n\r\0\x0B'");
                    if ($num !== '' && strtoupper($num) !== 'NULL') {
                        $stats[$activeTable]['numbers'][$num] = true;
                    }
                }

                if ($activeTable === 'sales_orders' && isset($colMap['status'])) {
                    $status = ddpc_norm(trim((string) ($values[$colMap['status']] ?? ''), " \t\n\r\0\x0B'"));
                    if ($status === 'quotation') {
                        $stats['sales_orders']['quotation_count']++;
                        if ($id > 0) {
                            $stats['sales_orders']['quotation_ids'][$id] = true;
                        }
                    }
                }
            }
        }

        if ($isEnd) {
            $activeTable = '';
            $activeIsCore = false;
            $colMap = [];
        }
    }
    fclose($fh);
    ksort($tableRows);

    return ['ok' => true, 'error' => '', 'stats' => $stats, 'table_rows' => $tableRows];
}

function ddpc_fetch_core_table_stats(PDO $pdo, string $table): array
{
    $out = ['exists' => false, 'count' => 0, 'ids' => [], 'numbers' => []];
    try {
        $chk = $pdo->prepare('SHOW TABLES LIKE ?');
        $chk->execute([$table]);
        if (!$chk->fetchColumn()) {
            return $out;
        }
        $out['exists'] = true;

        $colName = $table === 'payment_vouchers' ? 'voucher_no' : ($table === 'invoices' ? 'invoice_number' : 'order_number');
        $statusExpr = $table === 'sales_orders' ? ', status' : '';
        $sql = 'SELECT id, ' . $colName . $statusExpr . ' FROM ' . ddpc_qi($table);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['count'] = count($rows);

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out['ids'][$id] = true;
            }
            $n = trim((string) ($row[$colName] ?? ''));
            if ($n !== '') {
                $out['numbers'][$n] = true;
            }
            if ($table === 'sales_orders' && ddpc_norm((string) ($row['status'] ?? '')) === 'quotation') {
                if (!isset($out['quotation_ids'])) {
                    $out['quotation_ids'] = [];
                    $out['quotation_count'] = 0;
                }
                $out['quotation_count']++;
                if ($id > 0) {
                    $out['quotation_ids'][$id] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

function ddpc_missing_ids(array $expectedIds, array $actualIds): array
{
    $missing = [];
    foreach ($expectedIds as $id => $_) {
        if (!isset($actualIds[$id])) {
            $missing[] = (int) $id;
        }
    }
    sort($missing);
    return $missing;
}

function ddpc_missing_count(array $expectedIds, array $actualIds): int
{
    return count(ddpc_missing_ids($expectedIds, $actualIds));
}

function ddpc_csv_ids(array $ids): string
{
    if (empty($ids)) {
        return '';
    }
    return implode(', ', array_map('intval', $ids));
}

function ddpc_ids_from_set(array $idSet): array
{
    $ids = array_map('intval', array_keys($idSet));
    sort($ids);
    return $ids;
}

function ddpc_collect_dump_rows(string $sqlPath, array $tables): array
{
    $wanted = array_fill_keys($tables, true);
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = ['columns' => '', 'rows' => []];
    }

    $fh = @fopen($sqlPath, 'rb');
    if (!$fh) {
        return $out;
    }

    $activeTable = '';
    $colMap = [];
    while (($line = fgets($fh)) !== false) {
        $trim = trim($line);
        if ($activeTable === '') {
            if (preg_match('/^INSERT INTO\s+`([^`]+)`\s+\((.+)\)\s+VALUES$/i', $trim, $m)) {
                $tbl = strtolower(trim((string) $m[1]));
                if (isset($wanted[$tbl])) {
                    $activeTable = $tbl;
                    if ($out[$tbl]['columns'] === '') {
                        $out[$tbl]['columns'] = (string) $m[2];
                    }
                    $colMap = [];
                    if (preg_match_all('/`([^`]+)`/', (string) $m[2], $cm)) {
                        foreach (($cm[1] ?? []) as $i => $c) {
                            $colMap[strtolower((string) $c)] = (int) $i;
                        }
                    }
                }
            }
            continue;
        }

        if ($trim === '') {
            continue;
        }

        $raw = rtrim($trim, ",;");
        $isEnd = (substr($trim, -1) === ';');
        if (preg_match('/^\(.*\)$/', $raw)) {
            $payload = substr($raw, 1, -1);
            $vals = str_getcsv($payload, ',', "'", '\\');
            $rowMeta = ['raw' => $raw];
            if (is_array($vals) && !empty($colMap)) {
                if (isset($colMap['id'], $vals[$colMap['id']])) {
                    $rowMeta['id'] = (int) trim((string) $vals[$colMap['id']], " \t\n\r\0\x0B'");
                }
                if (isset($colMap['voucher_id'], $vals[$colMap['voucher_id']])) {
                    $rowMeta['voucher_id'] = (int) trim((string) $vals[$colMap['voucher_id']], " \t\n\r\0\x0B'");
                }
                if (isset($colMap['order_id'], $vals[$colMap['order_id']])) {
                    $rowMeta['order_id'] = (int) trim((string) $vals[$colMap['order_id']], " \t\n\r\0\x0B'");
                }
            }
            $out[$activeTable]['rows'][] = $rowMeta;
        }

        if ($isEnd) {
            $activeTable = '';
            $colMap = [];
        }
    }
    fclose($fh);
    return $out;
}

function ddpc_build_literal_insert(string $targetDb, string $table, string $columns, array $rawRows): string
{
    if ($columns === '' || empty($rawRows)) {
        return '';
    }
    $sql = [];
    // Use INSERT IGNORE so reruns don't fail on duplicate primary keys.
    $sql[] = 'INSERT IGNORE INTO ' . ddpc_qi($targetDb) . '.' . ddpc_qi($table) . ' (' . $columns . ')';
    $sql[] = 'VALUES';
    $last = count($rawRows) - 1;
    foreach ($rawRows as $i => $raw) {
        $suffix = ($i === $last) ? ';' : ',';
        $sql[] = $raw . $suffix;
    }
    return implode("\n", $sql);
}

function ddpc_generate_sync_sql(
    string $dumpPath,
    string $targetDataDb,
    string $targetSalesDb,
    array $dumpStats,
    array $missingVoucherIds,
    array $missingInvoiceIds,
    array $missingOrderIds
): string {
    $sql = [];
    $sql[] = '-- Missing-only sync SQL generated by debug_dump_page_coverage.php';
    $sql[] = '-- Mode: permission-safe (no cross-database SELECT required)';
    $sql[] = '-- Target Data DB: ' . $targetDataDb;
    $sql[] = '-- Target Sales DB: ' . $targetSalesDb;
    $sql[] = '-- Dump file: ' . $dumpPath;
    $sql[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $sql[] = 'START TRANSACTION;';
    $sql[] = '';

    $dumpRows = ddpc_collect_dump_rows($dumpPath, [
        'payment_vouchers',
        'voucher_items',
        'voucher_attachments',
        'approvals',
        'invoices',
        'sales_orders',
        'sales_order_items',
    ]);

    if (!empty($missingVoucherIds)) {
        $voucherNeed = array_fill_keys($missingVoucherIds, true);
        $mainRows = [];
        foreach ($dumpRows['payment_vouchers']['rows'] as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0 && isset($voucherNeed[$id])) {
                $mainRows[] = (string) $r['raw'];
            }
        }

        $itemRows = [];
        foreach ($dumpRows['voucher_items']['rows'] as $r) {
            $fk = (int) ($r['voucher_id'] ?? 0);
            if ($fk > 0 && isset($voucherNeed[$fk])) {
                $itemRows[] = (string) $r['raw'];
            }
        }

        $attachmentRows = [];
        foreach ($dumpRows['voucher_attachments']['rows'] as $r) {
            $fk = (int) ($r['voucher_id'] ?? 0);
            if ($fk > 0 && isset($voucherNeed[$fk])) {
                $attachmentRows[] = (string) $r['raw'];
            }
        }

        $approvalRows = [];
        foreach ($dumpRows['approvals']['rows'] as $r) {
            $fk = (int) ($r['voucher_id'] ?? 0);
            if ($fk > 0 && isset($voucherNeed[$fk])) {
                $approvalRows[] = (string) $r['raw'];
            }
        }

        $sql[] = '-- Payment vouchers + child data';
        $ins = ddpc_build_literal_insert($targetDataDb, 'payment_vouchers', (string) $dumpRows['payment_vouchers']['columns'], $mainRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
        $ins = ddpc_build_literal_insert($targetDataDb, 'voucher_items', (string) $dumpRows['voucher_items']['columns'], $itemRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
        $ins = ddpc_build_literal_insert($targetDataDb, 'voucher_attachments', (string) $dumpRows['voucher_attachments']['columns'], $attachmentRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
        $ins = ddpc_build_literal_insert($targetDataDb, 'approvals', (string) $dumpRows['approvals']['columns'], $approvalRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
    }

    if (!empty($missingInvoiceIds)) {
        $invoiceNeed = array_fill_keys($missingInvoiceIds, true);
        $mainRows = [];
        foreach ($dumpRows['invoices']['rows'] as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0 && isset($invoiceNeed[$id])) {
                $mainRows[] = (string) $r['raw'];
            }
        }
        $sql[] = '-- Sales invoices';
        $ins = ddpc_build_literal_insert($targetSalesDb, 'invoices', (string) $dumpRows['invoices']['columns'], $mainRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
    }

    if (!empty($missingOrderIds)) {
        $orderNeed = array_fill_keys($missingOrderIds, true);
        $mainRows = [];
        foreach ($dumpRows['sales_orders']['rows'] as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0 && isset($orderNeed[$id])) {
                $mainRows[] = (string) $r['raw'];
            }
        }
        $itemRows = [];
        foreach ($dumpRows['sales_order_items']['rows'] as $r) {
            $fk = (int) ($r['order_id'] ?? 0);
            if ($fk > 0 && isset($orderNeed[$fk])) {
                $itemRows[] = (string) $r['raw'];
            }
        }

        $sql[] = '-- Sales orders/quotations + line items';
        $ins = ddpc_build_literal_insert($targetSalesDb, 'sales_orders', (string) $dumpRows['sales_orders']['columns'], $mainRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
        $ins = ddpc_build_literal_insert($targetSalesDb, 'sales_order_items', (string) $dumpRows['sales_order_items']['columns'], $itemRows);
        if ($ins !== '') { $sql[] = $ins; $sql[] = ''; }
    }

    if (empty($missingVoucherIds) && empty($missingInvoiceIds) && empty($missingOrderIds)) {
        $sql[] = '-- No missing core records found. Nothing to insert.';
        $sql[] = '-- Refresh debug page to confirm all counts are MATCH.';
        $sql[] = '';
    }

    $sql[] = 'COMMIT;';
    $sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $sql[] = '';
    $sql[] = '-- Recheck counts after running this SQL (target DB only)';
    $pvAll = ddpc_ids_from_set($dumpStats['payment_vouchers']['ids'] ?? []);
    $ivAll = ddpc_ids_from_set($dumpStats['invoices']['ids'] ?? []);
    $soAll = ddpc_ids_from_set($dumpStats['sales_orders']['ids'] ?? []);
    if (!empty($pvAll)) {
        $sql[] = "SELECT 'payment_vouchers_missing_after' AS metric, "
            . count($pvAll) . " - (SELECT COUNT(*) FROM " . ddpc_qi($targetDataDb) . ".`payment_vouchers` WHERE id IN (" . ddpc_csv_ids($pvAll) . ")) AS missing_rows";
        $sql[] = 'UNION ALL';
    }
    if (!empty($ivAll)) {
        $sql[] = "SELECT 'invoices_missing_after' AS metric, "
            . count($ivAll) . " - (SELECT COUNT(*) FROM " . ddpc_qi($targetSalesDb) . ".`invoices` WHERE id IN (" . ddpc_csv_ids($ivAll) . ")) AS missing_rows";
        $sql[] = 'UNION ALL';
    }
    if (!empty($soAll)) {
        $sql[] = "SELECT 'sales_orders_missing_after' AS metric, "
            . count($soAll) . " - (SELECT COUNT(*) FROM " . ddpc_qi($targetSalesDb) . ".`sales_orders` WHERE id IN (" . ddpc_csv_ids($soAll) . ")) AS missing_rows;";
    } else {
        $sql[] = "SELECT 'sales_orders_missing_after' AS metric, 0 AS missing_rows;";
    }

    return implode("\n", $sql);
}

// Security gate
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$expectedKey = ddpc_expected_key();
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Use ?key=YOUR_DEBUG_KEY (default: " . DDPC_DEFAULT_KEY . ")\n";
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$dumpPath = isset($_GET['dump']) && trim((string) $_GET['dump']) !== ''
    ? trim((string) $_GET['dump'])
    : (__DIR__ . '/database/ultimate_trading_voucher (3).sql');
$sourceDb = isset($_GET['source_db']) && trim((string) $_GET['source_db']) !== ''
    ? trim((string) $_GET['source_db'])
    : 'ultimate_trading_voucher';

$dataDbName = defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '' ? trim((string) DATA_DB_NAME) : (defined('DB_NAME') ? trim((string) DB_NAME) : '');
$salesDbName = defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '' ? trim((string) SALES_DB_NAME) : $dataDbName;

$dumpParsed = ddpc_parse_dump($dumpPath);
$dumpStats = $dumpParsed['stats'];
$dumpTableRows = $dumpParsed['table_rows'];

$dataPdo = ddpc_open_db($dataDbName);
$salesPdo = ($salesDbName === $dataDbName) ? $dataPdo : ddpc_open_db($salesDbName);

$liveCore = [
    'payment_vouchers' => $dataPdo instanceof PDO ? ddpc_fetch_core_table_stats($dataPdo, 'payment_vouchers') : ['exists' => false, 'error' => 'Cannot connect'],
    'invoices' => $salesPdo instanceof PDO ? ddpc_fetch_core_table_stats($salesPdo, 'invoices') : ['exists' => false, 'error' => 'Cannot connect'],
    'sales_orders' => $salesPdo instanceof PDO ? ddpc_fetch_core_table_stats($salesPdo, 'sales_orders') : ['exists' => false, 'error' => 'Cannot connect'],
];

$missingVoucherIds = ddpc_missing_ids($dumpStats['payment_vouchers']['ids'] ?? [], $liveCore['payment_vouchers']['ids'] ?? []);
$missingInvoiceIds = ddpc_missing_ids($dumpStats['invoices']['ids'] ?? [], $liveCore['invoices']['ids'] ?? []);
$missingOrderIds = ddpc_missing_ids($dumpStats['sales_orders']['ids'] ?? [], $liveCore['sales_orders']['ids'] ?? []);
$missingQuotationIds = ddpc_missing_ids($dumpStats['sales_orders']['quotation_ids'] ?? [], $liveCore['sales_orders']['quotation_ids'] ?? []);

$allCoreAvailable = empty($missingVoucherIds) && empty($missingInvoiceIds) && empty($missingOrderIds);
$generatedSql = ddpc_generate_sync_sql(
    $dumpPath,
    $dataDbName,
    $salesDbName,
    $dumpStats,
    $missingVoucherIds,
    $missingInvoiceIds,
    $missingOrderIds
);

$dataTableCounts = $dataPdo instanceof PDO ? ddpc_fetch_all_table_counts($dataPdo) : [];
$salesTableCounts = $salesPdo instanceof PDO ? ddpc_fetch_all_table_counts($salesPdo) : [];

// Build dump-vs-system table comparison for all tables found in dump INSERT sections.
$compareRows = [];
foreach ($dumpTableRows as $table => $dumpCount) {
    $inData = array_key_exists($table, $dataTableCounts);
    $inSales = array_key_exists($table, $salesTableCounts);
    $liveCount = null;
    $source = '-';
    if ($inSales) {
        $liveCount = (int) $salesTableCounts[$table];
        $source = ($salesDbName === $dataDbName) ? 'data/sales' : 'sales';
    } elseif ($inData) {
        $liveCount = (int) $dataTableCounts[$table];
        $source = 'data';
    }
    $compareRows[] = [
        'table' => $table,
        'dump_count' => (int) $dumpCount,
        'live_count' => $liveCount,
        'source' => $source,
        'missing_rows' => ($liveCount === null) ? (int) $dumpCount : max(0, (int) $dumpCount - (int) $liveCount),
        'status' => ($liveCount !== null && (int) $liveCount >= (int) $dumpCount) ? 'OK' : 'CHECK',
    ];
}

usort($compareRows, static function ($a, $b) {
    if ($a['status'] !== $b['status']) {
        return $a['status'] === 'CHECK' ? -1 : 1;
    }
    return strcmp((string) $a['table'], (string) $b['table']);
});

$pageCoverage = [
    'payment_vouchers_page_total' => (int) ($liveCore['payment_vouchers']['count'] ?? 0),
    'invoices_page_total' => min(200, (int) ($liveCore['invoices']['count'] ?? 0)),
    'orders_page_total' => min(200, (int) ($liveCore['sales_orders']['count'] ?? 0)),
    'quotations_total' => (int) ($liveCore['sales_orders']['quotation_count'] ?? 0),
    'quotations_visible_on_orders_page' => 0,
];

if ($salesPdo instanceof PDO && !empty($liveCore['sales_orders']['exists'])) {
    try {
        $q = $salesPdo->query(
            "SELECT COUNT(*) AS c FROM (
                SELECT status
                FROM sales_orders
                ORDER BY created_at DESC, id DESC
                LIMIT 200
            ) x
            WHERE LOWER(TRIM(COALESCE(status, ''))) = 'quotation'"
        );
        $pageCoverage['quotations_visible_on_orders_page'] = (int) ($q ? $q->fetchColumn() : 0);
    } catch (Throwable $e) {
        $pageCoverage['quotations_visible_on_orders_page_error'] = $e->getMessage();
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dump vs Site Coverage Debug</title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; margin: 20px; background: #f8fafc; color: #0f172a; }
        h1 { margin: 0 0 6px; }
        h2 { margin-top: 28px; }
        .muted { color: #475569; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 10px 12px; border-radius: 8px; margin: 12px 0; }
        .ok { color: #166534; font-weight: 700; }
        .bad { color: #991b1b; font-weight: 700; }
        table { border-collapse: collapse; width: 100%; background: #fff; margin-top: 10px; }
        th, td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
        th { background: #f1f5f9; }
        code { background: #f1f5f9; border-radius: 4px; padding: 1px 4px; }
        pre.sql { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
    <h1>Dump vs Site Coverage Debug</h1>
    <p class="muted">Version <?= ddpc_h(DDPC_VERSION) ?>. Delete <code>debug_dump_page_coverage.php</code> after checking.</p>

    <div class="warn">
        <strong>Dump file:</strong> <code><?= ddpc_h($dumpPath) ?></code><br>
        <strong>Source backup DB (for SQL sync):</strong> <code><?= ddpc_h($sourceDb) ?></code><br>
        <strong>Data DB:</strong> <code><?= ddpc_h($dataDbName) ?></code> |
        <strong>Sales DB:</strong> <code><?= ddpc_h($salesDbName) ?></code>
    </div>

    <?php if (!$dumpParsed['ok']): ?>
        <p class="bad"><?= ddpc_h($dumpParsed['error']) ?></p>
    <?php endif; ?>

    <h2>1) Core Dump vs Live DB Match</h2>
    <table>
        <thead>
            <tr>
                <th>Dataset</th>
                <th>Dump Rows</th>
                <th>Live Rows</th>
                <th>Missing In Live</th>
                <th>Sample Missing IDs</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Payment Vouchers</td>
                <td><?= (int) ($dumpStats['payment_vouchers']['count'] ?? 0) ?></td>
                <td><?= (int) ($liveCore['payment_vouchers']['count'] ?? 0) ?></td>
                <td><?= count($missingVoucherIds) ?></td>
                <td><?= ddpc_h(empty($missingVoucherIds) ? '-' : implode(', ', array_slice($missingVoucherIds, 0, 12))) ?></td>
                <td class="<?= empty($missingVoucherIds) ? 'ok' : 'bad' ?>"><?= empty($missingVoucherIds) ? 'MATCH' : 'CHECK' ?></td>
            </tr>
            <tr>
                <td>Sales Invoices</td>
                <td><?= (int) ($dumpStats['invoices']['count'] ?? 0) ?></td>
                <td><?= (int) ($liveCore['invoices']['count'] ?? 0) ?></td>
                <td><?= count($missingInvoiceIds) ?></td>
                <td><?= ddpc_h(empty($missingInvoiceIds) ? '-' : implode(', ', array_slice($missingInvoiceIds, 0, 12))) ?></td>
                <td class="<?= empty($missingInvoiceIds) ? 'ok' : 'bad' ?>"><?= empty($missingInvoiceIds) ? 'MATCH' : 'CHECK' ?></td>
            </tr>
            <tr>
                <td>Sales Orders (all statuses)</td>
                <td><?= (int) ($dumpStats['sales_orders']['count'] ?? 0) ?></td>
                <td><?= (int) ($liveCore['sales_orders']['count'] ?? 0) ?></td>
                <td><?= count($missingOrderIds) ?></td>
                <td><?= ddpc_h(empty($missingOrderIds) ? '-' : implode(', ', array_slice($missingOrderIds, 0, 12))) ?></td>
                <td class="<?= empty($missingOrderIds) ? 'ok' : 'bad' ?>"><?= empty($missingOrderIds) ? 'MATCH' : 'CHECK' ?></td>
            </tr>
            <tr>
                <td>Quotations (from <code>sales_orders.status='quotation'</code>)</td>
                <td><?= (int) ($dumpStats['sales_orders']['quotation_count'] ?? 0) ?></td>
                <td><?= (int) ($liveCore['sales_orders']['quotation_count'] ?? 0) ?></td>
                <td><?= count($missingQuotationIds) ?></td>
                <td><?= ddpc_h(empty($missingQuotationIds) ? '-' : implode(', ', array_slice($missingQuotationIds, 0, 12))) ?></td>
                <td class="<?= empty($missingQuotationIds) ? 'ok' : 'bad' ?>"><?= empty($missingQuotationIds) ? 'MATCH' : 'CHECK' ?></td>
            </tr>
        </tbody>
    </table>

    <h2>2) All System Data Inventory (Live)</h2>
    <table>
        <thead>
            <tr>
                <th>Table</th>
                <th>Data DB Rows</th>
                <th>Sales DB Rows</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $allLiveTables = array_unique(array_merge(array_keys($dataTableCounts), array_keys($salesTableCounts)));
            sort($allLiveTables);
            foreach ($allLiveTables as $tbl):
                $d = array_key_exists($tbl, $dataTableCounts) ? (string) $dataTableCounts[$tbl] : '-';
                $s = array_key_exists($tbl, $salesTableCounts) ? (string) $salesTableCounts[$tbl] : '-';
                ?>
                <tr>
                    <td><?= ddpc_h($tbl) ?></td>
                    <td><?= ddpc_h($d) ?></td>
                    <td><?= ddpc_h($s) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>3) Compare Dump Tables vs System Tables</h2>
    <table>
        <thead>
            <tr>
                <th>Table</th>
                <th>Dump Rows</th>
                <th>Live Rows</th>
                <th>Live Source</th>
                <th>Missing Rows</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($compareRows as $r): ?>
                <tr>
                    <td><?= ddpc_h($r['table']) ?></td>
                    <td><?= (int) $r['dump_count'] ?></td>
                    <td><?= $r['live_count'] === null ? '-' : (int) $r['live_count'] ?></td>
                    <td><?= ddpc_h($r['source']) ?></td>
                    <td><?= (int) $r['missing_rows'] ?></td>
                    <td class="<?= $r['status'] === 'OK' ? 'ok' : 'bad' ?>"><?= ddpc_h($r['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>4) Generated SQL: Import Missing Only</h2>
    <p>Run the SQL below in phpMyAdmin. It only inserts missing rows and then provides a post-sync recheck query.</p>
    <pre class="sql"><?= ddpc_h($generatedSql) ?></pre>

    <h2>5) Recheck Status</h2>
    <p class="<?= $allCoreAvailable ? 'ok' : 'bad' ?>">
        <?= $allCoreAvailable
            ? 'All core dump records are available in live DB now.'
            : 'Not fully synced yet. Run generated SQL, then refresh this page to recheck.' ?>
    </p>

    <h2>6) Page Visibility Coverage</h2>
    <table>
        <thead>
            <tr>
                <th>Page Source</th>
                <th>Total In DB</th>
                <th>Rows Visible On List Page</th>
                <th>Not Visible On List Page</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $pvTotal = (int) ($liveCore['payment_vouchers']['count'] ?? 0);
            $invTotal = (int) ($liveCore['invoices']['count'] ?? 0);
            $ordTotal = (int) ($liveCore['sales_orders']['count'] ?? 0);
            $quoteTotal = (int) ($pageCoverage['quotations_total'] ?? 0);
            $quoteVisible = (int) ($pageCoverage['quotations_visible_on_orders_page'] ?? 0);
            ?>
            <tr>
                <td><code>employee/my-vouchers.php</code> (payment vouchers)</td>
                <td><?= $pvTotal ?></td>
                <td><?= (int) $pageCoverage['payment_vouchers_page_total'] ?></td>
                <td><?= max(0, $pvTotal - (int) $pageCoverage['payment_vouchers_page_total']) ?></td>
                <td>No SQL limit on this page query.</td>
            </tr>
            <tr>
                <td><code>modules/sales/invoices/index.php</code> (invoices)</td>
                <td><?= $invTotal ?></td>
                <td><?= (int) $pageCoverage['invoices_page_total'] ?></td>
                <td><?= max(0, $invTotal - (int) $pageCoverage['invoices_page_total']) ?></td>
                <td>Page query uses <code>LIMIT 200</code>.</td>
            </tr>
            <tr>
                <td><code>modules/sales/orders/index.php</code> (orders list)</td>
                <td><?= $ordTotal ?></td>
                <td><?= (int) $pageCoverage['orders_page_total'] ?></td>
                <td><?= max(0, $ordTotal - (int) $pageCoverage['orders_page_total']) ?></td>
                <td>Page query uses <code>LIMIT 200</code>.</td>
            </tr>
            <tr>
                <td>Quotations as shown from Orders page list</td>
                <td><?= $quoteTotal ?></td>
                <td><?= $quoteVisible ?></td>
                <td><?= max(0, $quoteTotal - $quoteVisible) ?></td>
                <td>Quotation filter is applied on top 200 fetched orders.</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
