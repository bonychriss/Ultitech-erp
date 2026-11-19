<?php

namespace App\Application\Services;

use App\Models\ExchangeRate;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ExchangeRateService
{
    public function resolveRate(int $companyId, string $from, string $to, string $date): string
    {
        if ($from === $to) {
            return Money::normalize('1', 8);
        }

        $rate = ExchangeRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        if (! $rate) {
            throw ValidationException::withMessages([
                'exchange_rate' => "No exchange rate found for {$from}/{$to} on or before {$date}.",
            ]);
        }

        return Money::normalize($rate->rate, 8);
    }
}
