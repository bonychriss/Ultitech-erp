<?php
/**
 * Bridge from operational records to the double-entry ledger (gl_journal_*).
 *
 * Sources (all read-only here; nothing in them is modified):
 *  - financial_accounts.opening_balance  -> opening entries against Opening Balance Equity
 *  - account_transactions                -> money movements (grouped per document and date)
 *  - revenue_entries                     -> revenue recognition (Dr Receivable / Cr Revenue / Cr VAT)
 *
 * Each source row is linked to the entry that represents it, with a fingerprint of the values posted.
 * When a source row is later edited, deleted or no longer qualifies, the linked entry is reversed and
 * the current values are posted again, so history is preserved and balances always follow the source.
 */

require_once __DIR__ . '/gl_core.php';
if (is_file(dirname(__DIR__) . '/fa_gl_linking.php')) {
    require_once dirname(__DIR__) . '/fa_gl_linking.php';
}
if (is_file(dirname(__DIR__) . '/accounting_settings.php')) {
    require_once dirname(__DIR__) . '/accounting_settings.php';
}

if (!function_exists('gl_bridge_nature')) {
    /** Accounting nature of a financial_accounts.type. */
    function gl_bridge_nature(string $faType): string
    {
        $t = strtolower(trim($faType));
        if (in_array($t, ['expense', 'expenses', 'cogs', 'cost'], true)) {
            return 'expense';
        }
        if (in_array($t, ['revenue', 'income', 'sales'], true)) {
            return 'revenue';
        }
        if (in_array($t, ['liability', 'liabilities', 'payable'], true)) {
            return 'liability';
        }
        if ($t === 'equity') {
            return 'equity';
        }
        return 'asset';
    }
}

