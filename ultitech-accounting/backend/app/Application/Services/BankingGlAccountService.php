<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * Ensures Cash & Bank desks always link to a postable asset GL account.
 */
final class BankingGlAccountService
{
    /**
     * @param  'bank'|'mobile_money'|'cash'  $walletType
     */
    public function resolveOrCreate(
        Company $company,
        AccountingBook $book,
        string $walletType,
        string $deskCode,
        string $deskName,
        ?int $glAccountId = null,
    ): Account {
        if ($glAccountId) {
            return $this->assertUsableAssetWallet($company, $book, $glAccountId, $walletType);
        }

        $assetTypeId = (int) AccountType::query()->where('code', 'asset')->value('id');
        if ($assetTypeId <= 0) {
            throw ValidationException::withMessages([
                'gl_account_id' => 'Asset account type is missing from the chart catalog.',
            ]);
        }

        $existing = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->where('code', $deskCode)
            ->first();

        if ($existing) {
            return $this->assertUsableAssetWallet($company, $book, (int) $existing->id, $walletType);
        }

        $parent = $this->findWalletParent($company, $book, $walletType);
        $code = $this->uniqueGlCode($company, $book, $deskCode, $walletType, $parent);

        return Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'account_type_id' => $assetTypeId,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $deskName,
            'description' => 'Auto-created asset wallet for Cash & Bank desk '.$deskCode,
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_system' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => $parent ? ((int) $parent->level + 1) : 1,
            'path' => 'banking',
        ]);
    }

    /**
     * @param  'bank'|'mobile_money'|'cash'  $walletType
     */
    public function assertUsableAssetWallet(
        Company $company,
        AccountingBook $book,
        int $glAccountId,
        string $walletType,
    ): Account {
        $account = Account::withoutGlobalScopes()
            ->with('accountType')
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->find($glAccountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'gl_account_id' => 'GL account was not found in this company book.',
            ]);
        }

        if (strtolower((string) ($account->accountType?->code ?? '')) !== 'asset') {
            throw ValidationException::withMessages([
                'gl_account_id' => 'Bank, cash and mobile money desks must link to an asset GL account.',
            ]);
        }

        if (! $account->is_postable) {
            throw ValidationException::withMessages([
                'gl_account_id' => 'Select a postable wallet GL account, not a parent header.',
            ]);
        }

        if ($account->status !== 'active') {
            throw ValidationException::withMessages([
                'gl_account_id' => 'The selected GL account is inactive.',
            ]);
        }

        return $account;
    }

    /**
     * @param  'bank'|'mobile_money'|'cash'  $walletType
     */
    private function findWalletParent(Company $company, AccountingBook $book, string $walletType): ?Account
    {
        $hints = match ($walletType) {
            'cash' => ['1001', 'Cash', 'Petty Cash'],
            'mobile_money' => ['1003', 'Mobile Money', 'Mobile Money Accounts'],
            default => ['1002', 'Bank', 'BANK ACCOUNTS'],
        };

        foreach ($hints as $hint) {
            $account = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('book_id', $book->id)
                ->where(function ($q) use ($hint) {
                    $q->where('code', $hint)->orWhere('name', $hint);
                })
                ->orderBy('id')
                ->first();
            if ($account) {
                return $account;
            }
        }

        // Fall back to Assets header if present.
        return Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->where(function ($q) {
                $q->where('code', '1000')->orWhere('name', 'like', '%Assets%');
            })
            ->where('is_postable', false)
            ->orderBy('code')
            ->first();
    }

    /**
     * @param  'bank'|'mobile_money'|'cash'  $walletType
     */
    private function uniqueGlCode(
        Company $company,
        AccountingBook $book,
        string $deskCode,
        string $walletType,
        ?Account $parent,
    ): string {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', $deskCode) ?: 'WALLET';
        $candidates = [$base];

        if ($parent && preg_match('/^\d+$/', (string) $parent->code)) {
            $candidates[] = $parent->code.'-'.$base;
        } else {
            $prefix = match ($walletType) {
                'cash' => '1001',
                'mobile_money' => '1003',
                default => '1002',
            };
            $candidates[] = $prefix.'-'.$base;
        }

        foreach ($candidates as $code) {
            $code = substr($code, 0, 40);
            $exists = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('book_id', $book->id)
                ->where('code', $code)
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        for ($i = 2; $i < 1000; $i++) {
            $code = substr($base.'-'.$i, 0, 40);
            $exists = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('book_id', $book->id)
                ->where('code', $code)
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        return substr($base.'-'.uniqid(), 0, 40);
    }
}
