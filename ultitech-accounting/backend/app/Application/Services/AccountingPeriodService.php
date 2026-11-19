<?php

namespace App\Application\Services;

use App\Models\FiscalPeriod;
use App\Models\PeriodLock;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function resolveOpenPeriod(int $companyId, int $bookId, string $date): FiscalPeriod
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'journal_date' => 'No fiscal period covers the journal date.',
            ]);
        }

        if ($period->status !== 'open') {
            throw ValidationException::withMessages([
                'period_id' => 'The fiscal period is closed.',
            ]);
        }

        $locked = PeriodLock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('period_id', $period->id)
            ->exists();

        if ($locked) {
            throw ValidationException::withMessages([
                'period_id' => 'The fiscal period is locked for this accounting book.',
            ]);
        }

        return $period;
    }
}
