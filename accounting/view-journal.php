<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

global $pdo;

$id = (int) ($_GET['id'] ?? 0);
$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
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
$jeRelatedVoucherIds = [];
$jeAddRelated = static function (string $key, string $label, string $href, string $type, string $sub = '') use (&$relatedDocs, &$relatedSeen): void {
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
        'sub' => $sub,
    ];
};

$jeInvoiceCustomer = static function (string $invoiceNumber) use ($pdo, $desc): string {
    try {
        $st = $pdo->prepare('SELECT c.company_name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.invoice_number = ? LIMIT 1');
        $st->execute([$invoiceNumber]);
        $name = trim((string) ($st->fetchColumn() ?: ''));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
    }
    if (preg_match('/' . preg_quote($invoiceNumber, '/') . '\s*[-–]\s*([^()\r\n]+)/i', $desc, $m)) {
        return trim($m[1]);
    }

    return '';
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
        $jeCustomer = $jeInvoiceCustomer((string) $invNo);
        $jeAddRelated((string) $invNo, strtoupper((string) $invNo) . ' (Invoice)', $href, 'invoice', $jeCustomer !== '' ? 'Customer: ' . $jeCustomer : '');
    }
}
if (preg_match_all('/\b(PV[\/\-][A-Z0-9][A-Z0-9\/\-]*)\b/i', $scanText, $pvMatches)) {
    foreach (array_unique($pvMatches[1]) as $pvNo) {
        $pvId = $jeResolveVoucherId((string) $pvNo);
        if ($pvId > 0) {
            $jeAddRelated((string) $pvNo, (string) $pvNo . ' (Payment Voucher)', $jeVoucherViewUrl($pvId), 'voucher');
            $jeRelatedVoucherIds[] = $pvId;
        }
    }
}

$jeFormatBytes = static function (int $bytes): string {
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return (int) round($bytes / 1024) . ' KB';
    }

    return number_format($bytes / 1048576, 1) . ' MB';
};
$jeProxyUrl = static function (string $rel): string {
    $base = function_exists('app_url') ? app_url('/proxy_pdf.php') : '../proxy_pdf.php';

    return $base . '?file=' . rawurlencode(ltrim(str_replace('\\', '/', $rel), '/'));
};

/** @var list<array{name:string,meta:string,href:string,kind:string}> */
$attachments = [];
$jeUploadDir = dirname(__DIR__) . '/uploads/journal-attachments/' . $id;
if ($id > 0 && is_dir($jeUploadDir)) {
    foreach (scandir($jeUploadDir) ?: [] as $jeFile) {
        $jeAbs = $jeUploadDir . '/' . $jeFile;
        if ($jeFile === '.' || $jeFile === '..' || !is_file($jeAbs)) {
            continue;
        }
        $attachments[] = [
            'name' => $jeFile,
            'meta' => $jeFormatBytes((int) filesize($jeAbs)),
            'href' => $jeProxyUrl('uploads/journal-attachments/' . $id . '/' . $jeFile),
            'kind' => 'file',
        ];
    }
}
foreach ($relatedDocs as $jeDoc) {
    if (($jeDoc['type'] ?? '') === 'invoice') {
        $attachments[] = [
            'name' => (string) $jeDoc['label'],
            'meta' => (string) ($jeDoc['sub'] ?? '') !== '' ? (string) $jeDoc['sub'] : 'Source invoice',
            'href' => (string) $jeDoc['href'],
            'kind' => 'invoice',
        ];
    }
}
if (function_exists('getVoucherAttachments')) {
    foreach (array_unique($jeRelatedVoucherIds) as $jePvId) {
        try {
            foreach (getVoucherAttachments($jePvId) as $jeAtt) {
                $jePath = (string) ($jeAtt['file_path'] ?? '');
                if ($jePath === '') {
                    continue;
                }
                $attachments[] = [
                    'name' => (string) (($jeAtt['original_name'] ?? '') !== '' ? $jeAtt['original_name'] : basename($jePath)),
                    'meta' => trim($jeFormatBytes((int) ($jeAtt['size_bytes'] ?? 0)) . ' · Payment voucher file', ' ·'),
                    'href' => preg_match('#^https?://#i', $jePath) ? $jePath : $jeProxyUrl($jePath),
                    'kind' => 'file',
                ];
            }
        } catch (Throwable $e) {
        }
    }
}

$page_title = 'Journal Entry Details';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<?php
$jeDiff = $totalDebit - $totalCredit;
$jeIsBalanced = abs($jeDiff) < 0.005;
$jeDateRaw = !empty($journal['date']) ? strtotime((string) $journal['date']) : false;
$jeDateLabel = $jeDateRaw ? date('d/m/Y', $jeDateRaw) : '-';
$jePeriodLabel = $jeDateRaw ? date('F Y', $jeDateRaw) : '-';
$jeCreatedOnLabel = !empty($journal['created_at']) ? date('d/m/Y h:i A', strtotime((string) $journal['created_at'])) : $jeDateLabel;
$jeCreatedBy = 'System';
$jeCreatorId = (int) ($journal['created_by'] ?? 0);
if ($jeCreatorId > 0) {
    try {
        $jeUserStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $jeUserStmt->execute([$jeCreatorId]);
        $jeCreatedBy = (string) ($jeUserStmt->fetchColumn() ?: 'System');
    } catch (Throwable $e) {
    }
}
$jeStatus = strtolower(trim((string) ($journal['status'] ?? ''))) ?: 'posted';
$jeStatusRank = ['draft' => 0, 'pending' => 0, 'reviewed' => 1, 'approved' => 2, 'posted' => 3][$jeStatus] ?? 3;
$jeFlowSteps = [
    ['title' => 'Draft', 'done' => true],
    ['title' => 'Reviewed', 'done' => $jeStatusRank >= 1],
    ['title' => 'Approved', 'done' => $jeStatusRank >= 2],
    ['title' => 'Posted', 'done' => $jeStatusRank >= 3],
];
$jeEntryNo = (string) ($journal['entry_number'] ?? 'JE');
$jeAccountingHref = function_exists('company_url')
    ? company_url('modules/accounting/index.php?module=' . rawurlencode((string) $jeModule))
    : '../modules/accounting/index.php?module=' . rawurlencode((string) $jeModule);
