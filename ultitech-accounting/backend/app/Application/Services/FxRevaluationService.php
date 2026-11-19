<?php

namespace App\Application\Services;

use App\Models\AccountMapping;
use App\Models\AccountingBook;
use App\Models\FiscalPeriod;
use App\Models\FxRevaluation;
use App\Models\FxRevaluationLine;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FxRevaluationService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ExchangeRateService $rates,
        private readonly AuditTrailService $audit,
    ) {}

    public function run(AccountingBook $book, User $user, int $periodId): FxRevaluation
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereKey($periodId)
            ->firstOrFail();

        $functional = $book->functional_currency_code;
        $asOf = \Carbon\Carbon::parse($period->end_date)->toDateString();

        $positions = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->join('acc_account_types', 'acc_account_types.id', '=', 'acc_accounts.account_type_id')
            ->where('acc_journal_lines.company_id', $book->company_id)
            ->where('acc_journal_lines.book_id', $book->id)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('acc_journal_entries.journal_date', '<=', $asOf)
            ->where('acc_journal_lines.currency_code', '!=', $functional)
            ->whereIn('acc_account_types.code', ['asset', 'liability'])
            ->select(
                'acc_journal_lines.account_id',
                'acc_journal_lines.currency_code',
                DB::raw('SUM(acc_journal_lines.debit - acc_journal_lines.credit) as foreign_balance'),
                DB::raw('SUM(acc_journal_lines.functional_debit - acc_journal_lines.functional_credit) as book_functional')
            )
            ->groupBy('acc_journal_lines.account_id', 'acc_journal_lines.currency_code')
            ->havingRaw('ABS(SUM(acc_journal_lines.debit - acc_journal_lines.credit)) > 0.0001')
            ->get();

        if ($positions->isEmpty()) {
            throw ValidationException::withMessages([
                'period_id' => 'No foreign-currency monetary positions to revalue for this period.',
            ]);
        }

        return DB::transaction(function () use ($book, $user, $period, $functional, $asOf, $positions) {
            $lines = [];
            $totalGain = '0.0000';
            $totalLoss = '0.0000';
            $journalLines = [];

            foreach ($positions as $pos) {
                $rate = $this->rates->resolveRate(
                    (int) $book->company_id,
                    $pos->currency_code,
                    $functional,
                    $asOf
                );
                $foreign = Money::normalize($pos->foreign_balance);
                $bookFunctional = Money::normalize($pos->book_functional);
                $revalued = Money::multiply($foreign, $rate);
                $diff = Money::normalize(bcsub($revalued, $bookFunctional, 4));

                if (Money::isZero($diff)) {
                    continue;
                }

                $lines[] = [
                    'account_id' => (int) $pos->account_id,
                    'currency_code' => $pos->currency_code,
                    'foreign_balance' => $foreign,
                    'book_functional' => $bookFunctional,
                    'closing_rate' => $rate,
                    'revalued_functional' => $revalued,
                    'difference' => $diff,
                ];

                // Positive diff = asset/liability needs increase in functional (gain for net asset increase)
                if (bccomp($diff, '0', 4) > 0) {
                    $totalGain = Money::add($totalGain, $diff);
                    $journalLines[] = [
                        'account_id' => (int) $pos->account_id,
                        'debit' => $diff,
                        'credit' => '0',
                        'description' => 'FX revaluation',
                    ];
                } else {
                    $loss = Money::normalize(bcmul($diff, '-1', 4));
                    $totalLoss = Money::add($totalLoss, $loss);
                    $journalLines[] = [
                        'account_id' => (int) $pos->account_id,
                        'debit' => '0',
                        'credit' => $loss,
                        'description' => 'FX revaluation',
                    ];
                }
            }

            if (empty($lines)) {
                throw ValidationException::withMessages([
                    'period_id' => 'Foreign positions exist but closing-rate differences are zero.',
                ]);
            }

            $gainAccount = $this->mapped((int) $book->company_id, (int) $book->id, 'fx_gain');
            $lossAccount = $this->mapped((int) $book->company_id, (int) $book->id, 'fx_loss');

            if (! Money::isZero($totalGain)) {
                $journalLines[] = [
                    'account_id' => $gainAccount,
                    'debit' => '0',
                    'credit' => $totalGain,
                    'description' => 'Unrealized FX gain',
                ];
            }
            if (! Money::isZero($totalLoss)) {
                $journalLines[] = [
                    'account_id' => $lossAccount,
                    'debit' => $totalLoss,
                    'credit' => '0',
                    'description' => 'Unrealized FX loss',
                ];
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $asOf,
                'description' => 'FX revaluation '.$period->name,
                'reference' => 'FXR-'.$period->id,
                'currency_code' => $functional,
                'source_type' => 'fx_revaluation',
                'source_id_ref' => (string) $period->id.'-'.$asOf,
                'source_hash' => 'fx_revaluation:'.$book->id.':'.$period->id.':'.$asOf,
                'lines' => $journalLines,
            ], 'system');

            $reval = FxRevaluation::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'period_id' => $period->id,
                'revaluation_date' => $asOf,
                'functional_currency' => $functional,
                'total_gain' => $totalGain,
                'total_loss' => $totalLoss,
                'journal_id' => $journal->id,
                'status' => 'posted',
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                FxRevaluationLine::query()->create([
                    ...$line,
                    'fx_revaluation_id' => $reval->id,
                    'company_id' => $book->company_id,
                ]);
            }

            $this->audit->record('fx.revaluation_posted', 'fx_revaluation', $reval->id, null, [
                'total_gain' => $totalGain,
                'total_loss' => $totalLoss,
            ]);

            return $reval->load('lines');
        });
    }

    private function mapped(int $companyId, int $bookId, string $key): int
    {
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('mapping_key', $key)
            ->first();

        if (! $mapping) {
            throw ValidationException::withMessages([
                'mapping' => "Account mapping '{$key}' is required for FX revaluation.",
            ]);
        }

        return (int) $mapping->account_id;
    }
}
