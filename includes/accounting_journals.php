<?php

require_once __DIR__ . '/accounting_settings.php';

function accounting_journal_types(): array
{
    return [
        'general' => 'General',
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'bank' => 'Bank',
        'cash' => 'Cash',
        'payments' => 'Payments',
        'payroll' => 'Payroll',
    ];
}

function accounting_journal_seeds(): array
{
    $now = date('d M Y');

    return [
        [
            'id' => 'gen', 'name' => 'General Journal', 'code' => 'GEN', 'type' => 'general',
            'status' => 'active', 'is_default' => true,
            'description' => 'Manual adjustments, accruals, and entries that do not belong to a specialized journal.',
            'currency' => 'TZS', 'posting_sequence' => 'GEN-', 'linked_module' => 'General',
            'allow_manual' => true, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => false,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'sal', 'name' => 'Sales Journal', 'code' => 'SAL', 'type' => 'sales',
            'status' => 'active', 'is_default' => false,
            'description' => 'Sales invoices and customer credit notes posted by the Sales module.',
            'currency' => 'TZS', 'posting_sequence' => 'SAL-', 'linked_module' => 'Sales',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => false,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'pur', 'name' => 'Purchase Journal', 'code' => 'PUR', 'type' => 'purchase',
            'status' => 'active', 'is_default' => false,
            'description' => 'Supplier bills posted by the Purchasing module.',
            'currency' => 'TZS', 'posting_sequence' => 'PUR-', 'linked_module' => 'Purchasing',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => false,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'bnk', 'name' => 'Bank Journal', 'code' => 'BNK', 'type' => 'bank',
            'status' => 'active', 'is_default' => false,
            'description' => 'Bank receipts, payments, transfers, and reconciliation activity.',
            'currency' => 'TZS', 'posting_sequence' => 'BNK-', 'linked_module' => 'Balances',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => true,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'csh', 'name' => 'Cash Journal', 'code' => 'CSH', 'type' => 'cash',
            'status' => 'active', 'is_default' => false,
            'description' => 'Petty cash and physical cash movements.',
            'currency' => 'TZS', 'posting_sequence' => 'CSH-', 'linked_module' => 'Balances',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => true,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'pym', 'name' => 'Payment Voucher', 'code' => 'PAY', 'type' => 'payments',
            'status' => 'active', 'is_default' => false,
            'description' => 'Supplier payments posted through payment vouchers.',
            'currency' => 'TZS', 'posting_sequence' => 'PAY-', 'linked_module' => 'Balances',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => true,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'pay', 'name' => 'Payroll Journal', 'code' => 'PAYRL', 'type' => 'payroll',
            'status' => 'inactive', 'is_default' => false,
            'description' => 'Payroll accruals and statutory deductions. Activate after payroll posting is connected.',
            'currency' => 'TZS', 'posting_sequence' => 'PRL-', 'linked_module' => 'Payroll',
            'allow_manual' => false, 'allow_posting' => true, 'allow_reverse' => true,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => false,
            'created_by' => 'System', 'created_at' => $now,
        ],
        [
            'id' => 'opn', 'name' => 'Opening Journal', 'code' => 'OPN', 'type' => 'general',
            'status' => 'inactive', 'is_default' => false,
            'description' => 'Reserved for legacy opening balances; keep inactive and use the opening-balance workflow.',
            'currency' => 'TZS', 'posting_sequence' => 'OPN-', 'linked_module' => 'General',
            'allow_manual' => false, 'allow_posting' => false, 'allow_reverse' => false,
            'require_approval' => false, 'auto_numbering' => true, 'affects_cash' => false,
            'created_by' => 'System', 'created_at' => $now,
        ],
    ];
}

function accounting_journals_get(PDO $pdo): array
{
    $stored = accounting_settings_get($pdo, 'journals');
    if ($stored === null) {
        return accounting_journal_seeds();
    }

    $journals = json_decode($stored, true);
    if (!is_array($journals)) {
        error_log('accounting_journals_get: Invalid saved journal configuration JSON.');
        return accounting_journal_seeds();
    }

    return array_values(array_filter($journals, static function ($journal) {
        return is_array($journal) && !empty($journal['id']);
    }));
}

