<?php

namespace App\Domains\CashBook;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Optional sync from Cash Book entries ? Balances deposit wallets.
 * Existing cash_book_* and erp_expenses rows are never migrated or rewritten.
 */
final class BalancesBridge
{
    public const REF_TYPE = 'cash_book_entry';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $fn = $root . '/modules/balances/functions.php';
        if (is_file($fn)) {
            require_once $fn;
        }
        self::$booted = true;
    }

    public static function pdo(): ?PDO
    {
        try {
            $pdo = DB::connection()->getPdo();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;

                return $pdo;
            }
        } catch (Throwable $e) {
            error_log('CashBook BalancesBridge::pdo: ' . $e->getMessage());
        }

        return null;
    }

    /** @return list<array{id:int,name:string,type:string,currency:string,current_balance:float}> */
    public static function depositAccounts(): array
    {
        self::boot();
        $pdo = self::pdo();
        if ($pdo === null || !function_exists('balancesFetchDepositAccounts')) {
            return [];
        }

        $rows = balancesFetchDepositAccounts($pdo);
        $out = [];
        foreach ($rows as $acc) {
            $id = (int) ($acc['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($acc['name'] ?? ('Account #' . $id)),
                'type' => (string) ($acc['type'] ?? ''),
                'currency' => (string) ($acc['currency'] ?? 'TZS'),
                'current_balance' => round((float) ($acc['current_balance'] ?? $acc['balance'] ?? 0), 2),
            ];
        }

        return $out;
    }

    public static function assertDepositAccountId(int $accountId): void
    {
        if ($accountId <= 0) {
            return;
        }
        foreach (self::depositAccounts() as $acc) {
            if ((int) $acc['id'] === $accountId) {
                return;
            }
        }
        throw new InvalidArgumentException('Select a cash, bank, or mobile wallet from Balances.');
    }

    public static function accountLabel(int $accountId): string
    {
        if ($accountId <= 0) {
            return '';
        }
        foreach (self::depositAccounts() as $acc) {
            if ((int) $acc['id'] === $accountId) {
                return (string) $acc['name'];
            }
        }

        try {
            $name = DB::table('financial_accounts')->where('id', $accountId)->value('name');

            return $name ? (string) $name : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $entry  id, entry_type, amount, entry_date, party_name, remark, category_name?
     */
    public static function syncEntry(int $walletAccountId, array $entry): void
    {
        if ($walletAccountId <= 0) {
            return;
        }

        self::boot();
        $pdo = self::pdo();
        if ($pdo === null || !function_exists('balancesRecordTransaction')) {
            throw new InvalidArgumentException('Balances module is not available to sync this entry.');
        }

        $entryId = (int) ($entry['id'] ?? 0);
        $amount = round((float) ($entry['amount'] ?? 0), 2);
        $type = (string) ($entry['entry_type'] ?? '');
        if ($entryId <= 0 || $amount <= 0 || ($type !== 'in' && $type !== 'out')) {
            return;
        }

        self::removeEntry($walletAccountId, $entryId);

        $balType = $type === 'in' ? 'credit' : 'debit';
        $date = trim((string) ($entry['entry_date'] ?? date('Y-m-d')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date .= ' 12:00:00';
        }

        $parts = array_filter([
            $type === 'out' ? 'Cash out' : 'Cash in',
            trim((string) ($entry['category_name'] ?? '')),
            trim((string) ($entry['party_name'] ?? '')),
            trim((string) ($entry['remark'] ?? '')),
        ], static fn ($p) => $p !== '');
        $description = mb_substr(implode(' - ', $parts), 0, 255);

        $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
        $ok = balancesRecordTransaction(
            $pdo,
            $walletAccountId,
            $balType,
            $amount,
            $description !== '' ? $description : 'Cash book entry',
            self::REF_TYPE,
            $entryId,
            $date,
            $companyId > 0 ? $companyId : null
        );
        if (!$ok) {
            throw new InvalidArgumentException('Could not post this entry to the Balances wallet.');
        }

        if ($pdo->inTransaction() && function_exists('balancesRecalculateAccount')) {
            balancesRecalculateAccount($pdo, $walletAccountId, $companyId > 0 ? $companyId : null);
        }
    }

    public static function removeEntry(int $walletAccountId, int $entryId): void
    {
        if ($entryId <= 0) {
            return;
        }

        self::boot();
        $pdo = self::pdo();
        if ($pdo === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'DELETE FROM account_transactions WHERE reference_type = ? AND reference_id = ?'
            );
            $stmt->execute([self::REF_TYPE, $entryId]);
        } catch (Throwable $e) {
            error_log('CashBook BalancesBridge::removeEntry: ' . $e->getMessage());

            return;
        }

        if ($walletAccountId > 0 && function_exists('balancesRecalculateAccount')) {
            $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
            balancesRecalculateAccount($pdo, $walletAccountId, $companyId > 0 ? $companyId : null);
        }
    }

    /** @param list<int> $entryIds */
    public static function removeEntries(int $walletAccountId, array $entryIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $entryIds), static fn ($id) => $id > 0));
        if ($ids === []) {
            return;
        }

        self::boot();
        $pdo = self::pdo();
        if ($pdo === null) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([self::REF_TYPE], $ids);
            $stmt = $pdo->prepare(
                "DELETE FROM account_transactions WHERE reference_type = ? AND reference_id IN ($placeholders)"
            );
            $stmt->execute($params);
        } catch (Throwable $e) {
            error_log('CashBook BalancesBridge::removeEntries: ' . $e->getMessage());

            return;
        }

        if ($walletAccountId > 0 && function_exists('balancesRecalculateAccount')) {
            $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
            balancesRecalculateAccount($pdo, $walletAccountId, $companyId > 0 ? $companyId : null);
        }
    }
}
