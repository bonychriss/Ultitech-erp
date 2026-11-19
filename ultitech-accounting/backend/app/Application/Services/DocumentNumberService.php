<?php

namespace App\Application\Services;

use App\Models\CustomerInvoice;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DocumentNumberService
{
    public function next(int $companyId, string $documentType, string $prefix): string
    {
        return DB::transaction(function () use ($companyId, $documentType, $prefix) {
            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentSequence::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'next_number' => 1,
                ]);
                $sequence = DocumentSequence::withoutGlobalScopes()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            }

            $number = $sequence->prefix.str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT);
            $sequence->update(['next_number' => $sequence->next_number + 1]);

            return $number;
        });
    }

    /**
     * Classic Accounting revenue voucher format: REV-{year}-{m}-{n}
     * Monthly sequence, no leading zeros (REV-2026-11-1, REV-2026-11-2, …).
     */
    public function nextRevenueVoucher(int $companyId, ?string $entryDate = null): string
    {
        [$year, $month] = $this->revenueYearMonth($entryDate);
        $prefix = "REV-{$year}-{$month}-";
        $documentType = "revenue_voucher_{$year}_{$month}";

        return DB::transaction(function () use ($companyId, $documentType, $prefix, $year, $month) {
            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentSequence::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'next_number' => 1,
                ]);
                $sequence = DocumentSequence::withoutGlobalScopes()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            }

            $maxExisting = max(
                $this->maxRevenueSequenceFromLocal($companyId, $year, $month),
                $this->maxRevenueSequenceFromClassic($year, $month)
            );
            $next = max((int) $sequence->next_number, $maxExisting + 1);
            $number = $prefix.$next;
            $sequence->update([
                'prefix' => $prefix,
                'next_number' => $next + 1,
            ]);

            return $number;
        });
    }

    /**
     * @return array{0:int,1:int} [year, month]
     */
    private function revenueYearMonth(?string $entryDate): array
    {
        $raw = trim((string) $entryDate);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) date('Y'), (int) date('n')];
    }

    private function maxRevenueSequenceFromLocal(int $companyId, int $year, int $month): int
    {
        $patterns = $this->revenueLikePatterns($year, $month);
        $rows = CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($patterns) {
                foreach ($patterns as $i => $like) {
                    if ($i === 0) {
                        $q->where('invoice_number', 'like', $like);
                    } else {
                        $q->orWhere('invoice_number', 'like', $like);
                    }
                }
            })
            ->pluck('invoice_number');

        return $this->maxSequenceFromVoucherNumbers($rows->all(), $year, $month);
    }

    /**
     * Continue classic Accounting monthly sequence when ERP DB is linked.
     */
    private function maxRevenueSequenceFromClassic(int $year, int $month): int
    {
        try {
            if (! config('erp.enabled')) {
                return 0;
            }
            if (! Schema::connection('erp_ultimate')->hasTable('revenue_entries')) {
                return 0;
            }
            $patterns = $this->revenueLikePatterns($year, $month);
            $query = DB::connection('erp_ultimate')->table('revenue_entries');
            $query->where(function ($q) use ($patterns) {
                foreach ($patterns as $i => $like) {
                    if ($i === 0) {
                        $q->where('voucher_number', 'like', $like);
                    } else {
                        $q->orWhere('voucher_number', 'like', $like);
                    }
                }
            });
            $rows = $query->pluck('voucher_number')->all();

            return $this->maxSequenceFromVoucherNumbers($rows, $year, $month);
        } catch (\Throwable $e) {
            Log::debug('Classic revenue sequence peek skipped: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function revenueLikePatterns(int $year, int $month): array
    {
        $monthPad = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $abbr = date('M', mktime(0, 0, 0, $month, 1, 2000));

        return [
            "REV-{$year}-{$month}-%",
            "REV-{$year}-{$monthPad}-%",
            "REV-{$year}-{$abbr}-%",
        ];
    }

    /**
     * @param  list<mixed>  $numbers
     */
    private function maxSequenceFromVoucherNumbers(array $numbers, int $year, int $month): int
    {
        $max = 0;
        foreach ($numbers as $vn) {
            $vn = trim((string) $vn);
            if (! preg_match('/^REV-(\d{4})-([0-9]{1,2}|[A-Za-z]{3})-(\d+)$/i', $vn, $m)) {
                continue;
            }
            $vnYear = (int) $m[1];
            $vnMonthPart = $m[2];
            $vnSeq = (int) $m[3];
            if ($vnYear !== $year) {
                continue;
            }
            $vnMonth = ctype_digit($vnMonthPart)
                ? (int) $vnMonthPart
                : (int) date('n', strtotime('1 '.$vnMonthPart.' 2000'));
            if ($vnMonth !== $month) {
                continue;
            }
            if ($vnSeq > $max) {
                $max = $vnSeq;
            }
        }

        return $max;
    }
}