if (!function_exists('gl_bridge_fa_rows')) {
    /** @return array<int, array<string, mixed>> */
    function gl_bridge_fa_rows(PDO $pdo, bool $refresh = false): array
    {
        static $cache = [];
        $oid = spl_object_id($pdo);
        if ($refresh || !isset($cache[$oid])) {
            $rows = [];
            $hasChildren = [];
            foreach ($pdo->query('SELECT parent_id FROM financial_accounts WHERE parent_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $hasChildren[(int) $pid] = true;
            }
            foreach ($pdo->query('SELECT * FROM financial_accounts')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['_has_children'] = !empty($hasChildren[(int) $r['id']]);
                $r['_nature'] = gl_bridge_nature((string) $r['type']);
                $rows[(int) $r['id']] = $r;
            }
            $cache[$oid] = $rows;
        }
        return $cache[$oid];
    }
}

if (!function_exists('gl_bridge_fa_gl_account')) {
    /** GL account behind a financial account. Falls back to Suspense, never to an unrelated account. */
    function gl_bridge_fa_gl_account(PDO $pdo, int $faId): int
    {
        static $cache = [];
        $ck = spl_object_id($pdo) . ':' . $faId;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        $fa = gl_bridge_fa_rows($pdo)[$faId] ?? null;
        $glId = 0;
        if ($fa) {
            $linked = (int) ($fa['gl_account_id'] ?? 0);
            if ($linked > 0) {
                $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE id = ? LIMIT 1');
                $st->execute([$linked]);
                $glId = (int) ($st->fetchColumn() ?: 0);
            }
            if ($glId <= 0 && function_exists('fa_gl_link_financial_account')) {
                $glId = (int) (fa_gl_link_financial_account($pdo, $faId) ?: 0);
            }
            if ($glId <= 0 && function_exists('fa_gl_infer_erp_account')) {
                $glId = (int) (fa_gl_infer_erp_account($pdo, $fa) ?: 0);
            }
        }
        if ($glId <= 0) {
            $glId = gl_system_account($pdo, 'suspense');
        }
        $cache[$ck] = $glId;
        return $glId;
    }
}

if (!function_exists('gl_bridge_fa_currency')) {
    /** Currency of a financial account ('' when it is the ledger base currency). */
    function gl_bridge_fa_currency(PDO $pdo, int $faId): string
    {
        $cur = gl_normalize_currency(gl_bridge_fa_rows($pdo)[$faId]['currency'] ?? '');
        return $cur === gl_base_currency($pdo) ? '' : $cur;
    }
}

if (!function_exists('gl_bridge_active_link')) {
    /** @return array{entry_id:int, fingerprint:string}|null */
    function gl_bridge_active_link(PDO $pdo, string $table, int $id): ?array
    {
        $st = $pdo->prepare('SELECT entry_id, fingerprint FROM gl_source_links WHERE source_table = ? AND source_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$table, $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['entry_id' => (int) $r['entry_id'], 'fingerprint' => (string) $r['fingerprint']] : null;
    }
}

if (!function_exists('gl_bridge_link_version')) {
    /** 1 + number of times any of these source rows has been posted before (makes re-post keys unique). */
    function gl_bridge_link_version(PDO $pdo, string $table, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 1;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT MAX(c) FROM (SELECT COUNT(*) AS c FROM gl_source_links WHERE source_table = ? AND source_id IN ($in) GROUP BY source_id) x");
        $st->execute(array_merge([$table], $ids));
        return (int) ($st->fetchColumn() ?: 0) + 1;
    }
}

if (!function_exists('gl_bridge_link')) {
    function gl_bridge_link(PDO $pdo, string $table, int $id, int $entryId, string $fingerprint): void
    {
        $pdo->prepare('INSERT INTO gl_source_links (source_table, source_id, entry_id, fingerprint) VALUES (?, ?, ?, ?)')
            ->execute([$table, $id, $entryId, $fingerprint]);
    }
}

if (!function_exists('gl_bridge_reverse_and_unlink')) {
    /** Reverse an entry and release every source row linked to it, so the current values can be re-posted. */
    function gl_bridge_reverse_and_unlink(PDO $pdo, int $entryId, string $reason, array &$stats): void
    {
        $st = $pdo->prepare("SELECT entry_id FROM gl_source_links WHERE source_table = 'gl_reclass' AND source_id = ? AND active = 1");
        $st->execute([$entryId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $reclassId) {
            gl_bridge_reverse_and_unlink($pdo, (int) $reclassId, $reason . ' (reclassification follows original)', $stats);
        }

        $res = gl_reverse_entry($pdo, $entryId, $reason);
        if (!$res['ok'] && !gl_entry_is_reversed($pdo, $entryId)) {
            $stats['errors'][] = "Reverse entry #{$entryId}: " . ($res['error'] ?? 'unknown error');
            return;
        }
        if ($res['ok'] && !$res['existing']) {
            $stats['reversed']++;
        }
        $pdo->prepare('UPDATE gl_source_links SET active = 0 WHERE entry_id = ? AND active = 1')->execute([$entryId]);
    }
}

/* ------------------------------------------------------------------ */
/* Opening balances                                                    */
/* ------------------------------------------------------------------ */

if (!function_exists('gl_bridge_opening_fingerprint')) {
    function gl_bridge_opening_fingerprint(array $fa, string $currency = ''): string
    {
        return md5(number_format((float) $fa['opening_balance'], 2, '.', '') . '|' . (string) ($fa['opening_date'] ?? '')
            . ($currency !== '' ? '|' . $currency : ''));
    }
}

if (!function_exists('gl_bridge_sync_openings')) {
    function gl_bridge_sync_openings(PDO $pdo, array &$stats): void
    {
        $obe = gl_system_account($pdo, 'opening_balance_equity');
        $faRows = gl_bridge_fa_rows($pdo);
        $orphans = $pdo->query("SELECT source_id, entry_id FROM gl_source_links WHERE source_table = 'financial_accounts_opening' AND active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orphans as $o) {
            if (!isset($faRows[(int) $o['source_id']])) {
                gl_bridge_reverse_and_unlink($pdo, (int) $o['entry_id'], "account #{$o['source_id']} was deleted", $stats);
            }
        }
        foreach ($faRows as $faId => $fa) {
            $opening = round((float) ($fa['opening_balance'] ?? 0), 2);
            $faCurrency = gl_bridge_fa_currency($pdo, $faId);
            $fp = gl_bridge_opening_fingerprint($fa, $faCurrency);
            $link = gl_bridge_active_link($pdo, 'financial_accounts_opening', $faId);

            if ($link && $link['fingerprint'] === $fp) {
                continue;
            }
            if ($link) {
                gl_bridge_reverse_and_unlink($pdo, $link['entry_id'], "opening balance of account #{$faId} changed", $stats);
            }
            if (abs($opening) < 0.005) {
                continue;
            }

            $date = (string) ($fa['opening_date'] ?? '');
            if ($date === '' || $date === '0000-00-00') {
                $st = $pdo->prepare('SELECT DATE(MIN(transaction_date)) FROM account_transactions WHERE account_id = ?');
                $st->execute([$faId]);
                $date = (string) ($st->fetchColumn() ?: '');
                if ($date === '') {
                    $date = substr((string) ($fa['created_at'] ?? ''), 0, 10) ?: date('Y-m-d');
                }
            }

            $glId = gl_bridge_fa_gl_account($pdo, $faId);
            // App balance = opening + credits - debits. For asset accounts a positive balance is a GL debit.
            $faDebit = ($fa['_nature'] === 'asset') ? $opening > 0 : $opening < 0;
            $amt = abs($opening);
            $lines = [
                ['account_id' => $glId, 'financial_account_id' => $faId, 'debit' => $faDebit ? $amt : 0, 'credit' => $faDebit ? 0 : $amt, 'memo' => 'Opening balance', 'currency' => $faCurrency],
                ['account_id' => $obe, 'debit' => $faDebit ? 0 : $amt, 'credit' => $faDebit ? $amt : 0, 'memo' => 'Opening balance: ' . $fa['name'], 'currency' => $faCurrency],
            ];
            $res = gl_post_entry($pdo, [
                'date' => $date,
                'description' => 'Opening balance: ' . $fa['name'],
                'source_type' => 'opening_balance',
                'source_key' => 'opening:fa:' . $faId . ':v' . gl_bridge_link_version($pdo, 'financial_accounts_opening', [$faId]),
                'source_ref' => (string) $fa['name'],
                'entry_kind' => 'opening',
            ], $lines);
            if (!$res['ok']) {
                $stats['errors'][] = "Opening balance for account #{$faId}: " . $res['error'];
                continue;
            }
            gl_bridge_link($pdo, 'financial_accounts_opening', $faId, $res['entry_id'], $fp);
            if (!$res['existing']) {
                $stats['posted']++;
                $stats['by_type']['opening_balance'] = ($stats['by_type']['opening_balance'] ?? 0) + 1;
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Money movements (account_transactions)                              */
/* ------------------------------------------------------------------ */

if (!function_exists('gl_bridge_tx_fingerprint')) {
    function gl_bridge_tx_fingerprint(array $tx, string $currency = ''): string
    {
        $parts = [
            (int) $tx['account_id'],
            (string) $tx['type'],
            number_format((float) $tx['amount'], 2, '.', ''),
            substr((string) $tx['transaction_date'], 0, 10),
            (string) ($tx['reference_type'] ?? ''),
            (string) ($tx['reference_id'] ?? ''),
        ];
        if ($currency !== '') {
            $parts[] = $currency;
        }
        return md5(implode('|', $parts));
    }
}

if (!function_exists('gl_bridge_outflow_recorded_as_credit')) {
    /** Supplier/PO payments are written with type 'credit' although money leaves the account. */
    function gl_bridge_outflow_recorded_as_credit(array $tx): bool
    {
        $rt = strtolower((string) ($tx['reference_type'] ?? ''));
        return (string) $tx['type'] === 'credit'
            && (in_array($rt, ['purchase_order', 'supplier_payment'], true) || !empty($tx['supplier_payment_id']));
    }
}

if (!function_exists('gl_bridge_tx_line')) {
    /** Convert one account_transactions row to a GL line. */
    function gl_bridge_tx_line(PDO $pdo, array $tx): array
    {
        $faId = (int) $tx['account_id'];
        $fa = gl_bridge_fa_rows($pdo)[$faId] ?? ['_nature' => 'asset', 'name' => '#' . $faId];
        $amount = round((float) $tx['amount'], 2);
        $isCredit = (string) $tx['type'] === 'credit';
        if ($fa['_nature'] === 'asset' && gl_bridge_outflow_recorded_as_credit($tx)) {
            $isCredit = false;
        }
        // Money accounts follow bank-statement wording (credit = money in = GL debit); other natures map directly.
        $glDebit = ($fa['_nature'] === 'asset') ? $isCredit : !$isCredit;

        return [
            'account_id' => gl_bridge_fa_gl_account($pdo, $faId),
            'financial_account_id' => $faId,
            'debit' => $glDebit ? $amount : 0,
            'credit' => $glDebit ? 0 : $amount,
            'memo' => mb_substr((string) ($tx['description'] ?? ''), 0, 255),
            'currency' => gl_bridge_fa_currency($pdo, $faId),
        ];
    }
}

if (!function_exists('gl_bridge_category_account')) {
    /** GL account for a voucher budget category: explicit mapping first, then an expense account with the same name. */
    function gl_bridge_category_account(PDO $pdo, string $category): int
    {
        $category = trim($category);
        if ($category === '') {
            return 0;
        }
        $st = $pdo->prepare('SELECT a.id FROM gl_category_map m JOIN erp_accounts a ON a.id = m.account_id AND a.is_postable = 1 WHERE m.category = ? LIMIT 1');
        $st->execute([$category]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $st = $pdo->prepare("SELECT id FROM erp_accounts WHERE LOWER(type) = 'expense' AND is_postable = 1 AND LOWER(TRIM(name)) = LOWER(?) ORDER BY id LIMIT 1");
        $st->execute([$category]);
        return (int) ($st->fetchColumn() ?: 0);
    }
}

if (!function_exists('gl_bridge_pv_allocation')) {
    /**
     * Debit lines for a payment voucher outflow: stock purchases go to Inventory; general payments go to the
     * expense account matching each item's budget type, otherwise to Suspense for classification.
     */
    function gl_bridge_pv_allocation(PDO $pdo, int $voucherId, int $amountCents): array
    {
        $suspense = gl_system_account($pdo, 'suspense');
        $pv = null;
        if ($voucherId > 0) {
            $st = $pdo->prepare('SELECT id, voucher_no, payee_name, purpose, payment_purpose, total_amount FROM payment_vouchers WHERE id = ? LIMIT 1');
            $st->execute([$voucherId]);
            $pv = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$pv) {
            return [['account_id' => $suspense, 'debit' => $amountCents / 100, 'credit' => 0, 'memo' => "Payment voucher #{$voucherId} (voucher record not found)"]];
        }

        $label = (string) $pv['voucher_no'] . ' - ' . (string) $pv['payee_name'];
        $purpose = strtolower(trim((string) ($pv['purpose'] ?: $pv['payment_purpose'] ?: 'general')));
        if (in_array($purpose, ['stock_purchase', 'stock_purchases', 'stock purchase', 'stock'], true)) {
            return [['account_id' => gl_system_account($pdo, 'inventory'), 'debit' => $amountCents / 100, 'credit' => 0, 'memo' => 'Stock purchase: ' . $label]];
        }

        $st = $pdo->prepare('SELECT budget_type, name, amount FROM voucher_items WHERE voucher_id = ? ORDER BY id');
        $st->execute([$voucherId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $itemTotal = 0;
        foreach ($items as $it) {
            $itemTotal += max(0, gl_to_cents($it['amount']));
        }
        if (!$items || $itemTotal <= 0) {
            return [['account_id' => $suspense, 'debit' => $amountCents / 100, 'credit' => 0, 'memo' => 'Unclassified payment: ' . $label]];
        }

        $byAccount = [];
        $allocated = 0;
        $n = count($items);
        foreach ($items as $i => $it) {
            $share = ($i === $n - 1)
                ? $amountCents - $allocated
                : (int) round($amountCents * max(0, gl_to_cents($it['amount'])) / $itemTotal);
            $allocated += $share;
            if ($share <= 0) {
                continue;
            }
            $accId = gl_bridge_category_account($pdo, (string) $it['budget_type']);
            $memo = $accId > 0 ? ($label . ' - ' . $it['name']) : ('Unclassified (' . $it['budget_type'] . '): ' . $label . ' - ' . $it['name']);
            $key = $accId > 0 ? $accId : $suspense;
            if (!isset($byAccount[$key])) {
                $byAccount[$key] = ['cents' => 0, 'memo' => $memo];
            }
            $byAccount[$key]['cents'] += $share;
        }
        $lines = [];
        foreach ($byAccount as $accId => $v) {
            $lines[] = ['account_id' => $accId, 'debit' => $v['cents'] / 100, 'credit' => 0, 'memo' => mb_substr($v['memo'], 0, 255)];
        }
        return $lines;
    }
}

if (!function_exists('gl_bridge_counterpart_lines')) {
    /**
     * Lines that complete a group whose own lines leave $netCents (debits - credits) unbalanced.
     */
    function gl_bridge_counterpart_lines(PDO $pdo, string $refType, int $refId, int $netCents, string $label): array
    {
        $rt = strtolower($refType);
        $needDebit = $netCents < 0;
        $amt = abs($netCents);
        $line = static function (int $acc, string $memo) use ($needDebit, $amt): array {
            return ['account_id' => $acc, 'debit' => $needDebit ? $amt / 100 : 0, 'credit' => $needDebit ? 0 : $amt / 100, 'memo' => $memo];
        };

        if (in_array($rt, ['invoice_payment', 'revenue_collection', 'revenue_entry', 'revenue_payment', 'sales_payment'], true)) {
            return [$line(gl_system_account($pdo, 'accounts_receivable'), 'Customer receipt: ' . $label)];
        }
        if ($rt === 'payment_voucher' && $needDebit) {
            return gl_bridge_pv_allocation($pdo, $refId, $amt);
        }
        if (in_array($rt, ['purchase_order', 'supplier_payment'], true) && $needDebit) {
            return [$line(gl_system_account($pdo, 'inventory'), 'Supplier payment: ' . $label)];
        }
        if (strpos($rt, 'transfer') !== false || strpos($rt, 'petty_cash') !== false || $rt === 'topup') {
            return [$line(gl_system_account($pdo, 'transfer_clearing'), 'Transfer: ' . $label)];
        }
        return [$line(gl_system_account($pdo, 'suspense'), 'Awaiting classification: ' . $label)];
    }
}

if (!function_exists('gl_bridge_sync_transactions')) {
    function gl_bridge_sync_transactions(PDO $pdo, array &$stats): void
    {
        // 1. Detect source rows that changed or disappeared since they were posted.
        $links = $pdo->query("SELECT l.source_id, l.entry_id, l.fingerprint, t.id AS tx_id, t.account_id, t.type, t.amount,
                                     t.transaction_date, t.reference_type, t.reference_id
                              FROM gl_source_links l
                              LEFT JOIN account_transactions t ON t.id = l.source_id
                              WHERE l.source_table = 'account_transactions' AND l.active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $stale = [];
        foreach ($links as $l) {
            if ($l['tx_id'] === null) {
                $stale[(int) $l['entry_id']] = 'source transaction #' . $l['source_id'] . ' was deleted';
            } elseif (gl_bridge_tx_fingerprint($l, gl_bridge_fa_currency($pdo, (int) $l['account_id'])) !== $l['fingerprint']) {
                $stale[(int) $l['entry_id']] = 'source transaction #' . $l['source_id'] . ' was changed';
            }
        }
        foreach ($stale as $entryId => $reason) {
            gl_bridge_reverse_and_unlink($pdo, $entryId, $reason, $stats);
        }

        // 2. Post every transaction without an active link, grouped by document and date.
        $rows = $pdo->query("SELECT t.* FROM account_transactions t
                             WHERE NOT EXISTS (SELECT 1 FROM gl_source_links l
                                               WHERE l.source_table = 'account_transactions' AND l.source_id = t.id AND l.active = 1)
                             ORDER BY t.transaction_date, t.id")->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $tx) {
            $rt = (string) ($tx['reference_type'] ?? '');
            $rid = (int) ($tx['reference_id'] ?? 0);
            $day = substr((string) $tx['transaction_date'], 0, 10);
            $key = ($rt !== '' && $rid > 0) ? "{$rt}|{$rid}|{$day}" : 'tx|' . $tx['id'];
            $groups[$key][] = $tx;
        }

        $faRows = gl_bridge_fa_rows($pdo);
        foreach ($groups as $group) {
            $first = $group[0];
            $rt = (string) ($first['reference_type'] ?? '');
            $rid = (int) ($first['reference_id'] ?? 0);
            $lines = [];
            $currencies = [];
            foreach ($group as $tx) {
                if (round((float) $tx['amount'], 2) <= 0) {
                    continue;
                }
                $line = gl_bridge_tx_line($pdo, $tx);
                $currencies[$line['currency']] = true;
                $lines[] = $line;
            }
            $ids = array_map(static fn ($t) => (int) $t['id'], $group);
            if (!$lines) {
                $stats['skipped_zero'] += count($group);
                continue;
            }

            // Same currency: balance the group in that currency. Mixed currencies: balance in the base currency,
            // and let a transfer between accounts of different currencies settle its difference as exchange gain/loss.
            $day = substr((string) $first['transaction_date'], 0, 10);
            $groupCurrency = count($currencies) === 1 ? (string) array_key_first($currencies) : '';
            $net = 0;
            $missingRate = '';
            foreach ($lines as $line) {
                $rate = count($currencies) === 1 ? 1.0 : gl_exchange_rate($pdo, $line['currency'], $day);
                if ($rate === null) {
                    $missingRate = $line['currency'];
                    break;
                }
                $net += (int) round((gl_to_cents($line['debit']) - gl_to_cents($line['credit'])) * $rate);
            }
            if ($missingRate !== '') {
                $stats['errors'][] = 'Transactions ' . implode(',', $ids) . ": no exchange rate for {$missingRate} on or before {$day}. Add one under Exchange Rates, then sync again.";
                continue;
            }

            $label = trim((string) ($first['description'] ?? '')) ?: ($rt !== '' ? "{$rt} #{$rid}" : 'Transaction #' . $first['id']);
            $isTransfer = strpos(strtolower($rt), 'transfer') !== false;
            if ($net !== 0 && !($groupCurrency === '' && count($currencies) > 1 && $isTransfer)) {
                foreach (gl_bridge_counterpart_lines($pdo, $rt, $rid, $net, $label) as $cp) {
                    $cp['currency'] = $groupCurrency;
                    $lines[] = $cp;
                }
            }

            $accountNames = [];
            foreach ($group as $tx) {
                $accountNames[] = (string) ($faRows[(int) $tx['account_id']]['name'] ?? '#' . $tx['account_id']);
            }
            $res = gl_post_entry($pdo, [
                'date' => substr((string) $first['transaction_date'], 0, 10),
                'description' => mb_substr($label, 0, 400),
                'source_type' => $rt !== '' ? $rt : 'account_transaction',
                'source_key' => 'at:' . implode(',', $ids) . ':v' . gl_bridge_link_version($pdo, 'account_transactions', $ids),
                'source_ref' => $rt !== '' && $rid > 0 ? "{$rt}#{$rid}" : 'tx#' . $first['id'],
                'company_id' => $first['company_id'] ?? null,
                'created_by' => $first['created_by'] ?? null,
            ], $lines);
            if (!$res['ok']) {
                $stats['errors'][] = 'Transactions ' . implode(',', $ids) . ': ' . $res['error'];
                continue;
            }
            foreach ($group as $tx) {
                gl_bridge_link($pdo, 'account_transactions', (int) $tx['id'], $res['entry_id'], gl_bridge_tx_fingerprint($tx, gl_bridge_fa_currency($pdo, (int) $tx['account_id'])));
            }
            if (!$res['existing']) {
                $stats['posted']++;
                $bt = $rt !== '' ? $rt : 'other';
                $stats['by_type'][$bt] = ($stats['by_type'][$bt] ?? 0) + 1;
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Reclassification of payment voucher expenses                        */
/* ------------------------------------------------------------------ */

if (!function_exists('gl_bridge_sync_reclassifications')) {
    /**
     * When a voucher category is mapped (or re-mapped) to a GL account, post a reclassification entry that moves
     * the amount from where it was originally booked (usually Suspense). Original entries are never altered.
     */
    function gl_bridge_sync_reclassifications(PDO $pdo, array &$stats): void
    {
        $entries = $pdo->query("SELECT DISTINCT e.id, e.entry_number, e.source_ref
                                FROM gl_source_links l
                                JOIN gl_journal_entries e ON e.id = l.entry_id
                                WHERE l.source_table = 'account_transactions' AND l.active = 1
                                  AND e.source_type = 'payment_voucher'")->fetchAll(PDO::FETCH_ASSOC);
        $linesOf = $pdo->prepare('SELECT account_id, SUM(ROUND(debit * 100)) - SUM(ROUND(credit * 100)) AS net
                                  FROM gl_journal_lines WHERE entry_id = ? AND financial_account_id IS NULL GROUP BY account_id');
        $reclassOf = $pdo->prepare("SELECT l2.account_id, SUM(ROUND(l2.debit * 100)) - SUM(ROUND(l2.credit * 100)) AS net
                                    FROM gl_source_links s JOIN gl_journal_lines l2 ON l2.entry_id = s.entry_id
                                    WHERE s.source_table = 'gl_reclass' AND s.source_id = ? AND s.active = 1
                                    GROUP BY l2.account_id");

        foreach ($entries as $e) {
            $entryId = (int) $e['id'];
            if (!preg_match('/^payment_voucher#(\d+)$/', (string) $e['source_ref'], $m)) {
                continue;
            }
            $posted = [];
            $linesOf->execute([$entryId]);
            foreach ($linesOf->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $posted[(int) $r['account_id']] = (int) $r['net'];
            }
            $reclassOf->execute([$entryId]);
            foreach ($reclassOf->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $posted[(int) $r['account_id']] = ($posted[(int) $r['account_id']] ?? 0) + (int) $r['net'];
            }
            $total = array_sum($posted);
            if ($total <= 0) {
                continue;
            }

            $desired = [];
            foreach (gl_bridge_pv_allocation($pdo, (int) $m[1], $total) as $l) {
                $acc = (int) $l['account_id'];
                $desired[$acc] = ($desired[$acc] ?? 0) + gl_to_cents($l['debit']) - gl_to_cents($l['credit']);
            }

            $lines = [];
            foreach (array_unique(array_merge(array_keys($posted), array_keys($desired))) as $acc) {
                $delta = ($desired[$acc] ?? 0) - ($posted[$acc] ?? 0);
                if ($delta !== 0) {
                    $lines[] = ['account_id' => $acc, 'debit' => $delta > 0 ? $delta / 100 : 0, 'credit' => $delta < 0 ? -$delta / 100 : 0, 'memo' => 'Reclassified from ' . $e['entry_number']];
                }
            }
            if (!$lines) {
                continue;
            }

            $res = gl_post_entry($pdo, [
                'date' => date('Y-m-d'),
                'description' => 'Reclassification of ' . $e['entry_number'] . ' (payment voucher #' . $m[1] . ')',
                'source_type' => 'reclassification',
                'source_key' => 'reclass:' . $entryId . ':v' . gl_bridge_link_version($pdo, 'gl_reclass', [$entryId]),
                'source_ref' => (string) $e['entry_number'],
                'entry_kind' => 'adjustment',
            ], $lines);
            if (!$res['ok']) {
                $stats['errors'][] = 'Reclassify ' . $e['entry_number'] . ': ' . $res['error'];
                continue;
            }
            gl_bridge_link($pdo, 'gl_reclass', $entryId, $res['entry_id'], md5(json_encode($desired)));
            if (!$res['existing']) {
                $stats['posted']++;
                $stats['by_type']['reclassification'] = ($stats['by_type']['reclassification'] ?? 0) + 1;
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Revenue recognition (revenue_entries)                              */
/* ------------------------------------------------------------------ */

if (!function_exists('gl_bridge_revenue_qualifies')) {
    function gl_bridge_revenue_qualifies(array $r): bool
    {
        $status = strtolower(trim((string) ($r['approval_status'] ?? '')));
        if (in_array($status, ['rejected', 'cancelled', 'canceled', 'void', 'voided', 'deleted'], true)) {
            return false;
        }
        return round((float) ($r['amount_total'] ?? 0), 2) > 0;
    }
}

if (!function_exists('gl_bridge_revenue_fingerprint')) {
    function gl_bridge_revenue_fingerprint(array $r): string
    {
        return md5(implode('|', [
            number_format((float) $r['amount_total'], 2, '.', ''),
            number_format((float) $r['vat_amount'], 2, '.', ''),
            (string) $r['entry_date'],
            number_format((float) ($r['exchange_rate'] ?? 1), 6, '.', ''),
            (string) ($r['revenue_account_id'] ?? ''),
            gl_bridge_revenue_qualifies($r) ? '1' : '0',
        ]));
    }
}

if (!function_exists('gl_bridge_sync_revenue')) {
    function gl_bridge_sync_revenue(PDO $pdo, array &$stats): void
    {
        if (!gl_table_exists($pdo, 'revenue_entries')) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $sel = ['id', 'voucher_number', 'entry_date', 'customer_name', 'amount_total', 'vat_amount', 'amount_exclusive', 'approval_status'];
        foreach (['exchange_rate', 'revenue_account_id', 'currency'] as $c) {
            if (in_array($c, $cols, true)) {
                $sel[] = $c;
            }
        }
        $rows = [];
        foreach ($pdo->query('SELECT ' . implode(', ', $sel) . ' FROM revenue_entries ORDER BY entry_date, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['id']] = $r;
        }

        $ar = gl_system_account($pdo, 'accounts_receivable');
        $vat = gl_system_account($pdo, 'vat_payable');
        $defaultRevenue = gl_system_account($pdo, 'sales_revenue');
        $revenueAccountOk = $pdo->prepare("SELECT id FROM erp_accounts WHERE id = ? AND LOWER(type) = 'revenue' AND is_postable = 1 LIMIT 1");

        $linked = [];
        foreach ($pdo->query("SELECT source_id, entry_id, fingerprint FROM gl_source_links WHERE source_table = 'revenue_entries' AND active = 1")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $linked[(int) $l['source_id']] = $l;
        }
        foreach ($linked as $id => $l) {
            if (!isset($rows[$id])) {
                gl_bridge_reverse_and_unlink($pdo, (int) $l['entry_id'], "revenue entry #{$id} was deleted", $stats);
                unset($linked[$id]);
            }
        }

        foreach ($rows as $id => $r) {
            $fp = gl_bridge_revenue_fingerprint($r);
            if (isset($linked[$id])) {
                if ($linked[$id]['fingerprint'] === $fp) {
                    continue;
                }
                gl_bridge_reverse_and_unlink($pdo, (int) $linked[$id]['entry_id'], "revenue entry #{$id} was changed", $stats);
            }
            if (!gl_bridge_revenue_qualifies($r)) {
                continue;
            }

            $fx = (float) ($r['exchange_rate'] ?? 1);
            if ($fx <= 0) {
                $fx = 1.0;
            }
            $totalC = gl_to_cents((float) $r['amount_total'] * $fx);
            $vatC = max(0, min($totalC, gl_to_cents((float) $r['vat_amount'] * $fx)));
            $revC = $totalC - $vatC;

            $revAcc = $defaultRevenue;
            if (!empty($r['revenue_account_id'])) {
                $revenueAccountOk->execute([(int) $r['revenue_account_id']]);
                $revAcc = (int) ($revenueAccountOk->fetchColumn() ?: $defaultRevenue);
            }

            $label = trim((string) $r['voucher_number']) . ' - ' . trim((string) $r['customer_name']);
            $lines = [
                ['account_id' => $ar, 'debit' => $totalC / 100, 'credit' => 0, 'memo' => 'Receivable: ' . $label],
                ['account_id' => $revAcc, 'debit' => 0, 'credit' => $revC / 100, 'memo' => 'Revenue: ' . $label],
            ];
            if ($vatC > 0) {
                $lines[] = ['account_id' => $vat, 'debit' => 0, 'credit' => $vatC / 100, 'memo' => 'VAT: ' . $label];
            }
            $res = gl_post_entry($pdo, [
                'date' => (string) ($r['entry_date'] ?: date('Y-m-d')),
                'description' => 'Revenue recognised: ' . $label,
                'source_type' => 'revenue_entry',
                'source_key' => 'rev:' . $id . ':v' . gl_bridge_link_version($pdo, 'revenue_entries', [$id]),
                'source_ref' => (string) $r['voucher_number'],
            ], $lines);
            if (!$res['ok']) {
                $stats['errors'][] = "Revenue entry #{$id}: " . $res['error'];
                continue;
            }
            gl_bridge_link($pdo, 'revenue_entries', $id, $res['entry_id'], $fp);
            if (!$res['existing']) {
                $stats['posted']++;
                $stats['by_type']['revenue_recognition'] = ($stats['by_type']['revenue_recognition'] ?? 0) + 1;
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Orchestration                                                       */
/* ------------------------------------------------------------------ */

if (!function_exists('gl_bridge_sync_all')) {
    /**
     * Bring the ledger in line with all sources. Idempotent.
     *
     * @param array{dry_run?:bool, only?:list<string>} $options
     * @return array<string, mixed>
     */
    function gl_bridge_sync_all(PDO $pdo, array $options = []): array
    {
        $stats = ['dry_run' => !empty($options['dry_run']), 'posted' => 0, 'reversed' => 0, 'skipped_zero' => 0, 'by_type' => [], 'errors' => []];
        if (!gl_ensure_schema($pdo)) {
            $stats['errors'][] = 'Ledger tables are not available.';
            return $stats;
        }
        $only = $options['only'] ?? ['openings', 'transactions', 'reclass', 'revenue'];

        $pdo->beginTransaction();
        try {
            gl_bridge_fa_rows($pdo, true);
            if (in_array('openings', $only, true)) {
                gl_bridge_sync_openings($pdo, $stats);
            }
            if (in_array('transactions', $only, true)) {
                gl_bridge_sync_transactions($pdo, $stats);
            }
            if (in_array('reclass', $only, true)) {
                gl_bridge_sync_reclassifications($pdo, $stats);
            }
            if (in_array('revenue', $only, true)) {
                gl_bridge_sync_revenue($pdo, $stats);
            }
            if ($stats['dry_run']) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $stats['errors'][] = $e->getMessage();
            error_log('gl_bridge_sync_all: ' . $e->getMessage());
        }
        return $stats;
    }
}

if (!function_exists('gl_bridge_schedule_sync')) {
    /**
     * Parallel run: after the current request finishes, post any new money movements to the ledger.
     * Never interferes with the operational flow; failures are logged only.
     */
    function gl_bridge_schedule_sync(?PDO $pdo): void
    {
        static $scheduled = false;
        if ($scheduled || !($pdo instanceof PDO)) {
            return;
        }
        if (defined('GL_AUTOSYNC') && !GL_AUTOSYNC) {
            return;
        }
        // Background sync starts only after the first full backfill was reviewed and applied.
        if (gl_meta_get($pdo, 'autosync') !== '1') {
            return;
        }
        $scheduled = true;
        register_shutdown_function(static function () use ($pdo): void {
            try {
                if ($pdo->inTransaction()) {
                    return;
                }
                $res = gl_bridge_sync_all($pdo);
                if (!empty($res['errors'])) {
                    error_log('gl autosync: ' . implode(' | ', array_slice($res['errors'], 0, 5)));
                }
            } catch (Throwable $e) {
                error_log('gl autosync: ' . $e->getMessage());
            }
        });
    }
}
