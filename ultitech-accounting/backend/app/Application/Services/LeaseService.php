<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\Lease;
use App\Models\LeaseSchedule;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaseService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
    ) {}

    public function create(AccountingBook $book, User $user, array $payload): Lease
    {
        $term = (int) $payload['term_months'];
        $payment = Money::normalize($payload['payment_amount']);
        $annualRate = Money::normalize($payload['annual_rate_percent'], 4);
        $monthlyRate = bcdiv($annualRate, '1200', 10); // percent / 12 / 100

        $pv = $this->presentValueAnnuity($payment, $monthlyRate, $term);
        $depreciation = Money::normalize(bcdiv($pv, (string) $term, 4));

        return DB::transaction(function () use ($book, $user, $payload, $term, $payment, $annualRate, $monthlyRate, $pv, $depreciation) {
            $number = $payload['lease_number'] ?? $this->numbers->next((int) $book->company_id, 'lease', 'LS');
            $start = Carbon::parse($payload['commencement_date']);

            $lease = Lease::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'lease_number' => $number,
                'name' => $payload['name'],
                'commencement_date' => $start->toDateString(),
                'term_months' => $term,
                'payment_amount' => $payment,
                'annual_rate_percent' => $annualRate,
                'initial_liability' => $pv,
                'rou_asset_cost' => $pv,
                'liability_balance' => $pv,
                'rou_net_book_value' => $pv,
                'rou_account_id' => $payload['rou_account_id'],
                'liability_account_id' => $payload['liability_account_id'],
                'interest_account_id' => $payload['interest_account_id'],
                'depreciation_account_id' => $payload['depreciation_account_id'],
                'status' => 'active',
            ]);

            $opening = $pv;
            for ($i = 1; $i <= $term; $i++) {
                $interest = Money::normalize(bcmul($opening, $monthlyRate, 8), 4);
                $principal = Money::normalize(bcsub($payment, $interest, 4));
                // Final period adjustment to clear liability
                if ($i === $term) {
                    $principal = $opening;
                    $interest = Money::normalize(bcsub($payment, $principal, 4));
                    if (bccomp($interest, '0', 4) < 0) {
                        $interest = '0.0000';
                        $principal = $payment;
                    }
                }
                $closing = Money::normalize(bcsub($opening, $principal, 4));
                if ($i === $term) {
                    $closing = '0.0000';
                }

                LeaseSchedule::query()->create([
                    'lease_id' => $lease->id,
                    'company_id' => $book->company_id,
                    'period_no' => $i,
                    'due_date' => $start->copy()->addMonths($i)->toDateString(),
                    'opening_liability' => $opening,
                    'interest' => $interest,
                    'payment' => $payment,
                    'principal' => $principal,
                    'closing_liability' => $closing,
                    'depreciation' => $depreciation,
                    'status' => 'open',
                ]);

                $opening = $closing;
            }

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $lease->commencement_date->toDateString(),
                'description' => 'Lease commencement '.$lease->lease_number,
                'reference' => $lease->lease_number,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'lease',
                'source_id_ref' => (string) $lease->id,
                'source_hash' => 'lease:commence:'.$lease->id,
                'lines' => [
                    [
                        'account_id' => (int) $lease->rou_account_id,
                        'debit' => $pv,
                        'credit' => '0',
                        'description' => 'ROU asset',
                    ],
                    [
                        'account_id' => (int) $lease->liability_account_id,
                        'debit' => '0',
                        'credit' => $pv,
                        'description' => 'Lease liability',
                    ],
                ],
            ], 'system');

            $lease->update(['commencement_journal_id' => $journal->id]);

            $this->audit->record('lease.created', 'lease', $lease->id, null, [
                'lease_number' => $lease->lease_number,
                'initial_liability' => $pv,
            ]);

            return $lease->fresh('schedules');
        });
    }

    public function postPeriod(Lease $lease, AccountingBook $book, User $user, int $periodNo, int $cashAccountId): LeaseSchedule
    {
        $schedule = LeaseSchedule::query()
            ->where('lease_id', $lease->id)
            ->where('period_no', $periodNo)
            ->firstOrFail();

        if ($schedule->status === 'posted') {
            throw ValidationException::withMessages(['period_no' => 'Lease period already posted.']);
        }

        return DB::transaction(function () use ($lease, $book, $user, $schedule, $cashAccountId) {
            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $schedule->due_date->toDateString(),
                'description' => 'Lease '.$lease->lease_number.' period '.$schedule->period_no,
                'reference' => $lease->lease_number.'-P'.$schedule->period_no,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'lease_schedule',
                'source_id_ref' => (string) $schedule->id,
                'source_hash' => 'lease:period:'.$schedule->id,
                'lines' => [
                    [
                        'account_id' => (int) $lease->interest_account_id,
                        'debit' => $schedule->interest,
                        'credit' => '0',
                        'description' => 'Lease interest',
                    ],
                    [
                        'account_id' => (int) $lease->liability_account_id,
                        'debit' => $schedule->principal,
                        'credit' => '0',
                        'description' => 'Lease principal',
                    ],
                    [
                        'account_id' => $cashAccountId,
                        'debit' => '0',
                        'credit' => $schedule->payment,
                        'description' => 'Lease payment',
                    ],
                    [
                        'account_id' => (int) $lease->depreciation_account_id,
                        'debit' => $schedule->depreciation,
                        'credit' => '0',
                        'description' => 'ROU depreciation',
                    ],
                    [
                        'account_id' => (int) $lease->rou_account_id,
                        'debit' => '0',
                        'credit' => $schedule->depreciation,
                        'description' => 'ROU accum depreciation',
                    ],
                ],
            ], 'system');

            $schedule->update([
                'status' => 'posted',
                'journal_id' => $journal->id,
            ]);

            $lease->update([
                'liability_balance' => $schedule->closing_liability,
                'rou_net_book_value' => Money::normalize(bcsub($lease->rou_net_book_value, $schedule->depreciation, 4)),
            ]);

            return $schedule->fresh();
        });
    }

    private function presentValueAnnuity(string $payment, string $monthlyRate, int $term): string
    {
        if (bccomp($monthlyRate, '0', 10) === 0) {
            return Money::normalize(bcmul($payment, (string) $term, 4));
        }

        // PV = PMT * (1 - (1+r)^-n) / r
        $onePlus = bcadd('1', $monthlyRate, 12);
        $factor = bcpow($onePlus, (string) $term, 12);
        $inv = bcdiv('1', $factor, 12);
        $numerator = bcsub('1', $inv, 12);
        $annuity = bcdiv($numerator, $monthlyRate, 12);

        return Money::normalize(bcmul($payment, $annuity, 8), 4);
    }
}
