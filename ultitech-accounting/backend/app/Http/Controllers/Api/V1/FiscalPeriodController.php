<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\PeriodLock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalPeriodController extends Controller
{
    public function years(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => FiscalYear::query()->where('company_id', $company->id)->with('periods')->orderBy('start_date')->get(),
        ]);
    }

    public function storeYear(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $year = FiscalYear::query()->create([
            ...$data,
            'company_id' => $company->id,
            'status' => 'open',
        ]);

        $start = Carbon::parse($data['start_date'])->startOfMonth();
        $end = Carbon::parse($data['end_date'])->endOfMonth();
        $periodNumber = 1;

        while ($start->lte($end)) {
            $periodEnd = $start->copy()->endOfMonth();
            if ($periodEnd->gt($end)) {
                $periodEnd = Carbon::parse($data['end_date']);
            }

            FiscalPeriod::query()->create([
                'company_id' => $company->id,
                'fiscal_year_id' => $year->id,
                'period_number' => $periodNumber,
                'name' => $start->format('M Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'status' => 'open',
            ]);

            $start->addMonth()->startOfMonth();
            $periodNumber++;
        }

        return response()->json(['data' => $year->load('periods')], 201);
    }

    public function lock(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lock = PeriodLock::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'book_id' => $book->id,
                'period_id' => $data['period_id'],
            ],
            [
                'locked_at' => now(),
                'locked_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
            ]
        );

        return response()->json(['data' => $lock], 201);
    }
}
