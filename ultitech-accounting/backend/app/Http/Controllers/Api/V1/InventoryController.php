<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function items(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => InventoryItem::query()->where('book_id', $book->id)->orderBy('sku')->get(),
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'valuation_method' => ['required', 'in:fifo,weighted_average'],
            'inventory_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'cogs_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $item = InventoryItem::query()->create([
            ...$data,
            'company_id' => $company->id,
            'book_id' => $book->id,
            'quantity_on_hand' => 0,
            'unit_cost' => 0,
            'total_value' => 0,
            'status' => 'active',
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $item = InventoryItem::query()->where('book_id', $book->id)->findOrFail($id);
        $payload = $request->validate([
            'movement_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'credit_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'reference' => ['nullable', 'string'],
        ]);

        $movement = $this->inventory->receive($book, $request->user(), $item, $payload);

        return response()->json(['data' => $movement], 201);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $item = InventoryItem::query()->where('book_id', $book->id)->findOrFail($id);
        $payload = $request->validate([
            'movement_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string'],
        ]);

        $movement = $this->inventory->issue($book, $request->user(), $item, $payload);

        return response()->json(['data' => $movement], 201);
    }

    public function movements(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => InventoryMovement::query()->where('book_id', $book->id)->orderByDesc('movement_date')->limit(100)->get(),
        ]);
    }

    public function valuation(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->inventory->valuation((int) $company->id, (int) $book->id)
        );
    }
}
