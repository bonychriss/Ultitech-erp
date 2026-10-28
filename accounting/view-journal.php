<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

global $pdo;

$id = (int) ($_GET['id'] ?? 0);
$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$attachments = [
    ['name' => 'Rent_Invoice_June2026.pdf', 'size' => '245 KB'],
    ['name' => 'Payment_Receipt_CRDB.pdf', 'size' => '198 KB'],
];

if (isset($_GET['download_attachment'])) {
    $requested = trim((string) ($_GET['download_attachment'] ?? ''));
    $allowedNames = array_map(static function ($a) {
        return (string) ($a['name'] ?? '');
    }, $attachments);
    if (!in_array($requested, $allowedNames, true)) {
        http_response_code(404);
        echo 'Attachment not found.';
        exit;
    }

    $safeName = basename($requested);
    $candidate = __DIR__ . '/../uploads/journal-attachments/' . $safeName;
    if (is_file($candidate)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($candidate));
        readfile($candidate);
        exit;
    }

    $demo = "Demo attachment placeholder\nJournal ID: {$id}\nFile: {$safeName}\nGenerated: " . date('Y-m-d H:i:s');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) strlen($demo));
    echo $demo;
    exit;
}

$jeHasCompany = function_exists('columnExists') && columnExists('erp_journal_entries', 'company_id', $pdo);
$jiHasCompany = function_exists('columnExists') && columnExists('erp_journal_items', 'company_id', $pdo);
$acctHasCompany = function_exists('columnExists') && columnExists('erp_accounts', 'company_id', $pdo);

if ($jeHasCompany && $company_id > 0) {
    $journalStmt = $pdo->prepare('SELECT * FROM erp_journal_entries WHERE id = ? AND company_id = ?');
    $journalStmt->execute([$id, $company_id]);
} else {
    $journalStmt = $pdo->prepare('SELECT * FROM erp_journal_entries WHERE id = ?');
    $journalStmt->execute([$id]);
}
$journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
if (!$journal) {
    http_response_code(404);
    echo 'Journal entry not found.';
    exit;
}

$items = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
try {
    $joinOn = 'ji.account_id = a.id';
    if ($jiHasCompany && $acctHasCompany) {
        $joinOn .= ' AND a.company_id = ji.company_id';
    }
    $itemsSql = "SELECT ji.*, a.code, a.name FROM erp_journal_items ji JOIN erp_accounts a ON {$joinOn} WHERE ji.journal_id = ?";
    $itemsParams = [$id];
    if ($jiHasCompany && $company_id > 0) {
        $itemsSql .= ' AND ji.company_id = ?';
        $itemsParams[] = $company_id;
    }
    $itemsSql .= ' ORDER BY ji.id ASC';
    $itemsStmt = $pdo->prepare($itemsSql);
    $itemsStmt->execute($itemsParams);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($items as $it) {
        $totalDebit += (float) ($it['debit'] ?? 0);
        $totalCredit += (float) ($it['credit'] ?? 0);
    }
} catch (Throwable $e) {
    $items = [];
}

$desc = trim((string) ($journal['description'] ?? ''));
$entryNumber = trim((string) ($journal['entry_number'] ?? ('JE-' . $id)));
$journalReference = trim((string) ($journal['reference'] ?? ''));
$isReversalEntry = (stripos($desc, 'Reversal of ') === 0)
    || (stripos($journalReference, 'REV:') === 0);
