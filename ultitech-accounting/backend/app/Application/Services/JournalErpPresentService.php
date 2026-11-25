<?php

namespace App\Application\Services;

use App\Integration\Erp\ReadOnlyErpGateway;
use App\Models\AccountingBook;
use App\Models\CompanySetting;
use App\Models\ErpConnection;
use Illuminate\Support\Facades\Log;

/**
 * Read classic GL journals for the Journal Entries desk (ERP is read-only).
 */
final class JournalErpPresentService
{
    public function __construct(
        private readonly ReadOnlyErpGateway $erp,
        private readonly ErpSyncService $sync,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listJournals(
        AccountingBook $book,
        int $limit = 500,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $status = null,
        ?string $q = null,
    ): array {
        if (! config('erp.enabled')) {
            return [];
        }

        // ERP GL rows are posted history; drafts/reversals live only in New Accounting.
        if ($status !== null && $status !== '' && ! in_array($status, ['posted', 'all'], true)) {
            return [];
        }

        try {
            $connection = $this->sync->ensureConnection((int) $book->company_id);
            $this->bindErpDatabase((int) $book->company_id, $connection);

            if (! $this->erp->tableExists('gl_journal_entries')) {
                return [];
            }

            $limit = max(1, min(1000, $limit));
            $bindings = [];
            $where = ['1=1'];

            // Tenant DB is already selected via bindErpDatabase(). Only filter by
            // company_id when the classic GL actually stores non-null company ids.
            $erpCompanyId = $this->erpCompanyId((int) $book->company_id);
            if (
                $erpCompanyId !== null
                && $this->columnExists('gl_journal_entries', 'company_id')
                && $this->hasPopulatedCompanyIds()
            ) {
                $where[] = 'e.company_id = ?';
                $bindings[] = $erpCompanyId;
            }

            $from = $this->normalizeDate($dateFrom);
            $to = $this->normalizeDate($dateTo);
            if ($from !== null) {
                $where[] = 'e.entry_date >= ?';
                $bindings[] = $from;
            }
            if ($to !== null) {
                $where[] = 'e.entry_date <= ?';
                $bindings[] = $to;
            }

            $q = trim((string) $q);
            if ($q !== '') {
                $where[] = '(e.entry_number LIKE ? OR e.description LIKE ? OR e.source_type LIKE ? OR e.source_ref LIKE ? OR e.journal_code LIKE ?)';
                $like = '%'.$q.'%';
                array_push($bindings, $like, $like, $like, $like, $like);
            }

            $whereSql = implode(' AND ', $where);
            $sql = "
                SELECT
                    e.id,
                    e.entry_number,
                    e.entry_date,
                    e.description,
                    e.source_type,
                    e.source_ref,
                    e.journal_code,
                    e.currency,
                    e.total_amount,
                    e.reversal_of_id
                FROM gl_journal_entries e
                WHERE {$whereSql}
                ORDER BY e.entry_date DESC, e.id DESC
                LIMIT {$limit}
            ";

            $accounts = $this->accountNameMap();
            $out = [];
            foreach ($this->erp->select($sql, $bindings) as $row) {
                $out[] = $this->presentEntry($row, $accounts, false);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('ERP journal present failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findJournal(AccountingBook $book, int $erpId): ?array
    {
        if (! config('erp.enabled') || $erpId <= 0) {
            return null;
        }

        try {
            $connection = $this->sync->ensureConnection((int) $book->company_id);
            $this->bindErpDatabase((int) $book->company_id, $connection);

            if (! $this->erp->tableExists('gl_journal_entries')) {
                return null;
            }

            $bindings = [$erpId];
            $companySql = '';
            $erpCompanyId = $this->erpCompanyId((int) $book->company_id);
            if (
                $erpCompanyId !== null
                && $this->columnExists('gl_journal_entries', 'company_id')
                && $this->hasPopulatedCompanyIds()
            ) {
                $companySql = ' AND company_id = ?';
                $bindings[] = $erpCompanyId;
            }

            $rows = $this->erp->select(
                "SELECT id, entry_number, entry_date, description, source_type, source_ref, journal_code, currency, total_amount, reversal_of_id
                 FROM gl_journal_entries WHERE id = ?{$companySql} LIMIT 1",
                $bindings
            );
            $row = $rows[0] ?? null;
            if ($row === null) {
                return null;
            }

            return $this->presentEntry($row, $this->accountNameMap(), true);
        } catch (\Throwable $e) {
            Log::warning('ERP journal detail failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array{coa: array<string, array{code:string,name:string}>, wallet: array<string, array{code:string,name:string}>}  $accounts
     * @return array<string, mixed>
     */
    private function presentEntry(object $row, array $accounts, bool $includeLines): array
    {
        $erpId = (int) ($row->id ?? 0);
        $currency = strtoupper(trim((string) ($row->currency ?? 'TZS'))) ?: 'TZS';
        $total = round((float) ($row->total_amount ?? 0), 2);
        $sourceType = trim((string) ($row->source_type ?? 'system'));
        $status = ! empty($row->reversal_of_id) ? 'reversed' : 'posted';

        $lines = [];
        $debitTotal = $total;
        $creditTotal = $total;

        if ($includeLines && $this->erp->tableExists('gl_journal_lines')) {
            $lineRows = $this->erp->select(
                'SELECT id, line_no, account_id, financial_account_id, debit, credit, memo, currency
                 FROM gl_journal_lines WHERE entry_id = ? ORDER BY line_no, id',
                [$erpId]
            );
            $debitTotal = 0.0;
            $creditTotal = 0.0;
            foreach ($lineRows as $line) {
                $accountId = (int) ($line->account_id ?? 0);
                $walletId = (int) ($line->financial_account_id ?? 0);
                $debit = round((float) ($line->debit ?? 0), 2);
                $credit = round((float) ($line->credit ?? 0), 2);
                $debitTotal += $debit;
                $creditTotal += $credit;
                $coa = $accounts['coa'][(string) $accountId] ?? null;
                $wallet = $walletId > 0 ? ($accounts['wallet'][(string) $walletId] ?? null) : null;
                $code = $coa['code'] ?? (string) $accountId;
                $coaName = trim((string) ($coa['name'] ?? ''));
                $walletName = trim((string) ($wallet['name'] ?? ''));
                if ($walletName !== '' && $coaName !== '' && strcasecmp($coaName, $walletName) !== 0) {
                    $name = $coaName.' / '.$walletName;
                } elseif ($walletName !== '') {
                    $name = $walletName;
                } elseif ($coaName !== '') {
                    $name = $coaName;
                } else {
                    $name = 'Account #'.$accountId;
                }
                $lines[] = [
                    'id' => (int) ($line->id ?? 0),
                    'line_no' => (int) ($line->line_no ?? 0),
                    'account_id' => $accountId,
                    'financial_account_id' => $walletId > 0 ? $walletId : null,
                    'description' => trim((string) ($line->memo ?? '')) ?: null,
                    'debit' => number_format($debit, 2, '.', ''),
                    'credit' => number_format($credit, 2, '.', ''),
                    'currency_code' => strtoupper(trim((string) ($line->currency ?? $currency))) ?: $currency,
                    'account' => [
                        'id' => $accountId,
                        'code' => $code,
                        'name' => $name,
                    ],
                ];
            }
            if ($lines === []) {
                $debitTotal = $total;
                $creditTotal = $total;
            }
        }

        $sourceName = $this->sourceLabel($sourceType);

        return [
            // Negative ids mark ERP-only rows so they never collide with local journals.
            'id' => -1 * $erpId,
            'erp_journal_id' => $erpId,
            'journal_number' => (string) ($row->entry_number ?: ('GL-'.$erpId)),
            'journal_date' => (string) ($row->entry_date ?? ''),
            'posting_date' => (string) ($row->entry_date ?? ''),
            'description' => trim((string) ($row->description ?? '')) ?: (string) ($row->entry_number ?? 'Journal'),
            'reference' => trim((string) ($row->source_ref ?? $row->journal_code ?? '')) ?: null,
            'status' => $status,
            'currency_code' => $currency,
            'total_debit' => number_format($debitTotal, 2, '.', ''),
            'total_credit' => number_format($creditTotal, 2, '.', ''),
            'source_type' => $sourceType !== '' ? $sourceType : 'erp_gl_journal',
            'source_id_ref' => (string) $erpId,
            'source_hash' => 'erp:gl_journal:'.$erpId,
            'posted_at' => null,
            'read_only' => true,
            'source' => [
                'id' => 0,
                'code' => $sourceType !== '' ? $sourceType : 'system',
                'name' => $sourceName,
            ],
            'lines' => $includeLines ? $lines : [],
        ];
    }

    /**
     * @return array{coa: array<string, array{code:string,name:string}>, wallet: array<string, array{code:string,name:string}>}
     */
    private function accountNameMap(): array
    {
        $coa = [];
        $wallet = [];

        if ($this->erp->tableExists('erp_accounts')) {
            foreach ($this->erp->select('SELECT id, code, name FROM erp_accounts') as $row) {
                $id = (string) ($row->id ?? '');
                if ($id === '') {
                    continue;
                }
                $code = trim((string) ($row->code ?? ''));
                $name = trim((string) ($row->name ?? ''));
                $coa[$id] = [
                    'code' => $code !== '' ? $code : $id,
                    'name' => $name !== '' ? $name : ('Account #'.$id),
                ];
            }
        }

        if ($this->erp->tableExists('financial_accounts')) {
            foreach ($this->erp->select('SELECT id, name, type FROM financial_accounts') as $row) {
                $id = (string) ($row->id ?? '');
                if ($id === '') {
                    continue;
                }
                $name = trim((string) ($row->name ?? ''));
                $code = $id;
                // Classic Balances names often look like "1101 - CRDB BANK".
                if (preg_match('/^([A-Z0-9][A-Z0-9\\-\\/]*)\\s*[-]\\s*(.+)$/i', $name, $m)) {
                    $code = strtoupper(trim($m[1]));
                    $name = trim($m[2]) !== '' ? trim($m[2]) : $name;
                } elseif ($name === '') {
                    $name = 'Account #'.$id;
                }
                $wallet[$id] = [
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }

        return ['coa' => $coa, 'wallet' => $wallet];
    }

    private function sourceLabel(string $sourceType): string
    {
        $raw = trim($sourceType);
        if ($raw === '') {
            return 'System';
        }

        return match (strtolower($raw)) {
            'manual' => 'Manual',
            'opening_balance' => 'Opening balance',
            'revenue_collection' => 'Revenue collection',
            'payment_voucher', 'expense', 'expense_voucher' => 'Payment voucher',
            'invoice', 'sales_invoice' => 'Sales invoice',
            'erp_gl_journal' => 'ERP journal',
            default => ucwords(str_replace(['_', '-'], ' ', $raw)),
        };
    }

    private function erpCompanyId(int $companyId): ?int
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->first();
        $json = is_array($settings?->settings_json) ? $settings->settings_json : [];
        $erpCompanyId = (int) data_get($json, 'erp_bridge.erp_company_id', 0);
        if ($erpCompanyId > 0) {
            return $erpCompanyId;
        }
        $legacy = (int) data_get($json, 'erp_company_id', 0);

        return $legacy > 0 ? $legacy : null;
    }

    private function bindErpDatabase(int $companyId, ErpConnection $connection): void
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->first();
        $json = is_array($settings?->settings_json) ? $settings->settings_json : [];
        $erpDb = trim((string) data_get($json, 'erp_bridge.erp_db', ''));
        if ($erpDb === '') {
            $erpDb = trim((string) data_get($json, 'erp_db', ''));
        }
        if ($erpDb === '') {
            $erpDb = trim((string) ($connection->erp_database ?: config('erp.default_database')));
        }
        if ($erpDb !== '') {
            $this->erp->useDatabase($erpDb);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return $this->erp->select('SHOW COLUMNS FROM '.$table.' LIKE ?', [$column]) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasPopulatedCompanyIds(): bool
    {
        try {
            $row = $this->erp->selectOne(
                'SELECT COUNT(*) AS c FROM gl_journal_entries WHERE company_id IS NOT NULL'
            );

            return (int) ($row->c ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function normalizeDate(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d', $ts) : null;
    }
}
