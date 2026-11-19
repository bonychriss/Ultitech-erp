<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\ReceivableService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerReceipt;
use App\Models\ErpIdMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly ReceivableService $receivables) {}

    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => Customer::query()->where('company_id', $company->id)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:acc_currencies,code'],
        ]);

        $customer = Customer::query()->create([
            ...$data,
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        return response()->json(['data' => $customer], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $company = $request->attributes->get('company');

        $invoices = CustomerInvoice::query()
            ->where('book_id', $book->id)
            ->with(['customer', 'lines'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $maps = ErpIdMap::query()
            ->where('company_id', $company->id)
            ->where('entity_type', 'customer_invoice')
            ->whereIn('local_id', $invoices->pluck('id')->all())
            ->get()
            ->keyBy('local_id');

        $data = $invoices->map(function (CustomerInvoice $invoice) use ($maps) {
            $payload = $invoice->toArray();
            $map = $maps->get($invoice->id);
            $meta = is_array($map?->meta_json) ? $map->meta_json : [];
            $orderId = (int) ($meta['order_id'] ?? 0);
            $payload['erp_invoice_id'] = $map ? (string) $map->erp_id : null;
            $payload['erp_order_id'] = $orderId > 0 ? $orderId : null;

            return $payload;
        });

        return response()->json(['data' => $data]);
    }

    public function storeInvoice(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:acc_customers,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'tax_code_id' => ['nullable', 'integer', 'exists:acc_tax_codes,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.revenue_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $invoice = $this->receivables->createAndPostInvoice(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function receipts(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => CustomerReceipt::query()
                ->where('book_id', $book->id)
                ->with(['customer', 'allocations'])
                ->orderByDesc('receipt_date')
                ->limit(100)
                ->get(),
        ]);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:acc_customers,id'],
            'receipt_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'bank_account_id' => ['nullable', 'integer', 'exists:acc_bank_accounts,id'],
            'cash_account_id' => ['nullable', 'integer', 'exists:acc_cash_accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:acc_customer_invoices,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ]);

        $receipt = $this->receivables->createAndPostReceipt(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $receipt], 201);
    }

    /**
     * Immediate cash / other income (invoice + full receipt in one step).
     */
    public function storeManualRevenue(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'revenue_date' => ['required', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'revenue_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:acc_customers,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:acc_bank_accounts,id'],
            'cash_account_id' => ['nullable', 'integer', 'exists:acc_cash_accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if (empty($payload['bank_account_id']) && empty($payload['cash_account_id'])) {
            return response()->json([
                'message' => 'Choose a bank/mobile or cash account to deposit into.',
            ], 422);
        }
        if (! empty($payload['bank_account_id']) && ! empty($payload['cash_account_id'])) {
            return response()->json([
                'message' => 'Choose either a bank/mobile account or a cash account, not both.',
            ], 422);
        }

        $result = $this->receivables->createAndPostManualRevenue(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $result], 201);
    }
}
