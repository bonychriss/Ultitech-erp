<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Framework;
use App\Models\Jurisdiction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::query()->where('is_active', true)->orderBy('code')->get()]);
    }

    public function frameworks(): JsonResponse
    {
        return response()->json(['data' => Framework::query()->orderBy('code')->get()]);
    }

    public function jurisdictions(): JsonResponse
    {
        return response()->json(['data' => Jurisdiction::query()->orderBy('code')->get()]);
    }

    public function rates(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => ExchangeRate::query()->where('company_id', $company->id)->orderByDesc('rate_date')->limit(100)->get(),
        ]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'from_currency' => ['required', 'string', 'size:3', 'exists:acc_currencies,code'],
            'to_currency' => ['required', 'string', 'size:3', 'exists:acc_currencies,code'],
            'rate_date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $rate = ExchangeRate::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'from_currency' => $data['from_currency'],
                'to_currency' => $data['to_currency'],
                'rate_date' => $data['rate_date'],
            ],
            [
                'rate' => $data['rate'],
                'source' => 'manual',
                'created_by' => $request->user()->id,
            ]
        );

        return response()->json(['data' => $rate], 201);
    }
}
