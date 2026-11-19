<?php

namespace App\Application\Services;

use App\Integration\Erp\ReadOnlyErpGateway;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\CompanySetting;
use App\Models\ErpConnection;
use App\Models\ErpIdMap;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Attach classic Balances (financial_accounts) identity + live balances
 * to New Accounting bank/cash desks. ERP is read-only.
 */
final class BankingErpEnrichmentService
{
    public function __construct(
        private readonly ReadOnlyErpGateway $erp,
        private readonly ErpSyncService $sync,
        private readonly WalletBalanceWatchService $balanceWatch,
    ) {}

    /**
     * Ensure latest Balances wallets are mirrored into local desks.
     */
    public function refreshWallets(AccountingBook $book, User $user): void
    {
        if (! config('erp.enabled')) {
            return;
        }

        try {
            $this->sync->pull($book, $user, ['wallets'], 0);
        } catch (\Throwable $e) {
            Log::warning('Banking wallet refresh failed: '.$e->getMessage());
        }
    }

    /**
     * @param  Collection<int, BankAccount>  $banks
     * @return list<array<string,mixed>>
     */
    public function presentBankAccounts(AccountingBook $book, Collection $banks): array
    {
        $connection = $this->sync->ensureConnection((int) $book->company_id);
        $this->bindErpDatabase((int) $book->company_id, $connection);

        $maps = $this->mapsFor($connection->id, 'financial_bank', $banks->pluck('id')->all());
        $erpIds = array_values(array_unique(array_map('strval', array_keys($maps))));
        $balances = $this->liveBalances($erpIds);
        $details = $this->walletDetails($erpIds);

        return $banks->map(function (BankAccount $bank) use ($book, $maps, $balances, $details) {
            $erpId = $this->erpIdForLocal($maps, (int) $bank->id);
            $detail = $erpId !== null ? ($details[$erpId] ?? null) : null;
            $balance = $erpId !== null && array_key_exists($erpId, $balances)
                ? (float) $balances[$erpId]
                : null;

            $classicCode = $this->classicCode(
                is_array($detail) ? (string) ($detail['name'] ?? '') : '',
                $erpId,
                $bank->code
            );

            $accountNumber = trim((string) ($bank->account_number ?? ''));
            if ($accountNumber === '' && is_array($detail)) {
                $accountNumber = trim((string) ($detail['account_number'] ?? ''));
            }

            $bankName = trim((string) ($bank->bank_name ?? ''));
            if ($bankName === '' || $bankName === $bank->name) {
                $bankName = is_array($detail)
                    ? $this->classicLabel((string) ($detail['name'] ?? $bank->name))
                    : $bank->name;
            }

            $logoPath = is_array($detail) ? trim((string) ($detail['account_image'] ?? '')) : '';
            $payload = $bank->loadMissing('glAccount')->toArray();
            $payload['code'] = $classicCode;
            $payload['name'] = is_array($detail)
                ? $this->classicLabel((string) ($detail['name'] ?? $bank->name))
                : $bank->name;
            $payload['bank_name'] = $bankName;
            $payload['account_number'] = $accountNumber;
            $payload['balance'] = $balance;
            $payload['balance_source'] = $balance === null ? 'none' : 'erp_balances';
            $openingBalance = is_array($detail) && array_key_exists('opening_balance', $detail)
                ? (float) $detail['opening_balance']
                : null;
            $payload = array_merge($payload, $this->balanceWatch->observe(
                (int) $book->company_id,
                (int) $book->id,
                'bank',
                (int) $bank->id,
                $balance,
                $openingBalance,
            ));
            $payload['opening_balance'] = $openingBalance;
            $payload['opening_date'] = is_array($detail) ? ($detail['opening_date'] ?? null) : null;
            $payload['erp_financial_account_id'] = $erpId !== null ? (int) $erpId : null;
            $payload['linked_gl'] = $classicCode;
            $payload['logo_path'] = $logoPath !== '' ? $logoPath : null;
            $payload['logo_url'] = $logoPath !== '' ? $this->publicMediaUrl($logoPath) : null;
            if (isset($payload['gl_account']) && is_array($payload['gl_account'])) {
                $payload['gl_account']['code'] = $classicCode;
            }

            return $payload;
        })->values()->all();
    }

