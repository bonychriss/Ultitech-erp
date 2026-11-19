<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function createStatement(BankAccount $bank, User $user, array $payload): BankStatement
    {
        return DB::transaction(function () use ($bank, $user, $payload) {
            $statement = BankStatement::withoutGlobalScopes()->create([
                'company_id' => $bank->company_id,
                'bank_account_id' => $bank->id,
                'statement_number' => $payload['statement_number'],
                'statement_date' => $payload['statement_date'],
                'period_start' => $payload['period_start'],
                'period_end' => $payload['period_end'],
                'opening_balance' => Money::normalize($payload['opening_balance'] ?? 0),
                'closing_balance' => Money::normalize($payload['closing_balance'] ?? 0),
                'status' => 'open',
                'created_by' => $user->id,
            ]);

            foreach ($payload['lines'] as $index => $line) {
                BankStatementLine::withoutGlobalScopes()->create([
                    'company_id' => $bank->company_id,
                    'statement_id' => $statement->id,
                    'line_no' => $index + 1,
                    'line_date' => $line['line_date'],
                    'description' => $line['description'] ?? null,
                    'debit' => Money::normalize($line['debit'] ?? 0),
                    'credit' => Money::normalize($line['credit'] ?? 0),
                    'reference' => $line['reference'] ?? null,
                    'is_matched' => false,
                ]);
            }

            return $statement->load('lines');
        });
    }

    public function startReconciliation(BankAccount $bank, BankStatement $statement, User $user): BankReconciliation
    {
        $glBalance = $this->glBalance($bank, $statement->period_end);

        return BankReconciliation::withoutGlobalScopes()->create([
            'company_id' => $bank->company_id,
            'bank_account_id' => $bank->id,
            'statement_id' => $statement->id,
            'reconciliation_date' => $statement->statement_date,
            'gl_balance' => $glBalance,
            'statement_balance' => Money::normalize($statement->closing_balance),
            'unreconciled_total' => '0.0000',
            'status' => 'in_progress',
        ]);
    }

    public function match(BankReconciliation $recon, int $statementLineId, int $bankTransactionId): BankMatch
    {
        if ($recon->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => 'Reconciliation is not in progress.']);
        }

        $line = BankStatementLine::withoutGlobalScopes()
            ->where('company_id', $recon->company_id)
            ->where('statement_id', $recon->statement_id)
            ->where('id', $statementLineId)
            ->firstOrFail();

        $txn = BankTransaction::withoutGlobalScopes()
            ->where('company_id', $recon->company_id)
            ->where('bank_account_id', $recon->bank_account_id)
            ->where('id', $bankTransactionId)
            ->firstOrFail();

        if ($line->is_matched || $txn->is_reconciled) {
            throw ValidationException::withMessages(['match' => 'Line or transaction already matched.']);
        }

        $lineAmount = Money::normalize(bcsub(Money::normalize($line->debit), Money::normalize($line->credit), 4));
        $txnAmount = Money::normalize(bcsub(Money::normalize($txn->debit), Money::normalize($txn->credit), 4));

        if (! Money::equals($lineAmount, $txnAmount)) {
            throw ValidationException::withMessages(['match' => 'Amounts do not match.']);
        }

        return DB::transaction(function () use ($recon, $line, $txn, $lineAmount) {
            $match = BankMatch::withoutGlobalScopes()->create([
                'company_id' => $recon->company_id,
                'reconciliation_id' => $recon->id,
                'statement_line_id' => $line->id,
                'bank_transaction_id' => $txn->id,
                'matched_amount' => abs((float) $lineAmount) >= 0 ? Money::normalize(abs((float) $lineAmount)) : '0.0000',
            ]);

            // store absolute matched amount properly
            $match->update([
                'matched_amount' => Money::normalize(
                    max(
                        (float) Money::normalize($line->debit),
                        (float) Money::normalize($line->credit)
                    )
                ),
            ]);

            $line->update(['is_matched' => true]);
            $txn->update(['is_reconciled' => true]);

            return $match;
        });
    }

    public function complete(BankReconciliation $recon, User $user): BankReconciliation
    {
        $unmatchedLines = BankStatementLine::withoutGlobalScopes()
            ->where('statement_id', $recon->statement_id)
            ->where('is_matched', false)
            ->get();

        $unreconciled = $unmatchedLines->reduce(function ($carry, $line) {
            $amt = Money::normalize(bcsub(Money::normalize($line->debit), Money::normalize($line->credit), 4));

            return Money::add($carry, Money::normalize(abs((float) $amt)));
        }, '0.0000');

        $diff = Money::normalize(bcsub(
            Money::normalize($recon->statement_balance),
            Money::normalize($recon->gl_balance),
            4
        ));

        if (! Money::isZero($unreconciled) && ! Money::equals($unreconciled, Money::normalize(abs((float) $diff)))) {
            // Allow completion with outstanding items recorded
        }

        $recon->update([
            'unreconciled_total' => $unreconciled,
            'status' => 'completed',
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);

        BankStatement::withoutGlobalScopes()->whereKey($recon->statement_id)->update(['status' => 'reconciled']);

        $this->audit->record('bank.reconciled', 'bank_reconciliation', $recon->id, null, [
            'bank_account_id' => $recon->bank_account_id,
            'unreconciled_total' => $unreconciled,
        ]);

        return $recon->fresh();
    }

    public function unmatchedTransactions(int $companyId, int $bankAccountId): \Illuminate\Support\Collection
    {
        return BankTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->where('is_reconciled', false)
            ->orderBy('transaction_date')
            ->get();
    }

    private function glBalance(BankAccount $bank, string $asOf): string
    {
        $account = Account::withoutGlobalScopes()->findOrFail($bank->gl_account_id);
        $service = app(AccountBalanceService::class);
        $row = $service->trialBalance((int) $bank->company_id, (int) $bank->book_id, $asOf)
            ->firstWhere('account_id', $account->id);

        if (! $row) {
            return '0.0000';
        }

        return Money::normalize(bcsub($row['debit'], $row['credit'], 4));
    }
}
