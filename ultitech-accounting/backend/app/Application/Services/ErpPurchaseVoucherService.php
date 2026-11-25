<?php

namespace App\Application\Services;

use App\Integration\Erp\ReadOnlyErpGateway;
use App\Models\AccountingBook;
use App\Models\CompanySetting;
use App\Models\ErpConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Read classic payment vouchers for Purchases (stock) and Expenses (general) desks.
 */
final class ErpPurchaseVoucherService
{
    public function __construct(
        private readonly ReadOnlyErpGateway $erp,
        private readonly ErpSyncService $sync,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listStockPurchaseVouchers(AccountingBook $book, int $limit = 500): array
    {
        return $this->listPaymentVouchers($book, 'stock_purchase', $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGeneralExpenseVouchers(AccountingBook $book, int $limit = 500): array
    {
        return $this->listPaymentVouchers($book, 'general', $limit);
    }

    /**
     * @param  'stock_purchase'|'general'  $purpose
     * @return list<array<string, mixed>>
     */
    private function listPaymentVouchers(AccountingBook $book, string $purpose, int $limit = 500): array
    {
        if (! config('erp.enabled')) {
            return [];
        }

        $purpose = $purpose === 'stock_purchase' ? 'stock_purchase' : 'general';

        try {
            $connection = $this->sync->ensureConnection((int) $book->company_id);
            $this->bindErpDatabase((int) $book->company_id, $connection);

            if (! $this->erp->tableExists('payment_vouchers')) {
                return [];
            }

            $limit = max(1, min(2000, $limit));
            $purposeSql = $purpose === 'stock_purchase'
                ? $this->stockPurchasePurposeSql('pv')
                : $this->generalExpensePurposeSql('pv');
            $draftSql = $this->columnExists('payment_vouchers', 'is_draft')
                ? ' AND IFNULL(pv.is_draft, 0) = 0'
                : '';

            $itemPaymentType = 'NULL';
            $itemName = 'NULL';
            if ($this->erp->tableExists('voucher_items')) {
                $itemPaymentType = "(
                        SELECT vi.payment_type FROM voucher_items vi
                        WHERE vi.voucher_id = pv.id
                        ORDER BY vi.id ASC LIMIT 1
                    )";
                $itemName = "(
                        SELECT vi.name FROM voucher_items vi
                        WHERE vi.voucher_id = pv.id
                        ORDER BY vi.id ASC LIMIT 1
                    )";
            }

            $dueSelect = $this->columnExists('payment_vouchers', 'due_date')
                ? 'pv.due_date'
                : 'NULL AS due_date';
            $linkedPoIdSelect = $this->columnExists('payment_vouchers', 'linked_stock_po_id')
                ? 'pv.linked_stock_po_id'
                : 'NULL AS linked_stock_po_id';
            $linkedPoIdsSelect = $this->columnExists('payment_vouchers', 'linked_stock_po_ids')
                ? 'pv.linked_stock_po_ids'
                : 'NULL AS linked_stock_po_ids';

            $sql = "
                SELECT
                    pv.id,
                    pv.voucher_no,
                    pv.payee_name,
                    pv.description,
                    pv.total_amount,
                    pv.currency,
                    pv.status,
                    pv.is_paid,
                    pv.is_posted,
                    pv.date_created,
                    {$dueSelect},
                    {$linkedPoIdSelect},
                    {$linkedPoIdsSelect},
                    {$itemPaymentType} AS item_payment_type,
                    {$itemName} AS item_name
                FROM payment_vouchers pv
                WHERE {$purposeSql}
                {$draftSql}
                ORDER BY DATE(pv.date_created) DESC, pv.id DESC
                LIMIT {$limit}
            ";

            $rows = $this->erp->select($sql);
            $labels = $this->entryLabels($rows);
            $poLabels = $purpose === 'stock_purchase'
                ? $this->resolvePurchaseOrderLabels($rows)
                : [];

            $out = [];
            foreach ($rows as $row) {
                $mapped = $this->mapRow($row, $labels, $poLabels);
                if ($mapped !== null) {
                    $out[] = $mapped;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('ERP '.$purpose.' vouchers list failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, string>
     */
    private function entryLabels(array $rows): array
    {
        $ordered = $rows;
        usort($ordered, static function ($a, $b) {
            $da = substr((string) ($a->date_created ?? ''), 0, 10);
            $db = substr((string) ($b->date_created ?? ''), 0, 10);
            if ($da === $db) {
                return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
            }

            return $da <=> $db;
        });

        $seqByMonth = [];
        $labels = [];
        foreach ($ordered as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $dateRaw = substr(trim((string) ($row->date_created ?? '')), 0, 10);
            $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;
            $year = $ts ? (int) date('Y', $ts) : (int) date('Y');
            $month = $ts ? (int) date('n', $ts) : (int) date('n');
            $key = $year.'-'.$month;
            $seqByMonth[$key] = ($seqByMonth[$key] ?? 0) + 1;
            $labels[$id] = 'PV-'.$year.'-'.$month.'-'.$seqByMonth[$key];
        }

        return $labels;
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, string> po_id => po_number
     */
    private function resolvePurchaseOrderLabels(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($this->linkedPoIdsFromRow($row) as $poId) {
                $ids[$poId] = $poId;
            }
        }
        if ($ids === [] || ! $this->erp->tableExists('stocks_purchase_orders')) {
            return [];
        }

        $idList = implode(',', array_map('intval', array_values($ids)));
        if ($idList === '') {
            return [];
        }

        try {
            $poRows = $this->erp->select("
                SELECT id, po_number
                FROM stocks_purchase_orders
                WHERE id IN ({$idList})
            ");
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($poRows as $po) {
            $poId = (int) ($po->id ?? 0);
            if ($poId <= 0) {
                continue;
            }
            $number = trim((string) ($po->po_number ?? ''));
            $out[$poId] = $number !== '' ? $number : ('PO-'.$poId);
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function linkedPoIdsFromRow(object $row): array
    {
        $ids = [];
        $raw = trim((string) ($row->linked_stock_po_ids ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    $id = (int) $value;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            } else {
                foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
                    $id = (int) $part;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }
        $single = (int) ($row->linked_stock_po_id ?? 0);
        if ($single > 0) {
            $ids[$single] = $single;
        }

        return array_values($ids);
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $poLabels
     * @return array<string, mixed>|null
     */
    private function mapRow(object $row, array $labels, array $poLabels = []): ?array
    {
        $id = (int) ($row->id ?? 0);
        if ($id <= 0) {
            return null;
        }

        $status = strtolower(trim((string) ($row->status ?? '')));
        $isPosted = (int) ($row->is_posted ?? 0) === 1;
        $isPaid = (int) ($row->is_paid ?? 0) === 1;
        $official = trim((string) ($row->voucher_no ?? ''));
        if ($official === '') {
            $official = 'PV-'.$id;
        }
        $entry = $labels[$id] ?? $official;
        $date = substr((string) ($row->date_created ?? ''), 0, 10);
        $due = trim((string) ($row->due_date ?? ''));
        if (strlen($due) > 10) {
            $due = substr($due, 0, 10);
        }
        $description = trim((string) ($row->description ?? ''));
        if ($description === '') {
            $description = trim((string) ($row->item_name ?? ''));
        }
        $currency = strtoupper(trim((string) ($row->currency ?? 'TZS')));
        if ($currency === 'TSH') {
            $currency = 'TZS';
        }
        $total = round((float) ($row->total_amount ?? 0), 2);
        $balance = $isPaid ? 0.0 : $total;

        if ($isPosted) {
            $displayStatus = 'posted';
        } elseif ($isPaid) {
            $displayStatus = 'paid';
        } elseif (in_array($status, ['approved', 'rejected', 'pending', 'confirming', 'draft'], true)) {
            $displayStatus = $status;
        } else {
            $displayStatus = $status !== '' ? $status : 'pending';
        }

        $linkedPoIds = $this->linkedPoIdsFromRow($row);
        $linkedPoNumbers = [];
        foreach ($linkedPoIds as $poId) {
            $linkedPoNumbers[] = $poLabels[$poId] ?? ('PO-'.$poId);
        }

        return [
            'id' => $id,
            'pv_id' => $id,
            'voucher_number' => $entry,
            'voucher_no_official' => $official,
            'bill_number' => $entry,
            'bill_date' => $date,
            'due_date' => $due !== '' ? $due : null,
            'description' => $description,
            'currency_code' => $currency,
            'subtotal' => number_format($total, 2, '.', ''),
            'tax_total' => '0.00',
            'total' => number_format($total, 2, '.', ''),
            'amount_paid' => number_format($isPaid ? $total : 0, 2, '.', ''),
            'balance_due' => number_format($balance, 2, '.', ''),
            'status' => $isPaid ? 'paid' : ($isPosted ? 'posted' : $displayStatus),
            'display_status' => $displayStatus,
            'is_paid' => $isPaid ? 1 : 0,
            'is_posted' => $isPosted ? 1 : 0,
            'is_voucher' => 1,
            'payment_method_label' => trim((string) ($row->item_payment_type ?? '')) ?: 'Payment Voucher',
            'linked_po_ids' => $linkedPoIds,
            'linked_po_numbers' => $linkedPoNumbers,
            'linked_po_label' => $linkedPoNumbers !== [] ? implode(', ', $linkedPoNumbers) : '',
            'supplier' => [
                'id' => 0,
                'code' => '',
                'name' => trim((string) ($row->payee_name ?? '')) ?: '—',
            ],
            'lines' => [],
        ];
    }

    private function stockPurchasePurposeSql(string $alias = 'pv'): string
    {
        $p = $alias !== '' ? rtrim($alias, '.').'.' : '';
        $parts = [];
        foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $col) {
            if (! $this->columnExists('payment_vouchers', $col)) {
                continue;
            }
            $parts[] = "LOWER(TRIM(COALESCE({$p}`{$col}`, ''))) = 'stock_purchase'";
            $parts[] = "REPLACE(LOWER(TRIM(COALESCE({$p}`{$col}`, ''))), ' ', '_') = 'stock_purchase'";
            $parts[] = "LOWER(TRIM(COALESCE({$p}`{$col}`, ''))) = 'stockpurchase'";
        }

        if ($parts === []) {
            // Fallback: no purpose column — return none rather than all vouchers.
            return '0=1';
        }

        return '('.implode(' OR ', $parts).')';
    }

    /** General Payment vouchers only (excludes stock purchase). */
    private function generalExpensePurposeSql(string $alias = 'pv'): string
    {
        $p = $alias !== '' ? rtrim($alias, '.').'.' : '';
        $candidates = [];
        foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $col) {
            if (! $this->columnExists('payment_vouchers', $col)) {
                continue;
            }
            $candidates[] = "NULLIF(TRIM({$p}`{$col}`), '')";
        }

        if ($candidates === []) {
            // No purpose column — treat all non-draft rows as general.
            return '1=1';
        }

        $resolved = 'LOWER(TRIM(COALESCE('.implode(', ', $candidates).", 'general')))";

        return "({$resolved} IN ('general', 'general_payment') OR {$resolved} = '')
            AND {$resolved} NOT IN ('stock_purchase', 'stockpurchase')
            AND REPLACE({$resolved}, ' ', '_') NOT IN ('stock_purchase')";
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::connection($this->erp->connection()->getName())->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function bindErpDatabase(int $companyId, ErpConnection $connection): void
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->first();
        $json = is_array($settings?->settings_json) ? $settings->settings_json : [];
        $erpDb = trim((string) data_get($json, 'erp_bridge.erp_db', ''));
        if ($erpDb === '') {
            $erpDb = trim((string) ($connection->erp_database ?: config('erp.default_database')));
        }
        if ($erpDb !== '') {
            $this->erp->useDatabase($erpDb);
        }
    }
}
