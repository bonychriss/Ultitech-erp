<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\TaxEngineService;
use App\Http\Controllers\Controller;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TaxReconciliation;
use App\Models\TaxReturn;
use App\Models\TaxTransaction;
use App\Models\TaxType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function __construct(private readonly TaxEngineService $tax) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => TaxType::query()->orderBy('code')->get()]);
    }

    public function rates(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => TaxRate::query()
                ->with('taxType')
                ->where('jurisdiction_id', $book->jurisdiction_id)
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }

    public function codes(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => TaxCode::query()
                ->where('company_id', $company->id)
                ->with(['taxRate.taxType'])
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tax_code_id' => ['required', 'integer', 'exists:acc_tax_codes,id'],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'as_of_date' => ['required', 'date'],
        ]);

        $code = TaxCode::query()->findOrFail($data['tax_code_id']);
        $calc = $this->tax->calculate($code, (string) $data['taxable_amount'], $data['as_of_date']);

        return response()->json([
            'data' => [
                'tax_code_id' => $code->id,
                'tax_rate_id' => $calc['tax_rate']->id,
                'rate_percent' => $calc['rate_percent'],
                'taxable_amount' => $calc['taxable_amount'],
                'tax_amount' => $calc['tax_amount'],
                'gross_amount' => $calc['gross_amount'],
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => TaxTransaction::query()
                ->where('book_id', $book->id)
                ->with(['taxCode', 'taxRate'])
                ->orderByDesc('tax_date')
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
        ]);
    }

    public function returns(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => TaxReturn::query()
                ->where('company_id', $company->id)
                ->with('lines')
                ->orderByDesc('period_end')
                ->limit(50)
                ->get(),
        ]);
    }

    public function prepareReturn(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'tax_type_id' => ['required', 'integer', 'exists:acc_tax_types,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'jurisdiction_id' => ['nullable', 'integer', 'exists:acc_jurisdictions,id'],
        ]);

        $return = $this->tax->prepareReturn(
            (int) $company->id,
            (int) ($data['jurisdiction_id'] ?? $book->jurisdiction_id),
            (int) $data['tax_type_id'],
            $data['period_start'],
            $data['period_end'],
            $request->user()
        );

        return response()->json(['data' => $return], 201);
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $return = TaxReturn::query()->where('company_id', $company->id)->findOrFail($id);

        $recon = $this->tax->reconcileReturn($return, $book);

        return response()->json(['data' => $recon], 201);
    }

    public function reconciliations(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => TaxReconciliation::query()
                ->where('company_id', $company->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}