$jeListHref = 'journal-entries.php?module=' . rawurlencode((string) $jeModule);
$jeHomeHref = function_exists('company_dashboard_url') ? company_dashboard_url() : '../select-module.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.jed-shell{--jed-bg:#f6f8fb;--jed-card:#fff;--jed-line:#e5e7eb;--jed-line-soft:#eef2f7;--jed-ink:#0f172a;--jed-muted:#64748b;--jed-soft:#f8fafc;--jed-link:#2563eb;--jed-green:#16a34a;--jed-green-ink:#15803d;--jed-green-bg:#dcfce7;--jed-green-line:#86efac;--jed-green-soft:#f0fdf4;--jed-danger:#dc2626;--jed-danger-line:#fca5a5;--jed-btn-bg:#fff;--jed-btn-line:#dbe2ea;--jed-btn-fg:#0f172a;--jed-icon-bg:#f1f5f9;--jed-icon-fg:#475569;--jed-warn-fg:#f97316}
html[data-theme="dark"] .jed-shell{--jed-bg:#0b1324;--jed-card:#0f1b2e;--jed-line:#1f2d45;--jed-line-soft:#1a273d;--jed-ink:#f1f5f9;--jed-muted:#8b9ab3;--jed-soft:#0c1628;--jed-link:#60a5fa;--jed-green:#22c55e;--jed-green-ink:#4ade80;--jed-green-bg:rgba(34,197,94,.14);--jed-green-line:rgba(34,197,94,.5);--jed-green-soft:rgba(16,185,129,.07);--jed-danger:#f87171;--jed-danger-line:rgba(248,113,113,.6);--jed-btn-bg:#0f1b2e;--jed-btn-line:#2a3a55;--jed-btn-fg:#e2e8f0;--jed-icon-bg:#16233a;--jed-icon-fg:#94a3b8;--jed-warn-fg:#fb923c}
.main-content.jed-shell{margin-top:0!important;padding:18px 0 28px!important;background:var(--jed-bg)!important;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:var(--jed-ink)}
.jed-wrap{padding:0 18px}
.jed-shell *{box-sizing:border-box}
.jed-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.jed-shell .jed-title{margin:0;font-size:30px;font-weight:800;color:var(--jed-ink)!important;line-height:1.1;letter-spacing:-.01em}
.jed-shell{--jed-bc-link:#64748b;--jed-bc-sep:#cbd5e1;--jed-bc-cur:#0f172a}
html[data-theme="dark"] .jed-shell{--jed-bc-link:#94a3b8;--jed-bc-sep:#475569;--jed-bc-cur:#e2e8f0}
.jed-bc{margin-top:8px;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:13px;font-weight:500;line-height:1.3}
.jed-bc a{text-decoration:none}
.jed-bc a:hover{text-decoration:underline}
.jed-shell .jed-bc-cur{font-weight:600}
.jed-actions{display:flex;gap:10px;flex-wrap:wrap}
.jed-btn{border:1px solid var(--jed-btn-line);background:var(--jed-btn-bg);color:var(--jed-btn-fg);border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s}
.jed-btn:hover:not(:disabled){border-color:var(--jed-muted)}
.jed-btn.danger{border-color:var(--jed-danger-line);color:var(--jed-danger)}
.jed-btn.danger:hover:not(:disabled){border-color:var(--jed-danger)}
.jed-btn:disabled{opacity:.55;cursor:not-allowed}
.jed-btn[aria-expanded="true"]{border-color:var(--jed-link)}
.jed-flash{margin:0 0 12px;padding:10px 12px;border-radius:8px;font-size:13px;font-weight:600}
.jed-flash--ok{background:var(--jed-green-soft);border:1px solid var(--jed-green-line);color:var(--jed-green-ink)}
.jed-flash--err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
html[data-theme="dark"] .jed-flash--err{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.35);color:#fca5a5}
.jed-dl-wrap{position:relative;display:inline-flex}
.jed-dl-menu{position:absolute;top:calc(100% + 6px);right:0;min-width:180px;background:var(--jed-card);border:1px solid var(--jed-line);border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.18);padding:6px;z-index:40;display:none}
.jed-dl-menu.is-open{display:block}
.jed-dl-menu a,.jed-dl-menu button{width:100%;border:0;background:transparent;display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:600;color:var(--jed-btn-fg);text-decoration:none;cursor:pointer;text-align:left;font-family:inherit}
.jed-dl-menu a:hover,.jed-dl-menu button:hover{background:var(--jed-soft)}
.jed-dl-menu i{width:16px;color:var(--jed-link)}
.jed-dl-menu{min-width:200px}
.jed-dl-menu .is-danger,.jed-dl-menu .is-danger i{color:var(--jed-danger)!important}
.jed-dl-menu button:disabled{opacity:.5;cursor:not-allowed}
.jed-dl-menu button:disabled:hover{background:transparent}
.jed-dl-sep{height:1px;background:var(--jed-line);margin:4px 2px}
.jed-btn.jed-kebab{width:38px;height:38px;padding:0;border-radius:10px!important}

.jed-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.jed-col{display:flex;flex-direction:column;gap:16px;min-width:0}
.jed-card{background:var(--jed-card);border:1px solid var(--jed-line);border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);overflow:hidden}
html[data-theme="dark"] body .main-content.jed-shell{background:var(--jed-bg)!important}
html[data-theme="dark"] .jed-shell .jed-card{background:var(--jed-card)!important;border-color:var(--jed-line)!important;box-shadow:none}

.jed-hero-top{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:18px;padding:16px 18px}
.jed-hero-id{min-width:0;padding-right:4px}
.jed-shell .jed-tag{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:3px 10px 3px 4px;font-size:12px;font-weight:700;background:var(--jed-green-bg);color:var(--jed-green-ink)!important;border:1px solid var(--jed-green-line)}
.jed-tag i{width:18px;height:18px;border-radius:50%;background:var(--jed-green);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px}
.jed-hero-name{display:flex;align-items:center;gap:12px;margin-top:12px}
.jed-status{width:44px;height:44px;border-radius:10px;background:var(--jed-green-bg);color:var(--jed-green-ink);display:inline-flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.jed-ref{font-size:30px;font-weight:800;line-height:1.05;color:var(--jed-ink);letter-spacing:-.01em}
.jed-type{font-size:13px;color:var(--jed-muted);margin-top:4px}
.jed-hero-desc{margin-top:14px;font-size:13px;line-height:1.55;color:var(--jed-ink)}
.jed-inv-link{color:var(--jed-link);font-weight:600;text-decoration:none}
.jed-inv-link:hover{text-decoration:underline}

.jed-hero-side{display:flex;flex-direction:column;gap:14px;border-left:1px solid var(--jed-line-soft);padding-left:18px;min-width:0}
.jed-dates{display:grid;grid-template-columns:1fr 1fr}
.jed-dates>div+div{border-left:1px solid var(--jed-line-soft);padding-left:14px}
.jed-k{font-size:11px;color:var(--jed-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.jed-v{font-size:13.5px;color:var(--jed-ink);font-weight:600;margin-top:6px;display:flex;align-items:center;gap:8px;min-width:0}
.jed-v i{color:var(--jed-muted);font-size:13px}

.jed-bal{border:1px solid var(--jed-green-line);background:var(--jed-green-soft);border-radius:10px;overflow:hidden}
.jed-bal.is-off{border-color:#fca5a5;background:rgba(239,68,68,.05)}
.jed-bal-hd{display:flex;align-items:center;gap:8px;padding:10px 12px;font-size:13px;font-weight:700;color:var(--jed-green-ink);border-bottom:1px solid var(--jed-green-line)}
.jed-bal.is-off .jed-bal-hd{color:var(--jed-danger);border-bottom-color:#fca5a5}
.jed-bal-hd i{width:22px;height:22px;border-radius:50%;background:var(--jed-green);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px}
.jed-bal.is-off .jed-bal-hd i{background:var(--jed-danger)}
.jed-bal-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
.jed-bal-grid>div{padding:10px 12px;min-width:0}
.jed-bal-grid>div+div{border-left:1px solid var(--jed-green-line)}
.jed-bal.is-off .jed-bal-grid>div+div{border-left-color:#fca5a5}
.jed-bal-v{font-size:13px;font-weight:700;color:var(--jed-ink);margin-top:5px;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}

.jed-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-top:1px solid var(--jed-line-soft)}
.jed-fact{display:flex;gap:10px;align-items:flex-start;padding:14px 18px;min-width:0}
.jed-fact+.jed-fact{border-left:1px solid var(--jed-line-soft)}
.jed-fact>i{color:var(--jed-muted);font-size:15px;margin-top:2px;width:16px;text-align:center}
.jed-fact .jed-v{margin-top:4px;display:block;overflow-wrap:anywhere}

.jed-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px;border-bottom:1px solid var(--jed-line)}
.jed-tabs button{border:0;background:transparent;padding:13px 14px;font-size:13px;font-weight:600;color:var(--jed-muted);border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;font-family:inherit}
.jed-tabs button.active{color:var(--jed-link);border-bottom-color:var(--jed-link)}
.jed-tabs button:hover{color:var(--jed-link)}
.jed-pan{padding:18px;display:none}
.jed-pan.is-active{display:block}
.jed-sec+.jed-sec{margin-top:18px;padding-top:18px;border-top:1px solid var(--jed-line-soft)}
.jed-sec-hd{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:var(--jed-ink)}
.jed-sec-hd i{color:var(--jed-muted);font-size:15px;width:16px;text-align:center}
.jed-sec-bd{margin-top:10px;font-size:13px;line-height:1.6;color:var(--jed-ink)}
.jed-sec-bd.muted{color:var(--jed-muted)}

.jed-lines-hd{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.jed-lines-count{font-size:12px;font-weight:600;color:var(--jed-muted);background:var(--jed-icon-bg);border-radius:999px;padding:3px 10px}
.jed-table-wrap{overflow:auto;border:1px solid var(--jed-line);border-radius:10px}
.jed-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:480px}
.jed-shell .jed-table,.jed-shell .jed-table thead,.jed-shell .jed-table tbody,.jed-shell .jed-table tfoot,.jed-shell .jed-table tr{background:transparent!important}
.jed-line-meta{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:6px}
.jed-line-meta:empty{display:none}
.jed-line-meta b{font-size:11.5px;font-weight:500;color:var(--jed-muted);display:inline-flex;align-items:center;gap:5px}
.jed-line-meta i{font-size:11px;opacity:.8}
.jed-shell .jed-table .jed-empty{text-align:center;padding:26px;color:var(--jed-muted)!important}
.jed-shell .jed-table th,.jed-shell .jed-table td{padding:12px 14px;border-bottom:1px solid var(--jed-line-soft);vertical-align:top;color:var(--jed-ink)!important;background:transparent!important}
.jed-shell .jed-table thead th{font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--jed-muted)!important;font-weight:700;background:var(--jed-soft)!important;text-align:left;white-space:nowrap;vertical-align:middle;padding-top:11px;padding-bottom:11px}
.jed-shell .jed-table tbody tr:hover td{background:var(--jed-soft)!important}
.jed-shell .jed-table tbody tr:last-child td{border-bottom:0}
.jed-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.jed-shell .jed-table .c-no{width:44px;color:var(--jed-muted)!important;font-weight:600}
.jed-shell .jed-table .c-muted{color:var(--jed-muted)!important;white-space:nowrap}
.jed-shell .jed-table .c-zero{color:var(--jed-muted)!important;opacity:.6}
.jed-shell .jed-table .c-amt{font-weight:700}
.jed-table .c-desc{min-width:220px;width:100%}
.jed-line-desc{line-height:1.5;color:var(--jed-muted);font-size:12.5px;margin-top:6px}
.jed-acct{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.jed-acct b{font-weight:inherit}
.jed-acct-code{display:inline-flex;font-size:11.5px;font-weight:700!important;font-variant-numeric:tabular-nums;color:var(--jed-link);background:var(--jed-icon-bg);border-radius:6px;padding:1px 7px}
.jed-acct-name{font-weight:600!important;color:var(--jed-ink)}
.jed-shell .jed-table tfoot td{background:var(--jed-soft)!important;border-top:1px solid var(--jed-line);border-bottom:0;font-weight:800;vertical-align:middle}
.jed-shell .jed-table .jed-tot-label{text-align:right;text-transform:uppercase;font-size:11px;letter-spacing:.04em;color:var(--jed-muted)!important}
.jed-lines-bal{margin-top:14px}
.jed-bal-v.is-ok{color:var(--jed-green-ink)}
.jed-bal-v.is-bad{color:var(--jed-danger)}
.jed-footer-tot{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px;padding:12px 14px;background:var(--jed-soft);border:1px solid var(--jed-line);border-radius:10px}
.jed-footer-tot .n{font-size:12px;color:var(--jed-muted)}
.jed-footer-tot .v{font-size:19px;font-weight:800;color:var(--jed-ink);margin-top:2px}
.jed-footer-tot .ok{color:var(--jed-green-ink)}
.jed-footer-tot-right{max-width:560px;margin-left:auto}
.jed-ok{margin-top:10px;padding:10px 12px;border-radius:8px;background:var(--jed-green-soft);border:1px solid var(--jed-green-line);color:var(--jed-green-ink);font-size:13px;font-weight:600}

.jed-hd{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--jed-line-soft)}
.jed-hd-title{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;color:var(--jed-ink)}
.jed-shell .jed-hd-ico{width:30px;height:30px;border-radius:8px;background:var(--jed-icon-bg);color:var(--jed-icon-fg)!important;display:inline-flex;align-items:center;justify-content:center;font-size:13px}
.jed-bd{padding:14px 16px}
.jed-kv{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;font-size:13px}
.jed-shell .jed-kv>span:first-child{color:var(--jed-muted)!important}
.jed-shell .jed-kv>span:last-child{font-weight:700;color:var(--jed-ink)!important;text-align:right}
.jed-shell .jed-kv>span.jed-kv-good{color:var(--jed-green-ink)!important}
.jed-shell .jed-pill{display:inline-flex;align-items:center;padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:700;background:var(--jed-green-bg);color:var(--jed-green-ink)!important;border:1px solid var(--jed-green-line)}
.jed-shell .jed-pill.is-off{background:rgba(239,68,68,.1);color:var(--jed-danger)!important;border-color:#fca5a5}

.jed-flow{position:relative}
.jed-flow-row{position:relative;display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:4px 12px;align-items:start;padding-bottom:16px}
.jed-flow-row:last-child{padding-bottom:0}
.jed-flow-row:not(:last-child)::before{content:"";position:absolute;left:10px;top:22px;bottom:2px;width:2px;background:var(--jed-green);opacity:.55}
.jed-dot{width:22px;height:22px;border-radius:50%;background:var(--jed-green);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;line-height:1;position:relative;z-index:1}
.jed-flow-row.is-pending .jed-dot{background:var(--jed-line);color:var(--jed-muted)}
.jed-flow-row:has(+ .is-pending)::before{background:var(--jed-line);opacity:1}
.jed-flow-title{font-size:13px;font-weight:700;color:var(--jed-ink);line-height:1.2;margin-top:2px}
.jed-flow-by{font-size:11.5px;color:var(--jed-muted);margin-top:3px}
.jed-flow-time{font-size:11.5px;color:var(--jed-muted);white-space:nowrap;margin-top:3px}

.jed-rel-list{display:flex;flex-direction:column;gap:12px}
.jed-rel-item a{font-size:13px;text-decoration:none;font-weight:600}
.jed-rel-item a:hover{text-decoration:underline}
.jed-rel-sub{font-size:12px;color:var(--jed-muted);margin-top:4px}
.jed-rel-empty{font-size:12.5px;color:var(--jed-muted)}
.jed-rel-more{display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--jed-line-soft)}
.jed-rel-more.is-open{display:block}
.jed-rel-btn{width:100%;margin-top:14px}

.jed-att-list{display:flex;flex-direction:column;gap:10px}
.jed-att-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--jed-line);border-radius:10px;background:var(--jed-soft);text-decoration:none!important;transition:border-color .15s,background .15s,transform .15s}
.jed-att-row:hover{border-color:var(--jed-link);background:var(--jed-card)}
.jed-att-row:hover .jed-att-name{color:var(--jed-link)}
.jed-att-ico{width:38px;height:38px;border-radius:9px;background:var(--jed-icon-bg);color:var(--jed-link);display:inline-flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.jed-att-main{min-width:0;flex:1 1 auto}
.jed-att-name{font-size:13.5px;color:var(--jed-ink);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:color .15s}
.jed-att-meta{font-size:12px;color:var(--jed-muted);margin-top:3px}
.jed-att-open{color:var(--jed-muted);font-size:13px;flex-shrink:0}
.jed-att-row:hover .jed-att-open{color:var(--jed-link)}
.jed-att-empty{display:flex;flex-direction:column;align-items:center;gap:8px;padding:28px 12px;color:var(--jed-muted);font-size:13px;border:1px dashed var(--jed-line);border-radius:10px}
.jed-att-empty i{font-size:22px;opacity:.7}

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
html[data-theme="dark"] .jed-print-sheet h1,html[data-theme="dark"] .jed-print-sheet span,html[data-theme="dark"] .jed-print-sheet td,html[data-theme="dark"] .jed-print-sheet th,html[data-theme="dark"] .jed-print-sheet .v{color:#0f172a!important}
html[data-theme="dark"] .jed-print-sheet p.sub,html[data-theme="dark"] .jed-print-sheet .k{color:#64748b!important}
@media print{
  body *{visibility:hidden!important}
  .jed-print-sheet,.jed-print-sheet *{visibility:visible!important}
  .jed-print-sheet{position:static!important;left:auto!important;width:auto!important;padding:0!important}
}

@media(max-width:1180px){
  .jed-hero-top{grid-template-columns:1fr}
  .jed-hero-side{border-left:0;padding-left:0;border-top:1px solid var(--jed-line-soft);padding-top:14px}
  .jed-facts{grid-template-columns:1fr 1fr}
  .jed-fact:nth-child(3){border-left:0}
  .jed-fact:nth-child(n+3){border-top:1px solid var(--jed-line-soft)}
}
@media(max-width:900px){.jed-grid{grid-template-columns:1fr}}
@media(max-width:700px){.jed-facts{grid-template-columns:1fr 1fr}.jed-fact:nth-child(3){border-left:0}.jed-fact:nth-child(n+3){border-top:1px solid var(--jed-line-soft)}.jed-footer-tot{grid-template-columns:1fr}.jed-ref{font-size:24px}.jed-title{font-size:24px}}
@media(max-width:600px){
  .jed-top{gap:12px;margin-bottom:12px}
  .jed-top{flex-wrap:nowrap}
  .jed-top>div:first-child{min-width:0}
  .jed-title{font-size:22px}
  .jed-hero-top{padding:14px;gap:14px}
  .jed-ref{font-size:22px}
  .jed-bal-grid{grid-template-columns:1fr}
  .jed-bal-grid>div{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .jed-bal-grid>div+div{border-left:0;border-top:1px solid var(--jed-green-line)}
  .jed-bal.is-off .jed-bal-grid>div+div{border-top-color:#fca5a5}
  .jed-bal-v{margin-top:0;white-space:nowrap}
  .jed-fact{padding:12px 14px}
  .jed-tabs{flex-wrap:nowrap;overflow-x:auto;gap:2px;padding:0 6px;scrollbar-width:none}
  .jed-tabs::-webkit-scrollbar{display:none}
  .jed-tabs button{flex-shrink:0;white-space:nowrap;padding:12px 10px}
  .jed-table-wrap{border:0;border-radius:0;overflow:visible}
  .jed-table{min-width:0}
  .jed-table thead{display:none}
  .jed-table,.jed-table tbody,.jed-table tfoot{display:block}
  .jed-table tbody tr,.jed-table tfoot tr{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px;padding:12px;border:1px solid var(--jed-line);border-radius:10px;margin-bottom:10px}
  .jed-shell .jed-table tbody td,.jed-shell .jed-table tfoot td{display:block;padding:0;border:0;width:auto;min-width:0}
  .jed-shell .jed-table tbody tr:hover td{background:transparent!important}
  .jed-shell .jed-table tbody td.c-no{display:none}
  .jed-table .c-desc{grid-column:1/-1}
  .jed-shell .jed-table td.num{text-align:left;white-space:normal}
  .jed-shell .jed-table td.num::before{content:attr(data-label);display:block;font-size:11px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:var(--jed-muted);margin-bottom:3px}
  .jed-shell .jed-table tbody td[colspan]{grid-column:1/-1}
  .jed-shell .jed-table tfoot tr{background:var(--jed-soft)!important;margin-bottom:0}
  .jed-shell .jed-table tfoot td{background:transparent!important}
  .jed-shell .jed-table tfoot .jed-tot-label{grid-column:1/-1;text-align:left}
  .jed-pan{padding:14px}
}
</style>

<main class="main-content jed-shell">
  <div class="jed-wrap">
    <div class="jed-top">
      <div>
        <h1 class="jed-title">Journal Entry Details</h1>
        <nav class="jed-bc" aria-label="Breadcrumb">
          <a href="<?= htmlspecialchars($jeHomeHref, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--jed-bc-link)!important">Home</a>
          <span class="jed-bc-sep" aria-hidden="true" style="color:var(--jed-bc-sep)!important">/</span>
          <a href="<?= htmlspecialchars($jeAccountingHref, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--jed-bc-link)!important">Accounting</a>
          <span class="jed-bc-sep" aria-hidden="true" style="color:var(--jed-bc-sep)!important">/</span>
          <a href="<?= htmlspecialchars($jeListHref, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--jed-bc-link)!important">Journal Entries</a>
          <span class="jed-bc-sep" aria-hidden="true" style="color:var(--jed-bc-sep)!important">/</span>
          <span class="jed-bc-cur" aria-current="page" style="color:var(--jed-bc-cur)!important"><?= htmlspecialchars($jeEntryNo) ?></span>
        </nav>
      </div>
      <div class="jed-actions">
        <?php
          $jeStatusLower = strtolower((string) ($journal['status'] ?? 'posted'));
          $canEditEntry = $jeStatusLower !== 'locked';
          $editUrl = 'create-journal.php?id=' . (int) $id . '&module=' . rawurlencode((string) $jeModule);
        ?>
        <div class="jed-dl-wrap" id="jedDownloadWrap">
          <button type="button" class="jed-btn jed-kebab" id="jedDownloadBtn" aria-haspopup="true" aria-expanded="false" title="Actions" aria-label="Journal entry actions">
            <i class="fas fa-ellipsis-vertical"></i>
          </button>
          <div class="jed-dl-menu" id="jedDownloadMenu" role="menu">
            <?php if ($canEditEntry): ?>
              <a role="menuitem" style="color:var(--jed-btn-fg)!important" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="far fa-pen-to-square"></i> Edit</a>
            <?php else: ?>
              <button type="button" role="menuitem" disabled title="Locked entries cannot be edited"><i class="far fa-pen-to-square"></i> Edit</button>
            <?php endif; ?>
            <button type="button" role="menuitem" id="jedDownloadPdf"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <a role="menuitem" style="color:var(--jed-btn-fg)!important" href="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>&download=journal"><i class="fas fa-file-csv"></i> Download CSV</a>
            <div class="jed-dl-sep" role="separator"></div>
            <?php if ($canReverse): ?>
              <form method="post" action="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>" style="margin:0;" onsubmit="return confirm('Post a reversing journal entry for <?= htmlspecialchars($entryNumber, ENT_QUOTES, 'UTF-8') ?>? Debits and credits will be swapped.');">
                <input type="hidden" name="reverse_entry" value="1">
                <button type="submit" role="menuitem" class="is-danger"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
              </form>
            <?php elseif ($existingReversalId > 0): ?>
              <a role="menuitem" class="is-danger" style="color:var(--jed-danger)!important" href="?id=<?= (int) $existingReversalId ?>&module=<?= urlencode((string) $jeModule) ?>"><i class="fas fa-rotate-left"></i> View Reversal</a>
            <?php else: ?>
              <button type="button" role="menuitem" class="is-danger" disabled title="<?= $isReversalEntry ? 'This entry is already a reversal' : 'Nothing to reverse' ?>"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
            <?php endif; ?>
          </div>
        </div>
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
      <div class="jed-col">
        <section class="jed-card">
          <div class="jed-hero-top">
            <div class="jed-hero-id">
              <div class="jed-hero-name" style="margin-top:0">
                <div class="jed-status"><i class="far fa-file-lines"></i></div>
                <div>
                  <div class="jed-ref"><?= htmlspecialchars($jeEntryNo) ?></div>
                  <div class="jed-type"><?= htmlspecialchars($journalType) ?></div>
                </div>
              </div>
              <div class="jed-hero-desc"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : 'Journal entry' ?></div>
            </div>
            <div class="jed-hero-side">
              <div class="jed-dates">
                <div><div class="jed-k">Transaction Date</div><div class="jed-v"><i class="far fa-calendar"></i><?= htmlspecialchars($jeDateLabel) ?></div></div>
                <div><div class="jed-k">Posting Date</div><div class="jed-v"><i class="far fa-calendar"></i><?= htmlspecialchars($jeDateLabel) ?></div></div>
              </div>
              <div class="jed-bal<?= $jeIsBalanced ? '' : ' is-off' ?>">
                <div class="jed-bal-hd"><i class="fas <?= $jeIsBalanced ? 'fa-check' : 'fa-exclamation' ?>"></i> <?= $jeIsBalanced ? 'Balanced' : 'Unbalanced' ?></div>
                <div class="jed-bal-grid">
                  <div><div class="jed-k">Debit</div><div class="jed-bal-v">TZS <?= number_format($totalDebit, 2) ?></div></div>
                  <div><div class="jed-k">Credit</div><div class="jed-bal-v">TZS <?= number_format($totalCredit, 2) ?></div></div>
                  <div><div class="jed-k">Difference</div><div class="jed-bal-v">TZS <?= number_format(abs($jeDiff), 2) ?></div></div>
                </div>
              </div>
            </div>
          </div>
          <div class="jed-facts">
            <div class="jed-fact"><i class="far fa-calendar"></i><div><div class="jed-k">Period</div><div class="jed-v"><?= htmlspecialchars($jePeriodLabel) ?></div></div></div>
            <div class="jed-fact"><i class="far fa-file-lines"></i><div><div class="jed-k">Reference No.</div><div class="jed-v"><?= htmlspecialchars((string) ($journal['entry_number'] ?? '-')) ?></div></div></div>
            <div class="jed-fact"><i class="far fa-user"></i><div><div class="jed-k">Created By</div><div class="jed-v"><?= htmlspecialchars($jeCreatedBy) ?></div></div></div>
            <div class="jed-fact"><i class="far fa-clock"></i><div><div class="jed-k">Created On</div><div class="jed-v"><?= htmlspecialchars($jeCreatedOnLabel) ?></div></div></div>
          </div>
        </section>

        <section class="jed-card" id="jedTabsCard">
          <div class="jed-tabs" role="tablist" aria-label="Journal details">
            <button class="active" type="button" role="tab" aria-selected="true" data-jed-tab="overview">Overview</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="lines">Journal Lines</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="attachments">Attachments (<?= count($attachments) ?>)</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="workflow">Approval Workflow</button>
          </div>

          <div class="jed-pan is-active" data-jed-panel="overview" role="tabpanel">
            <div class="jed-sec">
              <div class="jed-sec-hd"><i class="far fa-file-lines"></i> Description</div>
              <div class="jed-sec-bd"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : '—' ?></div>
            </div>
            <div class="jed-sec">
              <div class="jed-sec-hd"><i class="far fa-note-sticky"></i> Notes</div>
              <div class="jed-sec-bd muted">Payment details and supporting remarks captured on post.</div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="lines" role="tabpanel" hidden>
            <div class="jed-lines-hd">
              <div class="jed-sec-hd"><i class="fas fa-list-ul"></i> Journal Lines</div>
              <div class="jed-lines-count"><?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?></div>
            </div>
            <div class="jed-table-wrap">
              <table class="jed-table">
                <thead>
                  <tr>
                    <th class="c-no">#</th>
                    <th>Account &amp; Description</th>
                    <th class="num">Debit (TZS)</th>
                    <th class="num">Credit (TZS)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="jed-empty">No journal lines found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                      <?php
                        $lnDebit = (float) ($item['debit'] ?? 0);
                        $lnCredit = (float) ($item['credit'] ?? 0);
                      ?>
                      <tr>
                        <td class="c-no"><?= $idx + 1 ?></td>
                        <?php
                          $lnProject = trim((string) ($item['project'] ?? ''));
                        ?>
                        <td class="c-desc">
                          <div class="jed-acct">
                            <b class="jed-acct-code"><?= htmlspecialchars((string) ($item['code'] ?? '-')) ?></b>
                            <b class="jed-acct-name"><?= htmlspecialchars((string) ($item['name'] ?? '-')) ?></b>
                          </div>
                          <div class="jed-line-desc"><?= $jeLinkifyInvoiceRefs((string) ($item['line_desc'] ?? ($desc !== '' ? $desc : '-'))) ?></div>
                          <?php if ($lnProject !== '' && $lnProject !== '-'): ?>
                            <div class="jed-line-meta"><b><i class="far fa-folder"></i> <?= htmlspecialchars($lnProject) ?></b></div>
                          <?php endif; ?>
                        </td>
                        <td class="num<?= $lnDebit > 0 ? ' c-amt' : ' c-zero' ?>" data-label="Debit (TZS)"><?= $lnDebit > 0 ? number_format($lnDebit, 2) : '—' ?></td>
                        <td class="num<?= $lnCredit > 0 ? ' c-amt' : ' c-zero' ?>" data-label="Credit (TZS)"><?= $lnCredit > 0 ? number_format($lnCredit, 2) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
                <?php if (!empty($items)): ?>
                  <tfoot>
                    <tr>
                      <td colspan="2" class="jed-tot-label">Total</td>
                      <td class="num jed-tot-v" data-label="Debit (TZS)"><?= number_format($totalDebit, 2) ?></td>
                      <td class="num jed-tot-v" data-label="Credit (TZS)"><?= number_format($totalCredit, 2) ?></td>
                    </tr>
                  </tfoot>
                <?php endif; ?>
              </table>
            </div>
            <div class="jed-bal jed-lines-bal<?= $jeIsBalanced ? '' : ' is-off' ?>">
              <div class="jed-bal-hd"><i class="fas <?= $jeIsBalanced ? 'fa-check' : 'fa-exclamation' ?>"></i> <?= $jeIsBalanced ? 'Debits and credits are balanced' : 'Debits and credits do not balance' ?></div>
              <div class="jed-bal-grid">
                <div><div class="jed-k">Total Debit</div><div class="jed-bal-v">TZS <?= number_format($totalDebit, 2) ?></div></div>
                <div><div class="jed-k">Total Credit</div><div class="jed-bal-v">TZS <?= number_format($totalCredit, 2) ?></div></div>
                <div><div class="jed-k">Difference</div><div class="jed-bal-v<?= $jeIsBalanced ? ' is-ok' : ' is-bad' ?>">TZS <?= number_format(abs($jeDiff), 2) ?></div></div>
              </div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="attachments" role="tabpanel" hidden>
            <?php if (!empty($attachments)): ?>
              <div class="jed-att-list">
                <?php foreach ($attachments as $att): ?>
                  <?php
                    $attExt = strtolower(pathinfo((string) $att['name'], PATHINFO_EXTENSION));
                    if ($att['kind'] === 'invoice') {
                        $attIcon = 'fa-file-invoice';
                    } elseif ($attExt === 'pdf') {
                        $attIcon = 'fa-file-pdf';
                    } elseif (in_array($attExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $attIcon = 'fa-file-image';
                    } elseif (in_array($attExt, ['xls', 'xlsx', 'csv'], true)) {
                        $attIcon = 'fa-file-excel';
                    } else {
                        $attIcon = 'fa-file-lines';
                    }
                  ?>
                  <a class="jed-att-row" href="<?= htmlspecialchars((string) $att['href'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="color:var(--jed-ink)!important">
                    <div class="jed-att-ico"><i class="far <?= $attIcon ?>"></i></div>
                    <div class="jed-att-main">
                      <div class="jed-att-name"><?= htmlspecialchars((string) $att['name']) ?></div>
                      <?php if ((string) $att['meta'] !== ''): ?><div class="jed-att-meta"><?= htmlspecialchars((string) $att['meta']) ?></div><?php endif; ?>
                    </div>
                    <i class="fas fa-arrow-up-right-from-square jed-att-open" aria-hidden="true"></i>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="jed-att-empty">
                <i class="far fa-folder-open"></i>
                <div>No attachments for this journal entry.</div>
              </div>
            <?php endif; ?>
          </div>

          <div class="jed-pan" data-jed-panel="workflow" role="tabpanel" hidden>
            <div class="jed-flow">
              <?php foreach ($jeFlowSteps as $step): ?>
              <div class="jed-flow-row<?= $step['done'] ? '' : ' is-pending' ?>">
                <i class="jed-dot fas <?= $step['done'] ? 'fa-check' : 'fa-hourglass-half' ?>"></i>
                <div>
                  <div class="jed-flow-title"><?= htmlspecialchars($step['title']) ?></div>
                  <div class="jed-flow-by"><?= $step['done'] ? htmlspecialchars($jeCreatedBy) : 'Pending' ?></div>
                </div>
                <div class="jed-flow-time"><?= $step['done'] ? htmlspecialchars($jeCreatedOnLabel) : '—' ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      </div>

      <aside class="jed-col">
        <section class="jed-card">
          <div class="jed-hd">
            <div class="jed-hd-title"><span class="jed-hd-ico" aria-hidden="true"><i class="far fa-file-lines"></i></span> Journal Summary</div>
            <span class="jed-pill<?= $jeIsBalanced ? '' : ' is-off' ?>"><?= $jeIsBalanced ? 'Balanced' : 'Unbalanced' ?></span>
          </div>
          <div class="jed-bd">
            <div class="jed-kv"><span>Journal</span><span><?= htmlspecialchars($journalType) ?></span></div>
            <div class="jed-kv"><span>Status</span><span><span class="jed-pill<?= $jeStatusRank >= 3 ? '' : ' is-off' ?>"><?= htmlspecialchars(ucfirst($jeStatus)) ?></span></span></div>
            <div class="jed-kv"><span>Debit (TZS)</span><span><?= number_format($totalDebit, 2) ?></span></div>
            <div class="jed-kv"><span>Credit (TZS)</span><span><?= number_format($totalCredit, 2) ?></span></div>
            <div class="jed-kv" style="margin-bottom:0;"><span>Difference</span><span class="jed-kv-good"><?= number_format($jeDiff, 2) ?></span></div>
          </div>
        </section>

        <section class="jed-card jed-rel" id="jedRelatedCard">
          <div class="jed-hd">
            <div class="jed-hd-title"><span class="jed-hd-ico" aria-hidden="true"><i class="fas fa-link"></i></span> Related Documents</div>
          </div>
          <div class="jed-bd">
            <?php if (empty($relatedDocs)): ?>
              <div class="jed-rel-empty">No related documents linked to this journal entry.</div>
            <?php else: ?>
              <?php
              $primary = $relatedDocs[0];
              $extraDocs = array_slice($relatedDocs, 1);
              ?>
              <div class="jed-rel-list">
                <div class="jed-rel-item">
                  <a href="<?= htmlspecialchars($primary['href'], ENT_QUOTES, 'UTF-8') ?>" style="color:var(--jed-link)!important"><?= htmlspecialchars($primary['label'], ENT_QUOTES, 'UTF-8') ?></a>
                  <?php if (!empty($primary['sub'])): ?><div class="jed-rel-sub"><?= htmlspecialchars($primary['sub'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                </div>
              </div>
              <?php if (!empty($extraDocs)): ?>
                <div class="jed-rel-more" id="jedRelatedMore" hidden>
                  <div class="jed-rel-list">
                    <?php foreach ($extraDocs as $doc): ?>
                      <div class="jed-rel-item">
                        <a href="<?= htmlspecialchars($doc['href'], ENT_QUOTES, 'UTF-8') ?>" style="color:var(--jed-link)!important"><?= htmlspecialchars($doc['label'], ENT_QUOTES, 'UTF-8') ?></a>
                        <?php if (!empty($doc['sub'])): ?><div class="jed-rel-sub"><?= htmlspecialchars($doc['sub'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <button
                type="button"
                class="jed-btn jed-rel-btn"
                id="jedRelatedToggle"
                aria-expanded="false"
                aria-controls="jedRelatedMore"
                data-related-count="<?= (int) count($relatedDocs) ?>"
              ><b class="jed-rel-btn-label">View All Related<?= count($relatedDocs) > 1 ? ' (' . count($relatedDocs) . ')' : '' ?></b> <i class="fas fa-arrow-right"></i></button>
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
        var label = toggle.querySelector('.jed-rel-btn-label') || toggle;
        label.textContent = open ? 'Hide Related' : ('View All Related (' + (toggle.getAttribute('data-related-count') || '') + ')');
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
