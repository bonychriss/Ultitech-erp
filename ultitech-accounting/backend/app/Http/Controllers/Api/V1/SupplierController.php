<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\PayableService;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(private readonly PayableService $payables) {}

    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => Supplier::query()->where('company_id', $company->id)->orderBy('code')->get(),
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

        $supplier = Supplier::query()->create([
            ...$data,
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        return response()->json(['data' => $supplier], 201);
    }

    public function bills(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => SupplierBill::query()
                ->where('book_id', $book->id)
                ->with(['supplier', 'lines'])
                ->orderByDesc('bill_date')
                ->limit(100)
                ->get(),
        ]);
    }

    public function storeBill(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:acc_suppliers,id'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'tax_code_id' => ['nullable', 'integer', 'exists:acc_tax_codes,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.expense_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $bill = $this->payables->createAndPostBill(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $bill], 201);
    }

    public function payments(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => SupplierPayment::query()
                ->where('book_id', $book->id)
                ->with(['supplier', 'allocations'])
                ->orderByDesc('payment_date')
                ->limit(100)
                ->get(),
        ]);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:acc_suppliers,id'],
            'payment_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'bank_account_id' => ['required', 'integer', 'exists:acc_bank_accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.bill_id' => ['required_with:allocations', 'integer', 'exists:acc_supplier_bills,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ]);

        $payment = $this->payables->createAndPostPayment(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $payment], 201);
    }
}