    /**
     * @param  Collection<int, CashAccount>  $cashAccounts
     * @return list<array<string,mixed>>
     */
    public function presentCashAccounts(AccountingBook $book, Collection $cashAccounts): array
    {
        $connection = $this->sync->ensureConnection((int) $book->company_id);
        $this->bindErpDatabase((int) $book->company_id, $connection);

        $maps = $this->mapsFor($connection->id, 'financial_cash', $cashAccounts->pluck('id')->all());
        $erpIds = array_values(array_unique(array_map('strval', array_keys($maps))));
        $balances = $this->liveBalances($erpIds);
        $details = $this->walletDetails($erpIds);

        return $cashAccounts->map(function (CashAccount $cash) use ($book, $maps, $balances, $details) {
            $erpId = $this->erpIdForLocal($maps, (int) $cash->id);
            $detail = $erpId !== null ? ($details[$erpId] ?? null) : null;
            $balance = $erpId !== null && array_key_exists($erpId, $balances)
                ? (float) $balances[$erpId]
                : null;
            $classicCode = $this->classicCode(
                is_array($detail) ? (string) ($detail['name'] ?? '') : '',
                $erpId,
                $cash->code
            );

            $logoPath = is_array($detail) ? trim((string) ($detail['account_image'] ?? '')) : '';
            $payload = $cash->loadMissing('glAccount')->toArray();
            $payload['code'] = $classicCode;
            $payload['name'] = is_array($detail)
                ? $this->classicLabel((string) ($detail['name'] ?? $cash->name))
                : $cash->name;
            $payload['balance'] = $balance;
            $payload['balance_source'] = $balance === null ? 'none' : 'erp_balances';
            $openingBalance = is_array($detail) && array_key_exists('opening_balance', $detail)
                ? (float) $detail['opening_balance']
                : null;
            $payload = array_merge($payload, $this->balanceWatch->observe(
                (int) $book->company_id,
                (int) $book->id,
                'cash',
                (int) $cash->id,
                $balance,
                $openingBalance,
            ));
            $payload['opening_balance'] = $openingBalance;
            $payload['opening_date'] = is_array($detail) ? ($detail['opening_date'] ?? null) : null;
            $payload['erp_financial_account_id'] = $erpId !== null ? (int) $erpId : null;
            $payload['linked_gl'] = $classicCode;
            $payload['logo_path'] = $logoPath !== '' ? $logoPath : null;
            $payload['logo_url'] = $logoPath !== '' ? $this->publicMediaUrl($logoPath) : null;
            if (isset($payload['gl_account']) && is_array($payload['gl_account'])) {
                $payload['gl_account']['code'] = $classicCode;
            }

            return $payload;
        })->values()->all();
    }

