<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountingBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $books = AccountingBook::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with(['framework', 'jurisdiction'])
            ->orderByDesc('is_primary')
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $books]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'framework_id' => ['required', 'integer', 'exists:acc_frameworks,id'],
            'jurisdiction_id' => ['required', 'integer', 'exists:acc_jurisdictions,id'],
            'functional_currency_code' => ['required', 'string', 'size:3', 'exists:acc_currencies,code'],
            'is_primary' => ['boolean'],
        ]);

        if (! empty($data['is_primary'])) {
            AccountingBook::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->update(['is_primary' => false]);
        }

        $book = AccountingBook::withoutGlobalScopes()->create([
            ...$data,
            'company_id' => $company->id,
            'is_primary' => $data['is_primary'] ?? false,
            'status' => 'active',
        ]);

        return response()->json(['data' => $book], 201);
    }
}