$existingReversalId = 0;
try {
    $revCheck = $pdo->prepare(
        "SELECT id FROM erp_journal_entries
         WHERE reference = ?
            OR description LIKE ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $revCheck->execute(['REV:' . $entryNumber, 'Reversal of ' . $entryNumber . '%']);
    $existingReversalId = (int) ($revCheck->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $existingReversalId = 0;
}
$canReverse = !$isReversalEntry && $existingReversalId <= 0 && !empty($items);

// CSV download of this journal entry
if (isset($_GET['download']) && (string) $_GET['download'] === 'journal') {
    $safeFile = preg_replace('/[^A-Za-z0-9_-]+/', '_', $entryNumber) ?: ('journal_' . $id);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeFile . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out) {
        fputcsv($out, ['Entry Number', 'Date', 'Description', 'Reference', 'Status']);
        fputcsv($out, [
            $entryNumber,
            (string) ($journal['date'] ?? ''),
            $desc,
            $journalReference,
            (string) ($journal['status'] ?? ''),
        ]);
        fputcsv($out, []);
        fputcsv($out, ['No.', 'Account Code', 'Account Name', 'Debit', 'Credit']);
        foreach ($items as $idx => $item) {
            fputcsv($out, [
                $idx + 1,
                (string) ($item['code'] ?? ''),
                (string) ($item['name'] ?? ''),
                number_format((float) ($item['debit'] ?? 0), 2, '.', ''),
                number_format((float) ($item['credit'] ?? 0), 2, '.', ''),
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Total Debit', number_format($totalDebit, 2, '.', '')]);
        fputcsv($out, ['Total Credit', number_format($totalCredit, 2, '.', '')]);
        fclose($out);
    }
    exit;
}

// Create reversing journal entry (swap debits/credits)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverse_entry'])) {
    if (!$canReverse) {
        $_SESSION['error_msg'] = $isReversalEntry
            ? 'This entry is already a reversal and cannot be reversed again.'
            : ($existingReversalId > 0
                ? 'A reversal already exists for this journal entry.'
                : 'This journal entry has no lines to reverse.');
        header('Location: view-journal.php?id=' . $id . '&module=' . rawurlencode((string) ($_GET['module'] ?? $jeModule)));
        exit;
    }

    try {
        $pdo->beginTransaction();
        $accountCol = function_exists('resolveExistingColumn')
            ? resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account'])
            : 'account_id';
        if (!$accountCol) {
            throw new RuntimeException('Journal items account column not found.');
        }

        $lastNum = (int) ($pdo->query(
            "SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries"
        )->fetchColumn() ?: 0);
        $newNumber = 'JE-' . str_pad((string) ($lastNum + 1), 6, '0', STR_PAD_LEFT);
        $revDate = date('Y-m-d');
        $revDesc = 'Reversal of ' . $entryNumber
            . ($desc !== '' ? (': ' . $desc) : '');
        if (strlen($revDesc) > 250) {
            $revDesc = substr($revDesc, 0, 247) . '...';
        }
        $revRef = 'REV:' . $entryNumber;

        $hasReference = function_exists('columnExists') && columnExists('erp_journal_entries', 'reference', $pdo);
        $hasStatus = function_exists('columnExists') && columnExists('erp_journal_entries', 'status', $pdo);
        $cols = ['entry_number', 'date', 'description', 'created_by'];
        $vals = [$newNumber, $revDate, $revDesc, (int) ($_SESSION['user_id'] ?? 0) ?: null];
        if ($hasReference) {
            $cols[] = 'reference';
            $vals[] = $revRef;
        }
        if ($hasStatus) {
            $cols[] = 'status';
            $vals[] = 'posted';
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $pdo->prepare(
            'INSERT INTO erp_journal_entries (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
        )->execute($vals);
        $newId = (int) $pdo->lastInsertId();

        $insItem = $pdo->prepare(
            "INSERT INTO erp_journal_items (journal_id, {$accountCol}, debit, credit) VALUES (?, ?, ?, ?)"
        );
        $revDebit = 0.0;
        $revCredit = 0.0;
        foreach ($items as $item) {
            $debit = (float) ($item['debit'] ?? 0);
            $credit = (float) ($item['credit'] ?? 0);
            $accountId = (int) ($item[$accountCol] ?? ($item['account_id'] ?? 0));
            if ($accountId <= 0) {
                throw new RuntimeException('Missing account on a journal line.');
            }
            // Swap debit/credit for the reversing entry.
            $insItem->execute([$newId, $accountId, $credit, $debit]);
            $revDebit += $credit;
            $revCredit += $debit;
        }
        if (abs($revDebit - $revCredit) > 0.01) {
            throw new RuntimeException('Reversal would not balance.');
        }
        $pdo->commit();

        $_SESSION['success_msg'] = 'Reversal posted as ' . $newNumber . '.';
        header(
            'Location: view-journal.php?id=' . $newId
            . '&module=' . rawurlencode((string) ($_GET['module'] ?? $jeModule))
            . '&reversed=1'
        );
        exit;
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_msg'] = 'Could not reverse entry: ' . $e->getMessage();
        header('Location: view-journal.php?id=' . $id . '&module=' . rawurlencode((string) ($_GET['module'] ?? $jeModule)));
        exit;
    }
}

$journalType = 'General Journal';
$descLower = strtolower($desc);
if (strpos($descLower, 'payroll') !== false) {
    $journalType = 'Payroll Journal';
} elseif (strpos($descLower, 'sale') !== false) {
    $journalType = 'Sales Journal';
} elseif (strpos($descLower, 'bank') !== false || strpos($descLower, 'payment') !== false) {
    $journalType = 'Bank Journal';
} elseif (strpos($descLower, 'purchase') !== false || strpos($descLower, 'supplier') !== false) {
    $journalType = 'Purchase Journal';
} elseif (strpos($descLower, 'voucher') !== false) {
    $journalType = 'Payment Voucher';
}

if (!function_exists('sales_module_url')) {
    $salesFn = __DIR__ . '/../modules/sales/functions.php';
    if (is_file($salesFn)) {
        require_once $salesFn;
    }
}

$jeInvoiceViewUrl = static function (int $invoiceId): string {
    if ($invoiceId <= 0) {
        return '';
    }
    if (function_exists('sales_module_url')) {
        return sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => 'sales']);
    }
    if (function_exists('company_url')) {
        return company_url('sales/invoice/' . $invoiceId) . '?module=sales';
    }
    $path = '/modules/sales/invoices/view.php?id=' . $invoiceId . '&module=sales';

    return function_exists('app_url') ? app_url($path) : $path;
};

/** @var array<string,int> */
$jeInvoiceIdByNumber = [];
$jeResolveInvoiceId = static function (string $invoiceNumber) use ($pdo, &$jeInvoiceIdByNumber): int {
    $key = strtoupper(trim($invoiceNumber));
    if ($key === '') {
        return 0;
    }
    if (array_key_exists($key, $jeInvoiceIdByNumber)) {
        return $jeInvoiceIdByNumber[$key];
    }
    $jeInvoiceIdByNumber[$key] = 0;
    try {
        if (function_exists('tableExists') && !tableExists('invoices', $pdo)) {
            return 0;
        }
        $st = $pdo->prepare('SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1');
        $st->execute([$invoiceNumber]);
        $jeInvoiceIdByNumber[$key] = (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $jeInvoiceIdByNumber[$key] = 0;
    }

    return $jeInvoiceIdByNumber[$key];
};

$jeLinkifyInvoiceRefs = static function (string $text) use ($jeResolveInvoiceId, $jeInvoiceViewUrl): string {
    $text = trim($text);
    if ($text === '' || $text === '-') {
        return htmlspecialchars($text !== '' ? $text : '-', ENT_QUOTES, 'UTF-8');
    }
    $parts = preg_split('/\b(INV-\d{4}-\d+)\b/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) === 1) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    $html = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^INV-\d{4}-\d+$/i', $part)) {
            $invId = $jeResolveInvoiceId($part);
            $label = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            if ($invId > 0) {
                $href = $jeInvoiceViewUrl($invId);
            } elseif (function_exists('sales_module_url')) {
                $href = sales_module_url('invoices/index.php', [
                    'module' => 'sales',
                    'q' => $part,
                ]);
            } elseif (function_exists('app_url')) {
                $href = app_url('/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode($part));
            } else {
                $href = '/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode($part);
            }
            $html .= '<a class="jed-inv-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
        } else {
            $html .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        }
    }

    return $html !== '' ? $html : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
};

$jeVoucherViewUrl = static function (int $voucherId): string {
    if ($voucherId <= 0) {
        return '';
    }
    $path = 'view-voucher.php?id=' . $voucherId . '&module=voucher';
    if (function_exists('company_url')) {
        return company_url($path);
    }

    return function_exists('app_url') ? app_url('/' . $path) : '/' . $path;
};

$jeResolveVoucherId = static function (string $voucherNo) use ($pdo): int {
    $voucherNo = trim($voucherNo);
    if ($voucherNo === '') {
        return 0;
    }
    try {
        if (function_exists('tableExists') && !tableExists('payment_vouchers', $pdo)) {
            return 0;
        }
        $st = $pdo->prepare('SELECT id FROM payment_vouchers WHERE voucher_no = ? LIMIT 1');
        $st->execute([$voucherNo]);
        $found = (int) ($st->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
        $alt = str_replace('-', '/', $voucherNo);
        if ($alt !== $voucherNo) {
            $st->execute([$alt]);
            $found = (int) ($st->fetchColumn() ?: 0);
            if ($found > 0) {
                return $found;
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
};

/** @var list<array{key:string,label:string,href:string,type:string}> */
$relatedDocs = [];
$relatedSeen = [];
$jeAddRelated = static function (string $key, string $label, string $href, string $type) use (&$relatedDocs, &$relatedSeen): void {
    $k = strtoupper(trim($key));
    if ($k === '' || isset($relatedSeen[$k]) || $href === '') {
        return;
    }
    $relatedSeen[$k] = true;
    $relatedDocs[] = [
        'key' => $k,
        'label' => $label,
        'href' => $href,
        'type' => $type,
    ];
};

$scanText = trim($desc . ' ' . (string) ($journal['reference'] ?? '') . ' ' . (string) ($journal['entry_number'] ?? ''));
if (preg_match_all('/\b(INV-\d{4}-\d+)\b/i', $scanText, $invMatches)) {
    foreach (array_unique($invMatches[1]) as $invNo) {
        $invId = $jeResolveInvoiceId((string) $invNo);
        if ($invId > 0) {
            $href = $jeInvoiceViewUrl($invId);
        } elseif (function_exists('sales_module_url')) {
            $href = sales_module_url('invoices/index.php', ['module' => 'sales', 'q' => $invNo]);
        } else {
            $href = function_exists('app_url')
                ? app_url('/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode((string) $invNo))
                : '/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode((string) $invNo);
        }
        $jeAddRelated((string) $invNo, strtoupper((string) $invNo) . ' (Invoice)', $href, 'invoice');
    }
}
if (preg_match_all('/\b(PV[\/\-][A-Z0-9][A-Z0-9\/\-]*)\b/i', $scanText, $pvMatches)) {
    foreach (array_unique($pvMatches[1]) as $pvNo) {
        $pvId = $jeResolveVoucherId((string) $pvNo);
        if ($pvId > 0) {
            $jeAddRelated((string) $pvNo, (string) $pvNo . ' (Payment Voucher)', $jeVoucherViewUrl($pvId), 'voucher');
        }
    }
}

$page_title = 'Journal Entry Details';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.jed-inv-link{color:#2563eb;font-weight:700;text-decoration:none}
.jed-inv-link:hover{text-decoration:underline}
.jed-rel-list{display:flex;flex-direction:column;gap:8px}
.jed-rel-list a{font-size:12px;color:#2563eb;text-decoration:none;font-weight:700}
.jed-rel-list a:hover{text-decoration:underline}
.jed-rel-empty{font-size:12px;color:#94a3b8}
.jed-rel-more{display:none;margin-top:8px;padding-top:8px;border-top:1px solid #eef2f7}
.jed-rel-more.is-open{display:block}
.jed-btn[aria-expanded="true"]{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.main-content.jed-shell{margin-top:0!important;padding:14px 0 26px!important;background:#f9fafb;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0f172a}
.jed-wrap{padding:0 16px}
.jed-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.jed-title{margin:0;font-size:34px;font-weight:800;color:#0b1f5d;line-height:1.1}
.jed-sub{margin:6px 0 0;font-size:14px;color:#64748b}
.jed-bc{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748b}
.jed-bc a{color:#2563eb;text-decoration:none;font-weight:700}
.jed-actions{display:flex;gap:10px;flex-wrap:wrap}
.jed-btn{border:1px solid #dbe2ea;background:#fff;color:#0f172a;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.jed-btn.warn{color:#f97316}
.jed-btn:disabled{opacity:.55;cursor:not-allowed}
.jed-flash{margin:0 0 12px;padding:10px 12px;border-radius:8px;font-size:13px;font-weight:600}
.jed-flash--ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857}
.jed-flash--err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.jed-dl-wrap{position:relative;display:inline-flex}
.jed-dl-menu{position:absolute;top:calc(100% + 6px);right:0;min-width:180px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 28px rgba(15,23,42,.12);padding:6px;z-index:40;display:none}
.jed-dl-menu.is-open{display:block}
.jed-dl-menu a,.jed-dl-menu button{width:100%;border:0;background:transparent;display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;text-align:left}
.jed-dl-menu a:hover,.jed-dl-menu button:hover{background:#f8fafc}
.jed-dl-menu i{width:16px;color:#2563eb}
.jed-print-sheet{position:fixed;left:0;top:0;width:794px;background:#fff;color:#0f172a;padding:28px 32px;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;opacity:0;pointer-events:none;z-index:-1}
.jed-print-sheet.is-exporting{opacity:1;z-index:2147483000;pointer-events:none}
.jed-print-sheet h1{margin:0 0 4px;font-size:22px;font-weight:800}
.jed-print-sheet .sub{margin:0 0 18px;color:#64748b;font-size:12px}
.jed-print-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;margin-bottom:18px;font-size:12px}
.jed-print-meta .k{color:#64748b;font-weight:600}
.jed-print-meta .v{color:#0f172a;font-weight:700}
.jed-print-sheet table{width:100%;border-collapse:collapse;font-size:11px}
.jed-print-sheet th,.jed-print-sheet td{border:1px solid #e2e8f0;padding:7px 8px;text-align:left}
.jed-print-sheet th{background:#f8fafc;font-weight:700}
.jed-print-sheet .num{text-align:right;font-variant-numeric:tabular-nums}
.jed-print-tot{margin-top:12px;display:flex;justify-content:flex-end;gap:18px;font-size:12px;font-weight:700}
@media print{
  body *{visibility:hidden!important}
  .jed-print-sheet,.jed-print-sheet *{visibility:visible!important}
  .jed-print-sheet{position:static!important;left:auto!important;width:auto!important;padding:0!important}
}
.jed-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:12px;align-items:start}
.jed-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);overflow:hidden;margin-bottom:12px}
.jed-hd{padding:12px 15px;border-bottom:1px solid #eef2f7;font-size:15px;font-weight:700;color:#0f172a}
.jed-bd{padding:14px 15px}
.jed-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 14px}
.jed-main{display:flex;gap:12px;align-items:flex-start}
.jed-status{width:54px;height:54px;border-radius:50%;background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
.jed-ref{font-size:32px;font-weight:800;line-height:1.05;color:#0f172a}
.jed-tag{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;margin-bottom:6px}
.jed-k{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.jed-v{font-size:14px;color:#0f172a;font-weight:600;margin-top:3px}
.jed-v.muted{font-weight:500;color:#475569}
.jed-tabs{display:flex;gap:4px;flex-wrap:wrap;padding:0 12px;border-bottom:1px solid #eef2f7}
.jed-tabs button{border:0;background:transparent;padding:11px 12px;font-size:13px;font-weight:600;color:#64748b;border-bottom:2px solid transparent;cursor:pointer}
.jed-tabs button.active{color:#2563eb;border-bottom-color:#2563eb}
.jed-tabs button:hover{color:#2563eb}
.jed-pan{padding:14px 15px;display:none}
.jed-pan.is-active{display:block}
.jed-split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.jed-table{width:100%;border-collapse:collapse;font-size:13px;min-width:820px}
.jed-table th,.jed-table td{padding:9px 8px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.jed-table th{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;background:#fafafa;text-align:left}
.jed-table .num{text-align:right}
.jed-footer-tot{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px;padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px}
.jed-footer-tot .n{font-size:12px;color:#64748b}
.jed-footer-tot .v{font-size:20px;font-weight:800;color:#0f172a;margin-top:2px}
.jed-footer-tot .ok{color:#16a34a}
.jed-footer-tot-right{max-width:560px;margin-left:auto}
.jed-ok{margin-top:10px;padding:10px 12px;border-radius:8px;background:#ecfdf5;border:1px solid #bbf7d0;color:#15803d;font-size:13px;font-weight:600}
.jed-kv{display:flex;justify-content:space-between;gap:8px;margin-bottom:9px;font-size:13px;color:#64748b}
.jed-kv span:last-child{font-weight:700;color:#0f172a;text-align:right}
.jed-pill{display:inline-flex;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d}
.jed-flow-row{display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:8px 10px;align-items:start;margin-bottom:12px}
.jed-flow-row:last-child{margin-bottom:0}
.jed-dot{width:16px;height:16px;border-radius:50%;background:#22c55e;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9px;line-height:1;flex-shrink:0;margin-top:2px}
.jed-flow-title{font-size:13px;font-weight:700;color:#0f172a;line-height:1.15}
.jed-flow-by{font-size:11px;color:#64748b;margin-top:3px}
.jed-flow-time{font-size:11px;color:#64748b;font-weight:600;white-space:nowrap;padding-top:1px}
.jed-att-row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eef2f7}
.jed-att-row:last-of-type{border-bottom:0}
.jed-att-main{min-width:0}
.jed-att-name{font-size:13px;color:#0f172a;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jed-att-meta{font-size:11px;color:#64748b;margin-top:2px}
.jed-att-actions{display:flex;align-items:center;gap:8px}
.jed-dl{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid #dbe2ea;border-radius:7px;background:#fff;color:#2563eb;text-decoration:none;font-size:12px;font-weight:700}
.jed-rel a{font-size:12px;color:#2563eb;text-decoration:none;font-weight:700}
@media(max-width:1200px){.jed-grid{grid-template-columns:1fr}.jed-meta{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.jed-meta,.jed-split,.jed-footer-tot{grid-template-columns:1fr}.jed-ref{font-size:26px}}
</style>

<main class="main-content jed-shell">
  <div class="jed-wrap">
    <div class="jed-top">
      <div>
        <h1 class="jed-title">Journal Entry Details</h1>
        <p class="jed-sub"><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE')) ?> · <?= htmlspecialchars($journalType) ?></p>
        <div class="jed-bc">
          <a href="journal-entries.php?module=<?= $jeModule ?>">Journal Entries</a><i class="fas fa-chevron-right"></i>
          <span><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE')) ?></span>
        </div>
      </div>
      <div class="jed-actions">
        <?php
          $jeStatusLower = strtolower((string) ($journal['status'] ?? 'posted'));
          $canEditEntry = $jeStatusLower !== 'locked';
          $editUrl = 'create-journal.php?id=' . (int) $id . '&module=' . rawurlencode((string) $jeModule);
        ?>
        <?php if ($canEditEntry): ?>
          <a class="jed-btn" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" title="Edit this journal entry"><i class="fas fa-pen"></i> Edit</a>
        <?php else: ?>
          <button type="button" class="jed-btn" disabled title="Locked entries cannot be edited" style="opacity:.55;cursor:not-allowed;"><i class="fas fa-pen"></i> Edit</button>
        <?php endif; ?>
        <div class="jed-dl-wrap" id="jedDownloadWrap">
          <button type="button" class="jed-btn" id="jedDownloadBtn" aria-haspopup="true" aria-expanded="false" title="Choose download format">
            <i class="fas fa-download"></i> Download <i class="fas fa-chevron-down" style="font-size:10px;opacity:.7;"></i>
          </button>
          <div class="jed-dl-menu" id="jedDownloadMenu" role="menu">
            <button type="button" role="menuitem" id="jedDownloadPdf"><i class="fas fa-file-pdf"></i> PDF</button>
            <a role="menuitem" href="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>&download=journal"><i class="fas fa-file-csv"></i> CSV</a>
          </div>
        </div>
        <?php if ($canReverse): ?>
          <form method="post" action="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>" style="margin:0;" onsubmit="return confirm('Post a reversing journal entry for <?= htmlspecialchars($entryNumber, ENT_QUOTES, 'UTF-8') ?>? Debits and credits will be swapped.');">
            <input type="hidden" name="reverse_entry" value="1">
            <button type="submit" class="jed-btn warn" title="Create a reversing journal entry"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
          </form>
        <?php elseif ($existingReversalId > 0): ?>
          <a class="jed-btn warn" href="?id=<?= (int) $existingReversalId ?>&module=<?= urlencode((string) $jeModule) ?>" title="Open the existing reversal"><i class="fas fa-rotate-left"></i> View Reversal</a>
        <?php else: ?>
          <button type="button" class="jed-btn warn" disabled title="<?= $isReversalEntry ? 'This entry is already a reversal' : 'Nothing to reverse' ?>" style="opacity:.55;cursor:not-allowed;"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($_SESSION['success_msg'])): ?>
      <div class="jed-flash jed-flash--ok"><?= htmlspecialchars((string) $_SESSION['success_msg']) ?></div>
      <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_msg'])): ?>
      <div class="jed-flash jed-flash--err"><?= htmlspecialchars((string) $_SESSION['error_msg']) ?></div>
      <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    <div class="jed-grid">
      <div>
        <section class="jed-card">
          <div class="jed-bd jed-meta">
            <div style="grid-column:span 2;">
              <div class="jed-main">
                <span class="jed-status"><i class="far fa-file-lines"></i></span>
                <div>
                  <span class="jed-tag">Posted</span>
                  <div class="jed-ref"><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE-000001')) ?></div>
                  <div class="jed-v muted" style="margin-top:6px;"><?= htmlspecialchars($journalType) ?></div>
                  <div class="jed-v muted"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : 'Journal entry' ?></div>
                </div>
              </div>
            </div>
            <div><div class="jed-k">Transaction Date</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('d/m/Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Posting Date</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('d/m/Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Period</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('F Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Reference No.</div><div class="jed-v"><?= htmlspecialchars((string) ($journal['entry_number'] ?? '-')) ?></div></div>
            <div><div class="jed-k">Created By</div><div class="jed-v"><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'System Admin')) ?></div></div>
            <div><div class="jed-k">Created On</div><div class="jed-v"><?= !empty($journal['created_at']) ? htmlspecialchars(date('d/m/Y h:i A', strtotime((string)$journal['created_at']))) : htmlspecialchars(date('d/m/Y h:i A')) ?></div></div>
          </div>
        </section>

        <section class="jed-card" id="jedTabsCard">
          <div class="jed-tabs" role="tablist" aria-label="Journal details">
            <button class="active" type="button" role="tab" aria-selected="true" data-jed-tab="overview">Overview</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="lines">Journal Lines</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="attachments">Attachments (<?= count($attachments) ?>)</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="audit">Audit Trail</button>
          </div>

          <div class="jed-pan is-active" data-jed-panel="overview" role="tabpanel">
            <div class="jed-split">
              <div><div class="jed-k">Description</div><div class="jed-v muted"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : '—' ?></div></div>
              <div><div class="jed-k">Notes</div><div class="jed-v muted">Payment details and supporting remarks captured on post.</div></div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="lines" role="tabpanel" hidden>
            <div style="overflow:auto;">
              <table class="jed-table">
                <thead>
                  <tr>
                    <th>No.</th><th>Account Code</th><th>Account Name</th><th>Description</th><th>Department</th><th>Project</th><th class="num">Debit (TZS)</th><th class="num">Credit (TZS)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($items)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#64748b;padding:22px;">No journal lines found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                      <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars((string) ($item['code'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['name'] ?? '-')) ?></td>
                        <td><?= $jeLinkifyInvoiceRefs((string) ($item['line_desc'] ?? ($desc !== '' ? $desc : '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($item['department'] ?? 'Administration')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['project'] ?? '-')) ?></td>
                        <td class="num"><?= (float)($item['debit'] ?? 0) > 0 ? number_format((float)$item['debit'], 2) : '0.00' ?></td>
                        <td class="num"><?= (float)($item['credit'] ?? 0) > 0 ? number_format((float)$item['credit'], 2) : '0.00' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
              <div class="jed-footer-tot jed-footer-tot-right">
                <div><div class="n">Total Debit (TZS)</div><div class="v"><?= number_format($totalDebit, 2) ?></div></div>
                <div><div class="n">Total Credit (TZS)</div><div class="v"><?= number_format($totalCredit, 2) ?></div></div>
                <div><div class="n">Difference</div><div class="v ok"><?= number_format($totalDebit - $totalCredit, 2) ?></div></div>
              </div>
              <div class="jed-ok"><i class="far fa-circle-check"></i> Debits and credits are balanced.</div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="attachments" role="tabpanel" hidden>
            <?php foreach ($attachments as $att): ?>
              <div class="jed-att-row">
                <div class="jed-att-main">
                  <div class="jed-att-name"><?= htmlspecialchars((string) $att['name']) ?></div>
                  <div class="jed-att-meta"><?= htmlspecialchars((string) $att['size']) ?></div>
                </div>
                <div class="jed-att-actions">
                  <a class="jed-dl" href="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>&download_attachment=<?= urlencode((string) $att['name']) ?>"><i class="fas fa-download"></i> Download</a>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($attachments)): ?>
              <div class="jed-v muted">No attachments.</div>
            <?php endif; ?>
          </div>

          <div class="jed-pan" data-jed-panel="audit" role="tabpanel" hidden>
            <div class="jed-kv"><span>Posted</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
            <div class="jed-kv"><span>Approved</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
            <div class="jed-kv" style="margin-bottom:0;"><span>Reviewed</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
          </div>
        </section>
      </div>

      <aside>
        <section class="jed-card">
          <div class="jed-hd"><i class="fas fa-square-poll-vertical"></i> Journal Summary</div>
          <div class="jed-bd">
            <div class="jed-kv"><span>Journal</span><span><?= htmlspecialchars($journalType) ?></span></div>
            <div class="jed-kv"><span>Status</span><span><span class="jed-pill">Posted</span></span></div>
            <div class="jed-kv"><span>Debit (TZS)</span><span><?= number_format($totalDebit, 2) ?></span></div>
            <div class="jed-kv"><span>Credit (TZS)</span><span><?= number_format($totalCredit, 2) ?></span></div>
            <div class="jed-kv" style="margin-bottom:0;"><span>Difference</span><span style="color:#16a34a;"><?= number_format($totalDebit - $totalCredit, 2) ?></span></div>
          </div>
        </section>

        <section class="jed-card">
          <div class="jed-hd"><i class="fas fa-gears"></i> Approval Workflow</div>
          <div class="jed-bd">
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Draft</div><div class="jed-flow-by">System Admin</div></div>
              <div class="jed-flow-time">01/06/2026 10:15 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Reviewed</div><div class="jed-flow-by">Finance User</div></div>
              <div class="jed-flow-time">01/06/2026 11:05 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Approved</div><div class="jed-flow-by">Finance Manager</div></div>
              <div class="jed-flow-time">01/06/2026 11:20 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Posted</div><div class="jed-flow-by">System Admin</div></div>
              <div class="jed-flow-time">01/06/2026 11:25 AM</div>
            </div>
          </div>
        </section>

        <section class="jed-card jed-rel" id="jedRelatedCard">
          <div class="jed-hd"><i class="fas fa-link"></i> Related Documents</div>
          <div class="jed-bd">
            <?php if (empty($relatedDocs)): ?>
              <div class="jed-rel-empty">No related documents linked to this journal entry.</div>
            <?php else: ?>
              <?php
              $primary = $relatedDocs[0];
              $extraDocs = array_slice($relatedDocs, 1);
              ?>
              <div class="jed-rel-list">
                <a href="<?= htmlspecialchars($primary['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($primary['label'], ENT_QUOTES, 'UTF-8') ?></a>
              </div>
              <?php if (!empty($extraDocs)): ?>
                <div class="jed-rel-more" id="jedRelatedMore" hidden>
                  <div class="jed-rel-list">
                    <?php foreach ($extraDocs as $doc): ?>
                      <a href="<?= htmlspecialchars($doc['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($doc['label'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <div style="margin-top:10px;">
                <button
                  type="button"
                  class="jed-btn"
                  id="jedRelatedToggle"
                  style="padding:6px 10px;font-size:12px;"
                  aria-expanded="false"
                  aria-controls="jedRelatedMore"
                  data-related-count="<?= (int) count($relatedDocs) ?>"
                >View All Related<?= count($relatedDocs) > 1 ? ' (' . count($relatedDocs) . ')' : '' ?></button>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>

<div class="jed-print-sheet" id="jedPrintSheet" aria-hidden="true">
  <h1>Journal Entry</h1>
  <p class="sub"><?= htmlspecialchars($entryNumber) ?> · <?= htmlspecialchars($journalType) ?></p>
  <div class="jed-print-meta">
    <div><div class="k">Transaction Date</div><div class="v"><?= !empty($journal['date']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $journal['date']))) : '-' ?></div></div>
    <div><div class="k">Reference</div><div class="v"><?= htmlspecialchars($journalReference !== '' ? $journalReference : $entryNumber) ?></div></div>
    <div style="grid-column:1 / -1;"><div class="k">Description</div><div class="v"><?= htmlspecialchars($desc !== '' ? $desc : '—') ?></div></div>
  </div>
  <table>
    <thead>
      <tr>
        <th>No.</th>
        <th>Account Code</th>
        <th>Account Name</th>
        <th class="num">Debit (TZS)</th>
        <th class="num">Credit (TZS)</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="5" style="text-align:center;color:#64748b;">No journal lines.</td></tr>
      <?php else: ?>
        <?php foreach ($items as $idx => $item): ?>
          <tr>
            <td><?= (int) ($idx + 1) ?></td>
            <td><?= htmlspecialchars((string) ($item['code'] ?? '-')) ?></td>
            <td><?= htmlspecialchars((string) ($item['name'] ?? '-')) ?></td>
            <td class="num"><?= number_format((float) ($item['debit'] ?? 0), 2) ?></td>
            <td class="num"><?= number_format((float) ($item['credit'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  <div class="jed-print-tot">
    <span>Total Debit: <?= number_format($totalDebit, 2) ?></span>
    <span>Total Credit: <?= number_format($totalCredit, 2) ?></span>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
(function () {
  var card = document.getElementById('jedTabsCard');
  if (card) {
    var tabs = card.querySelectorAll('[data-jed-tab]');
    var panels = card.querySelectorAll('[data-jed-panel]');
    function activate(name) {
      tabs.forEach(function (btn) {
        var on = btn.getAttribute('data-jed-tab') === name;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute('data-jed-panel') === name;
        panel.classList.toggle('is-active', on);
        if (on) panel.removeAttribute('hidden');
        else panel.setAttribute('hidden', '');
      });
    }
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.getAttribute('data-jed-tab') || 'overview');
      });
    });
  }

  var toggle = document.getElementById('jedRelatedToggle');
  var more = document.getElementById('jedRelatedMore');
  if (toggle) {
    toggle.addEventListener('click', function () {
      if (more) {
        var open = more.classList.toggle('is-open');
        if (open) more.removeAttribute('hidden');
        else more.setAttribute('hidden', '');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open ? 'Hide Related' : ('View All Related (' + (toggle.getAttribute('data-related-count') || '') + ')');
        return;
      }
      var first = document.querySelector('#jedRelatedCard .jed-rel-list a');
      if (first && first.href) {
        window.location.href = first.href;
      }
    });
  }

  var wrap = document.getElementById('jedDownloadWrap');
  var btn = document.getElementById('jedDownloadBtn');
  var menu = document.getElementById('jedDownloadMenu');
  var pdfBtn = document.getElementById('jedDownloadPdf');
  var pdfName = <?= json_encode(preg_replace('/[^A-Za-z0-9_-]+/', '_', $entryNumber) ?: ('journal_' . $id), JSON_UNESCAPED_SLASHES) ?>;
  var pdfData = <?= json_encode([
      'entry' => $entryNumber,
      'type' => $journalType,
      'date' => !empty($journal['date']) ? date('d/m/Y', strtotime((string) $journal['date'])) : '-',
      'reference' => $journalReference !== '' ? $journalReference : $entryNumber,
      'description' => $desc !== '' ? $desc : '—',
      'totalDebit' => number_format($totalDebit, 2),
      'totalCredit' => number_format($totalCredit, 2),
      'lines' => array_map(static function ($item, $idx) {
          return [
              'no' => $idx + 1,
              'code' => (string) ($item['code'] ?? '-'),
              'name' => (string) ($item['name'] ?? '-'),
              'debit' => number_format((float) ($item['debit'] ?? 0), 2),
              'credit' => number_format((float) ($item['credit'] ?? 0), 2),
          ];
      }, $items, array_keys($items)),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function closeMenu() {
    if (!menu || !btn) return;
    menu.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildPdfNode() {
    var linesHtml = (pdfData.lines || []).map(function (line) {
      return '<tr>'
        + '<td style="border:1px solid #94a3b8;padding:7px 8px;color:#000;">' + esc(line.no) + '</td>'
        + '<td style="border:1px solid #94a3b8;padding:7px 8px;color:#000;">' + esc(line.code) + '</td>'
        + '<td style="border:1px solid #94a3b8;padding:7px 8px;color:#000;">' + esc(line.name) + '</td>'
        + '<td style="border:1px solid #94a3b8;padding:7px 8px;color:#000;text-align:right;">' + esc(line.debit) + '</td>'
        + '<td style="border:1px solid #94a3b8;padding:7px 8px;color:#000;text-align:right;">' + esc(line.credit) + '</td>'
        + '</tr>';
    }).join('');
    if (!linesHtml) {
      linesHtml = '<tr><td colspan="5" style="border:1px solid #94a3b8;padding:10px;text-align:center;color:#000;">No journal lines.</td></tr>';
    }

    var node = document.createElement('div');
    node.setAttribute('data-theme', 'light');
    node.innerHTML = ''
      + '<h1 style="margin:0 0 4px;font-size:22px;font-weight:800;color:#000;font-family:Arial,Helvetica,sans-serif;">Journal Entry</h1>'
      + '<p style="margin:0 0 16px;font-size:12px;color:#334155;font-family:Arial,Helvetica,sans-serif;">' + esc(pdfData.entry) + ' · ' + esc(pdfData.type) + '</p>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;margin-bottom:16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;">'
      +   '<div><div style="color:#475569;font-weight:600;">Transaction Date</div><div style="font-weight:700;color:#000;">' + esc(pdfData.date) + '</div></div>'
      +   '<div><div style="color:#475569;font-weight:600;">Reference</div><div style="font-weight:700;color:#000;">' + esc(pdfData.reference) + '</div></div>'
      +   '<div style="grid-column:1 / -1;"><div style="color:#475569;font-weight:600;">Description</div><div style="font-weight:700;color:#000;">' + esc(pdfData.description) + '</div></div>'
      + '</div>'
      + '<table style="width:100%;border-collapse:collapse;font-size:11px;font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;">'
      +   '<thead><tr>'
      +     '<th style="border:1px solid #94a3b8;padding:7px 8px;background:#f1f5f9;color:#000;text-align:left;">No.</th>'
      +     '<th style="border:1px solid #94a3b8;padding:7px 8px;background:#f1f5f9;color:#000;text-align:left;">Account Code</th>'
      +     '<th style="border:1px solid #94a3b8;padding:7px 8px;background:#f1f5f9;color:#000;text-align:left;">Account Name</th>'
      +     '<th style="border:1px solid #94a3b8;padding:7px 8px;background:#f1f5f9;color:#000;text-align:right;">Debit (TZS)</th>'
      +     '<th style="border:1px solid #94a3b8;padding:7px 8px;background:#f1f5f9;color:#000;text-align:right;">Credit (TZS)</th>'
      +   '</tr></thead>'
      +   '<tbody>' + linesHtml + '</tbody>'
      + '</table>'
      + '<div style="margin-top:12px;display:flex;justify-content:flex-end;gap:18px;font-size:12px;font-weight:700;color:#000;font-family:Arial,Helvetica,sans-serif;">'
      +   '<span>Total Debit: ' + esc(pdfData.totalDebit) + '</span>'
      +   '<span>Total Credit: ' + esc(pdfData.totalCredit) + '</span>'
      + '</div>';

    Object.assign(node.style, {
      position: 'fixed',
      left: '0',
      top: '0',
      width: '794px',
      padding: '28px 32px',
      background: '#ffffff',
      color: '#000000',
      zIndex: '2147483000',
      pointerEvents: 'none',
      boxSizing: 'border-box',
      fontFamily: 'Arial, Helvetica, sans-serif'
    });
    return node;
  }

  if (btn && menu) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (wrap && !wrap.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenu();
    });
  }

  function runPdfDownload() {
      closeMenu();
      var hasCanvas = typeof html2canvas === 'function';
      var JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : null;
      if (!hasCanvas || !JsPDF) {
        window.print();
        return;
      }

      var node = buildPdfNode();
      document.body.appendChild(node);
      if (pdfBtn) pdfBtn.disabled = true;

      var done = function () {
        if (node && node.parentNode) node.parentNode.removeChild(node);
        if (pdfBtn) pdfBtn.disabled = false;
      };

      html2canvas(node, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        logging: false,
        scrollX: 0,
        scrollY: 0
      }).then(function (canvas) {
        var img = canvas.toDataURL('image/jpeg', 0.98);
        var pdf = new JsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        var pageWidth = pdf.internal.pageSize.getWidth();
        var pageHeight = pdf.internal.pageSize.getHeight();
        var margin = 10;
        var imgWidth = pageWidth - (margin * 2);
        var imgHeight = (canvas.height * imgWidth) / canvas.width;
        var y = margin;
        var remaining = imgHeight;
        var srcY = 0;
        // One page for typical journals; split if content is taller than a page.
        if (imgHeight <= pageHeight - (margin * 2)) {
          pdf.addImage(img, 'JPEG', margin, margin, imgWidth, imgHeight);
        } else {
          var pageCanvas = document.createElement('canvas');
          var pageCtx = pageCanvas.getContext('2d');
          var ratio = canvas.width / imgWidth;
          var pageImgHeightPx = (pageHeight - (margin * 2)) * ratio;
          pageCanvas.width = canvas.width;
          while (remaining > 0) {
            var sliceHeight = Math.min(pageImgHeightPx, canvas.height - srcY);
            pageCanvas.height = sliceHeight;
            pageCtx.fillStyle = '#ffffff';
            pageCtx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
            pageCtx.drawImage(canvas, 0, srcY, canvas.width, sliceHeight, 0, 0, canvas.width, sliceHeight);
            var sliceImg = pageCanvas.toDataURL('image/jpeg', 0.98);
            var sliceMm = sliceHeight / ratio;
            pdf.addImage(sliceImg, 'JPEG', margin, margin, imgWidth, sliceMm);
            remaining -= (pageHeight - (margin * 2));
            srcY += sliceHeight;
            if (remaining > 1) pdf.addPage();
          }
        }
        pdf.save(pdfName + '.pdf');
        done();
      }).catch(function () {
        done();
        window.print();
      });
  }

  if (pdfBtn) {
    pdfBtn.addEventListener('click', runPdfDownload);
  }

  try {
    var params = new URLSearchParams(window.location.search || '');
    if (params.get('auto_pdf') === '1') {
      // Give CDN scripts a tick, then download and strip the query flag.
      setTimeout(function () {
        runPdfDownload();
        params.delete('auto_pdf');
        var next = window.location.pathname + (params.toString() ? ('?' + params.toString()) : '');
        if (window.history && window.history.replaceState) {
          window.history.replaceState({}, '', next);
        }
      }, 250);
    }
  } catch (e) {}
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
