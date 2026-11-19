<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\FixedAssetService;
use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\DepreciationMethod;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public function methods(): JsonResponse
    {
        return response()->json(['data' => DepreciationMethod::query()->orderBy('code')->get()]);
    }

    public function categories(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => AssetCategory::query()->where('book_id', $book->id)->orderBy('code')->get(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'asset_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'accum_depr_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'depr_expense_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'depreciation_method_id' => ['required', 'integer', 'exists:acc_depreciation_methods,id'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'salvage_percent' => ['nullable', 'numeric', 'min:0'],
        ]);

        $category = AssetCategory::query()->create([
            ...$data,
            'company_id' => $company->id,
            'book_id' => $book->id,
            'salvage_percent' => $data['salvage_percent'] ?? 0,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => FixedAsset::query()->where('book_id', $book->id)->with('category')->orderBy('asset_code')->get(),
        ]);
    }

    public function acquire(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'category_id' => ['required', 'integer', 'exists:acc_asset_categories,id'],
            'asset_code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'acquisition_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1'],
            'credit_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $asset = $this->assets->acquire($request->attributes->get('book'), $request->user(), $payload);

        return response()->json(['data' => $asset], 201);
    }

    public function depreciate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id'],
        ]);

        $run = $this->assets->runDepreciation(
            $request->attributes->get('book'),
            $request->user(),
            (int) $payload['period_id']
        );

        return response()->json(['data' => $run], 201);
    }

    public function runs(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => DepreciationRun::query()->where('book_id', $book->id)->with('lines')->orderByDesc('run_date')->get(),
        ]);
    }
}