    /**
     * Classic Balances movements (account_transactions) for mapped bank/cash desks.
     *
     * @param  Collection<int, BankAccount>  $banks
     * @param  Collection<int, CashAccount>  $cashAccounts
     * @return list<array<string,mixed>>
     */
    public function presentMovements(
        AccountingBook $book,
        Collection $banks,
        Collection $cashAccounts,
        ?int $filterBankAccountId = null,
        int $limit = 200,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $connection = $this->sync->ensureConnection((int) $book->company_id);
        $this->bindErpDatabase((int) $book->company_id, $connection);

        if (! $this->erp->tableExists('account_transactions')) {
            return [];
        }

        $bankMaps = $this->mapsFor($connection->id, 'financial_bank', $banks->pluck('id')->all());
        $cashMaps = $this->mapsFor($connection->id, 'financial_cash', $cashAccounts->pluck('id')->all());

        /** @var array<string, array{local_id:int, wallet_type:string, name:string}> $byErp */
        $byErp = [];
        foreach ($bankMaps as $erpId => $row) {
            if ($filterBankAccountId !== null && (int) $row['local_id'] !== $filterBankAccountId) {
                continue;
            }
            $local = $banks->firstWhere('id', (int) $row['local_id']);
            $byErp[(string) $erpId] = [
                'local_id' => (int) $row['local_id'],
                'wallet_type' => 'bank',
                'name' => $local?->name ? (string) $local->name : 'Bank',
            ];
        }
        foreach ($cashMaps as $erpId => $row) {
            // Cash filter only when explicitly requested via bank_account_id is not applied;
            // cash desks use their own ids. Include all cash unless filtering a bank id.
            if ($filterBankAccountId !== null) {
                continue;
            }
            $local = $cashAccounts->firstWhere('id', (int) $row['local_id']);
            $byErp[(string) $erpId] = [
                'local_id' => (int) $row['local_id'],
                'wallet_type' => 'cash',
                'name' => $local?->name ? (string) $local->name : 'Cash',
            ];
        }

        if ($byErp === []) {
            return [];
        }

        $erpIds = array_keys($byErp);
        $placeholders = implode(',', array_fill(0, count($erpIds), '?'));
        $limit = max(1, min(1000, $limit));
        $bindings = $erpIds;
        $dateSql = '';

        $from = $this->normalizeDate($dateFrom);
        $to = $this->normalizeDate($dateTo);
        if ($from !== null) {
            $dateSql .= ' AND transaction_date >= ?';
            $bindings[] = $from;
        }
        if ($to !== null) {
            $dateSql .= ' AND transaction_date <= ?';
            $bindings[] = $to;
        }

        $sql = "
            SELECT id, account_id, transaction_date, type, amount, description, reference_type, reference_id
            FROM account_transactions
            WHERE account_id IN ({$placeholders})
            {$dateSql}
            ORDER BY transaction_date DESC, id DESC
            LIMIT {$limit}
        ";

        $out = [];
        foreach ($this->erp->select($sql, $bindings) as $row) {
            $erpAccountId = (string) ($row->account_id ?? '');
            $meta = $byErp[$erpAccountId] ?? null;
            if ($meta === null) {
                continue;
            }

            $amount = round((float) ($row->amount ?? 0), 2);
            $type = strtolower((string) ($row->type ?? ''));
            $isCredit = $type === 'credit';
            $isDebit = $type === 'debit';
            $description = trim((string) ($row->description ?? '')) ?: $meta['name'];
            // Prefer human voucher numbers (e.g. PV/UGT/2026/453), never expose internal ids like "payment_voucher #621".
            $reference = $this->extractHumanReference($description);

            $out[] = [
                'id' => (int) $row->id,
                'bank_account_id' => $meta['wallet_type'] === 'bank' ? $meta['local_id'] : 0,
                'cash_account_id' => $meta['wallet_type'] === 'cash' ? $meta['local_id'] : 0,
                'wallet_type' => $meta['wallet_type'],
                'account_name' => $meta['name'],
                'transaction_date' => (string) ($row->transaction_date ?? ''),
                'description' => $description,
                'debit' => $isDebit ? number_format($amount, 2, '.', '') : '0',
                'credit' => $isCredit ? number_format($amount, 2, '.', '') : '0',
                'reference' => $reference,
                'is_reconciled' => false,
                'source' => 'erp_balances',
            ];
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $localIds
     * @return array<string, array{local_id:int, meta?:array<string,mixed>|null}>
     */
    private function mapsFor(int $connectionId, string $entityType, array $localIds): array
    {
        if ($localIds === []) {
            return [];
        }

        $rows = ErpIdMap::query()
            ->where('connection_id', $connectionId)
            ->where('entity_type', $entityType)
            ->whereIn('local_id', $localIds)
            ->get(['erp_id', 'local_id', 'meta_json']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->erp_id] = [
                'local_id' => (int) $row->local_id,
                'meta' => is_array($row->meta_json) ? $row->meta_json : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{local_id:int, meta?:array<string,mixed>|null}>  $maps
     */
    private function erpIdForLocal(array $maps, int $localId): ?string
    {
        foreach ($maps as $erpId => $row) {
            if ((int) $row['local_id'] === $localId) {
                return (string) $erpId;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $erpIds
     * @return array<string, float>
     */
    private function liveBalances(array $erpIds): array
    {
        if ($erpIds === [] || ! $this->erp->tableExists('financial_accounts')) {
            return [];
        }

        $hasTx = $this->erp->tableExists('account_transactions');
        $placeholders = implode(',', array_fill(0, count($erpIds), '?'));

        if ($hasTx) {
            $sql = "
                SELECT
                    fa.id,
                    (COALESCE(fa.opening_balance, 0)
                        + COALESCE(tx.total_credits, 0)
                        - COALESCE(tx.total_debits, 0)
                    ) AS live_balance
                FROM financial_accounts fa
                LEFT JOIN (
                    SELECT
                        account_id,
                        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS total_credits,
                        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS total_debits
                    FROM account_transactions
                    WHERE account_id IN ({$placeholders})
                    GROUP BY account_id
                ) tx ON tx.account_id = fa.id
                WHERE fa.id IN ({$placeholders})
            ";
            $bindings = array_merge($erpIds, $erpIds);
        } else {
            $sql = "
                SELECT fa.id, COALESCE(fa.current_balance, fa.opening_balance, 0) AS live_balance
                FROM financial_accounts fa
                WHERE fa.id IN ({$placeholders})
            ";
            $bindings = $erpIds;
        }

        $out = [];
        foreach ($this->erp->select($sql, $bindings) as $row) {
            $out[(string) $row->id] = (float) $row->live_balance;
        }

        return $out;
    }

    /**
     * @param  list<string>  $erpIds
     * @return array<string, array<string,mixed>>
     */
    private function walletDetails(array $erpIds): array
    {
        if ($erpIds === [] || ! $this->erp->tableExists('financial_accounts')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($erpIds), '?'));
        $hasImage = false;
        try {
            $cols = $this->erp->select('SHOW COLUMNS FROM financial_accounts LIKE ?', ['account_image']);
            $hasImage = $cols !== [];
        } catch (\Throwable $e) {
            $hasImage = false;
        }

        $hasOpeningDate = false;
        try {
            $cols = $this->erp->select('SHOW COLUMNS FROM financial_accounts LIKE ?', ['opening_date']);
            $hasOpeningDate = $cols !== [];
        } catch (\Throwable $e) {
            $hasOpeningDate = false;
        }

        $imageSelect = $hasImage ? ', account_image' : '';
        $openingDateSelect = $hasOpeningDate ? ', opening_date' : '';
        $sql = "
            SELECT id, name, type, currency, status, opening_balance, current_balance{$imageSelect}{$openingDateSelect}
            FROM financial_accounts
            WHERE id IN ({$placeholders})
        ";

        $out = [];
        foreach ($this->erp->select($sql, $erpIds) as $row) {
            $openingDate = null;
            if ($hasOpeningDate && ! empty($row->opening_date)) {
                $openingDate = substr((string) $row->opening_date, 0, 10);
            }
            $out[(string) $row->id] = [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?? ''),
                'type' => (string) ($row->type ?? ''),
                'currency' => (string) ($row->currency ?? 'TZS'),
                'status' => (string) ($row->status ?? 'active'),
                'opening_balance' => (float) ($row->opening_balance ?? 0),
                'opening_date' => $openingDate,
                'account_number' => '',
                'account_image' => $hasImage ? trim((string) ($row->account_image ?? '')) : '',
            ];
        }

        return $out;
    }

    private function publicMediaUrl(string $storedPath): string
    {
        $storedPath = trim(str_replace('\\', '/', $storedPath));
        if ($storedPath === '' || str_contains($storedPath, '..')) {
            return '';
        }
        if (preg_match('#^https?://#i', $storedPath)) {
            return $storedPath;
        }

        $base = rtrim((string) (config('erp.public_url') ?: env('ERP_PUBLIC_URL', '')), '/');
        $path = '/'.ltrim($storedPath, '/');
        if ($base !== '') {
            return $base.$path;
        }

        return $path;
    }

    private function classicCode(string $name, ?string $erpId, string $fallback): string
    {
        if (preg_match('/^(\d+)\s*[-–:]\s*(.+)$/u', trim($name), $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^FA-(\d+)$/i', $fallback, $m)) {
            return $m[1];
        }
        if ($erpId !== null && $erpId !== '') {
            return $erpId;
        }

        return $fallback;
    }

    private function classicLabel(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^(\d+)\s*[-–:]\s*(.+)$/u', $name, $m)) {
            return trim($m[2]);
        }

        return $name !== '' ? $name : 'Account';
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

    /**
     * Pull a document-style reference from a classic description when present.
     */
    private function extractHumanReference(string $description): ?string
    {
        $text = trim($description);
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b((?:PV|RV|INV|PI|JV|CN|DN)\/[A-Z0-9][A-Z0-9\/\-]*)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\b(VOUCHER\s*#?\s*[A-Z0-9][A-Z0-9\/\-]*)\b/i', $text, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
        }

        return null;
    }
}
