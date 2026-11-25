<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\ErpSyncService;
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
    public function __construct(
        private readonly TaxEngineService $tax,
        private readonly ErpSyncService $erpSync,
    ) {}

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

        $base = TaxTransaction::query()->where('book_id', $book->id);

        $output = (clone $base)->where('direction', 'output')->sum('tax_amount');
        $input = (clone $base)->where('direction', 'input')->sum('tax_amount');
        $count = (clone $base)->count();
        $periodFrom = (clone $base)->min('tax_date');
        $periodTo = (clone $base)->max('tax_date');

        return response()->json([
            'data' => (clone $base)
                ->with(['taxCode', 'taxRate'])
                ->orderByDesc('tax_date')
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
            'summary' => [
                'output_tax' => round((float) $output, 2),
                'input_tax' => round((float) $input, 2),
                'net_tax' => round((float) $output - (float) $input, 2),
                'count' => (int) $count,
                'period_from' => $periodFrom ? substr((string) $periodFrom, 0, 10) : null,
                'period_to' => $periodTo ? substr((string) $periodTo, 0, 10) : null,
            ],
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

    /**
     * Build / refresh the VAT register from Accounting invoices + ERP purchases.
     */
    public function syncRegister(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $result = $this->erpSync->syncTaxRegister($book, $request->user());

        return response()->json([
            'data' => [
                ...$result,
                'transactions' => TaxTransaction::query()
                    ->where('book_id', $book->id)
                    ->count(),
            ],
        ]);
    }

    public function file(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'filing_date' => ['required', 'date'],
            'filing_reference' => ['required', 'string', 'max:120'],
        ]);

        $return = TaxReturn::query()->where('company_id', $company->id)->findOrFail($id);
        $filed = $this->tax->fileReturn(
            $return,
            $request->user(),
            $data['filing_date'],
            $data['filing_reference']
        );

        return response()->json(['data' => $filed]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'bank_account_id' => ['required', 'integer', 'exists:acc_bank_accounts,id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
        ]);

        $return = TaxReturn::query()->where('company_id', $company->id)->findOrFail($id);
        $paid = $this->tax->recordPayment(
            $return,
            $book,
            $request->user(),
            $data['payment_date'],
            (string) $data['amount'],
            (int) $data['bank_account_id'],
            strtoupper((string) ($data['currency_code'] ?? 'TZS'))
        );

        return response()->json(['data' => $paid]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'direction' => ['required', 'string', 'in:output,input'],
            'tax_date' => ['required', 'date'],
            'taxable_amount' => ['required', 'numeric'],
            'tax_amount' => ['required', 'numeric'],
            'memo' => ['nullable', 'string', 'max:255'],
            'supply_category' => ['nullable', 'string', 'in:standard,zero_rated,exempt,out_of_scope,reverse_charge,adjustment'],
        ]);

        $txn = $this->tax->recordAdjustment(
            $book,
            $data['direction'],
            $data['tax_date'],
            (string) $data['taxable_amount'],
            (string) $data['tax_amount'],
            (string) ($data['memo'] ?? 'VAT adjustment'),
            $data['supply_category'] ?? 'adjustment'
        );

        return response()->json(['data' => $txn], 201);
    }
}