function accounting_journal_save(PDO $pdo, array $input, string $saveMode, ?string $id = null): array
{
    $journals = accounting_journals_get($pdo);
    $types = accounting_journal_types();
    $name = trim((string) ($input['name'] ?? ''));
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $type = strtolower(trim((string) ($input['type'] ?? '')));
    $prefix = strtoupper(trim((string) ($input['prefix'] ?? '')));
    $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
    $linkedModule = trim((string) ($input['linked_module'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $status = $saveMode === 'draft' ? 'inactive' : (string) ($input['status'] ?? 'active');
    $isDefault = !empty($input['is_default']) && $status === 'active';

    if ($name === '' || strlen($name) > 100) {
        return ['ok' => false, 'error' => 'Enter a journal name of 1 to 100 characters.'];
    }
    if (!preg_match('/^[A-Z0-9]{2,8}$/', $code)) {
        return ['ok' => false, 'error' => 'Journal code must be 2 to 8 letters or numbers.'];
    }
    if (!isset($types[$type])) {
        return ['ok' => false, 'error' => 'Choose a valid journal type.'];
    }
    if (!preg_match('/^[A-Z0-9-]{2,12}$/', $prefix)) {
        return ['ok' => false, 'error' => 'Numbering prefix must be 2 to 12 letters, numbers, or hyphens.'];
    }
    if (!in_array($currency, ['TZS', 'USD', 'KES'], true)) {
        return ['ok' => false, 'error' => 'Choose a supported currency.'];
    }
    if (!in_array($linkedModule, ['Sales', 'Purchasing', 'Balances', 'Payroll', 'General'], true)) {
        return ['ok' => false, 'error' => 'Choose a valid linked module.'];
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'error' => 'Choose a valid journal status.'];
    }
    if (strlen($description) > 1000) {
        return ['ok' => false, 'error' => 'Description must be 1,000 characters or fewer.'];
    }

    $index = null;
    foreach ($journals as $i => $journal) {
        if ($id !== null && (string) $journal['id'] === $id) {
            $index = $i;
        }
        if ((string) $journal['id'] !== $id
            && (strcasecmp((string) ($journal['code'] ?? ''), $code) === 0
                || strcasecmp((string) ($journal['posting_sequence'] ?? ''), $prefix) === 0)) {
            return ['ok' => false, 'error' => 'Journal code and numbering prefix must be unique.'];
        }
    }
    if ($id !== null && $index === null) {
        return ['ok' => false, 'error' => 'The journal could not be found. Reload the page and try again.'];
    }

    $old = $index !== null ? $journals[$index] : [];
    $journal = [
        'id' => $id ?? ('jrn_' . bin2hex(random_bytes(8))),
        'name' => $name,
        'code' => $code,
        'type' => $type,
        'status' => $status,
        'is_default' => $isDefault,
        'description' => $description,
        'currency' => $currency,
        'posting_sequence' => $prefix,
        'linked_module' => $linkedModule,
        'allow_manual' => !empty($input['allow_manual']),
        'allow_posting' => !empty($input['allow_posting']),
        'allow_reverse' => !empty($input['allow_reverse']),
        'require_approval' => !empty($input['require_approval']),
        'auto_numbering' => !empty($input['auto_numbering']),
        'affects_cash' => !empty($input['affects_cash']),
        'created_by' => (string) ($old['created_by'] ?? ($_SESSION['full_name'] ?? 'Finance User')),
        'created_at' => (string) ($old['created_at'] ?? date('d M Y, h:i A')),
    ];
    if ($index === null) {
        $journals[] = $journal;
    } else {
        $journals[$index] = $journal;
    }

    if ($isDefault) {
        foreach ($journals as &$entry) {
            $entry['is_default'] = (string) $entry['id'] === $journal['id'];
        }
        unset($entry);
    } else {
        $hasDefault = false;
        foreach ($journals as $entry) {
            if (!empty($entry['is_default']) && ($entry['status'] ?? '') === 'active') {
                $hasDefault = true;
                break;
            }
        }
        if (!$hasDefault) {
            foreach ($journals as &$entry) {
                $entry['is_default'] = $entry['id'] === 'gen' && ($entry['status'] ?? '') === 'active';
            }
            unset($entry);
        }
    }

    $json = json_encode(array_values($journals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || !accounting_settings_set($pdo, 'journals', $json)) {
        return ['ok' => false, 'error' => 'Could not save journal settings. Please try again or contact support.'];
    }

    accounting_journal_log($pdo, (string) ($journal['code'] ?? $code), $id === null ? 'Created' : 'Saved', (string) ($journal['name'] ?? $name));

    return ['ok' => true, 'journal' => $journal];
}

/** Appends one audit line (who, when, what) to the journal change log; keeps the latest 300. */
function accounting_journal_log(PDO $pdo, string $code, string $action, string $detail = ''): void
{
    $raw = accounting_settings_get($pdo, 'journals_log');
    $log = $raw !== null ? json_decode((string) $raw, true) : [];
    if (!is_array($log)) {
        $log = [];
    }
    $log[] = [
        'code' => $code,
        'action' => $action,
        'detail' => $detail,
        'by' => (string) ($_SESSION['full_name'] ?? 'Finance User'),
        'at' => date('d M Y, h:i A'),
    ];
    $json = json_encode(array_slice($log, -300), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        accounting_settings_set($pdo, 'journals_log', $json);
    }
}

/** Latest log lines for each journal code, newest first. */
function accounting_journal_log_by_code(PDO $pdo, int $perJournal = 5): array
{
    $raw = accounting_settings_get($pdo, 'journals_log');
    $log = $raw !== null ? json_decode((string) $raw, true) : [];
    $out = [];
    foreach (array_reverse(is_array($log) ? $log : []) as $row) {
        $code = (string) ($row['code'] ?? '');
        if ($code === '' || count($out[$code] ?? []) >= $perJournal) {
            continue;
        }
        $out[$code][] = [
            'text' => trim((string) ($row['action'] ?? '') . ((string) ($row['detail'] ?? '') !== '' ? ': ' . $row['detail'] : '')),
            'by' => (string) ($row['by'] ?? ''),
            'at' => (string) ($row['at'] ?? ''),
        ];
    }
    return $out;
}

/** Journals the system posts to automatically; they can never be switched off. */
function accounting_journal_is_core(array $journal): bool
{
    return in_array(strtoupper((string) ($journal['code'] ?? '')), ['GEN', 'SAL', 'PUR'], true);
}

/** Posted entries per journal code (only entries stamped with a journal). */
function accounting_journal_entry_counts(PDO $pdo): array
{
    try {
        return array_map('intval', $pdo->query('SELECT journal_code, COUNT(*) FROM gl_journal_entries WHERE journal_code IS NOT NULL GROUP BY journal_code')->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (Throwable $e) {
        return [];
    }
}

function accounting_journal_toggle_status(PDO $pdo, string $id): array
{
    $journals = accounting_journals_get($pdo);
    $found = false;
    foreach ($journals as &$journal) {
        if ((string) ($journal['id'] ?? '') !== $id) {
            continue;
        }
        if (($journal['status'] ?? '') === 'active' && accounting_journal_is_core($journal)) {
            unset($journal);
            return ['ok' => false, 'error' => 'General, Sales and Purchase journals are used by automatic posting and cannot be deactivated.'];
        }
        if (($journal['status'] ?? '') === 'active' && !empty($journal['is_default'])) {
            unset($journal);
            return ['ok' => false, 'error' => 'Set another active journal as the default before deactivating this one.'];
        }
        $journal['status'] = ($journal['status'] ?? '') === 'active' ? 'inactive' : 'active';
        $toggledCode = (string) ($journal['code'] ?? '');
        $toggledStatus = $journal['status'];
        $found = true;
        break;
    }
    unset($journal);

    if (!$found) {
        return ['ok' => false, 'error' => 'The journal could not be found.'];
    }
    $json = json_encode(array_values($journals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || !accounting_settings_set($pdo, 'journals', $json)) {
        return ['ok' => false, 'error' => 'Could not update journal status. Please try again or contact support.'];
    }
    accounting_journal_log($pdo, $toggledCode ?? '', $toggledStatus === 'active' ? 'Activated' : 'Deactivated');

    return ['ok' => true];
}

/** Postable asset (bank/cash) accounts that can be a journal's default account. */
function accounting_journal_account_options(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT id, code, name FROM erp_accounts WHERE LOWER(type) = 'asset' AND (is_postable IS NULL OR is_postable = 1) ORDER BY code, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = trim(($r['code'] !== null && $r['code'] !== '' ? $r['code'] . ' - ' : '') . $r['name']);
    }
    return $out;
}

/**
 * Updates only the safe, editable fields of an existing journal. Code, type,
 * prefix, linked module and the always-on controls (posting, reversal,
 * auto-numbering) are never changed here so references stay traceable.
 */
function accounting_journal_update_basic(PDO $pdo, string $id, array $input): array
{
    $journals = accounting_journals_get($pdo);
    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $status = (string) ($input['status'] ?? 'active');
    $isDefault = !empty($input['is_default']);

    if ($name === '' || strlen($name) > 100) {
        return ['ok' => false, 'error' => 'Enter a journal name of 1 to 100 characters.'];
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'error' => 'Choose a valid journal status.'];
    }
    if (strlen($description) > 1000) {
        return ['ok' => false, 'error' => 'Description must be 1,000 characters or fewer.'];
    }

    $index = null;
    foreach ($journals as $i => $journal) {
        if ((string) ($journal['id'] ?? '') === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return ['ok' => false, 'error' => 'The journal could not be found. Reload the page and try again.'];
    }

    $wasDefault = !empty($journals[$index]['is_default']);
    if ($status === 'inactive' && ($wasDefault || $isDefault)) {
        return ['ok' => false, 'error' => 'Set another active journal as the default before deactivating this one.'];
    }

    if (!empty($journals[$index]['affects_cash'])) {
        $accountId = (int) ($input['default_account_id'] ?? 0);
        if ($accountId > 0 && !isset(accounting_journal_account_options($pdo)[$accountId])) {
            return ['ok' => false, 'error' => 'Choose a valid bank or cash account for this journal.'];
        }
        $journals[$index]['default_account_id'] = $accountId > 0 ? $accountId : null;
    }

    if ($status === 'inactive' && accounting_journal_is_core($journals[$index])) {
        return ['ok' => false, 'error' => 'General, Sales and Purchase journals are used by automatic posting and cannot be deactivated.'];
    }

    $before = $journals[$index];
    $journals[$index]['name'] = $name;
    $journals[$index]['description'] = $description;
    $journals[$index]['status'] = $status;
    $journals[$index]['allow_manual'] = !empty($input['allow_manual']);
    $journals[$index]['allow_posting'] = true;
    $journals[$index]['allow_reverse'] = true;
    $journals[$index]['auto_numbering'] = true;
    $journals[$index]['updated_by'] = (string) ($_SESSION['full_name'] ?? 'Finance User');
    $journals[$index]['updated_at'] = date('d M Y, h:i A');
    if ($isDefault) {
        foreach ($journals as &$entry) {
            $entry['is_default'] = (string) ($entry['id'] ?? '') === $id;
        }
        unset($entry);
    }

    $json = json_encode(array_values($journals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || !accounting_settings_set($pdo, 'journals', $json)) {
        return ['ok' => false, 'error' => 'Could not save journal settings. Please try again or contact support.'];
    }

    $changes = [];
    if (($before['name'] ?? '') !== $name) {
        $changes[] = 'name "' . ($before['name'] ?? '') . '" to "' . $name . '"';
    }
    if (($before['status'] ?? '') !== $status) {
        $changes[] = 'status to ' . $status;
    }
    if (!empty($before['allow_manual']) !== !empty($input['allow_manual'])) {
        $changes[] = 'manual entries ' . (!empty($input['allow_manual']) ? 'on' : 'off');
    }
    if ((int) ($before['default_account_id'] ?? 0) !== (int) ($journals[$index]['default_account_id'] ?? 0)) {
        $changes[] = 'default account';
    }
    if (($before['description'] ?? '') !== $description) {
        $changes[] = 'description';
    }
    if ($isDefault && !$wasDefault) {
        $changes[] = 'set as default';
    }
    if ($changes) {
        accounting_journal_log($pdo, (string) ($journals[$index]['code'] ?? ''), 'Edited', implode(', ', $changes));
    }

    return ['ok' => true];
}

/** Journal code an entry posts to, derived from where it came from; reversals follow the original entry. */
function accounting_journal_code_for_source(PDO $pdo, string $sourceType, ?int $reversalOfId = null): string
{
    $s = strtolower($sourceType);
    if ($s === 'reversal' && $reversalOfId) {
        $st = $pdo->prepare('SELECT journal_code FROM gl_journal_entries WHERE id = ?');
        $st->execute([$reversalOfId]);
        $code = (string) $st->fetchColumn();
        return $code !== '' ? $code : 'GEN';
    }
    if ($s === 'opening_balance') {
        return 'OPN';
    }
    if ($s === 'payment_voucher' || strpos($s, 'payment') !== false) {
        return 'PAY';
    }
    if (strpos($s, 'invoice') !== false || strpos($s, 'sale') !== false || $s === 'revenue_entry') {
        return 'SAL';
    }
    if (strpos($s, 'purchase') !== false || strpos($s, 'bill') !== false || strpos($s, 'stock') !== false) {
        return 'PUR';
    }
    if (strpos($s, 'cash') !== false) {
        return 'CSH';
    }
    if ($s === 'account_transaction' || strpos($s, 'transfer') !== false || strpos($s, 'bank') !== false) {
        return 'BNK';
    }
    return 'GEN';
}