<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\InventoryItem;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly AuditTrailService $audit,
    ) {}

    public function receive(AccountingBook $book, User $user, InventoryItem $item, array $payload): InventoryMovement
    {
        $qty = Money::normalize($payload['quantity']);
        $unitCost = Money::normalize($payload['unit_cost']);
        $total = Money::multiply($qty, $unitCost);
        $creditAccountId = (int) $payload['credit_account_id'];

        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be positive.']);
        }

        return DB::transaction(function () use ($book, $user, $item, $payload, $qty, $unitCost, $total, $creditAccountId) {
            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['movement_date'],
                'description' => 'Inventory receipt '.$item->sku,
                'reference' => $payload['reference'] ?? $item->sku,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'inventory_receipt',
                'source_id_ref' => $item->id.'|'.$payload['movement_date'].'|'.$qty,
                'source_hash' => 'inventory_receipt:'.$item->id.':'.md5($payload['movement_date'].$qty.$unitCost.($payload['reference'] ?? '')),
                'lines' => [
                    [
                        'account_id' => $item->inventory_account_id,
                        'debit' => $total,
                        'credit' => '0',
                        'description' => 'Stock in '.$item->name,
                    ],
                    [
                        'account_id' => $creditAccountId,
                        'debit' => '0',
                        'credit' => $total,
                        'description' => 'Inventory receipt clearing',
                    ],
                ],
            ], 'system');

            if ($item->valuation_method === 'fifo') {
                InventoryLayer::withoutGlobalScopes()->create([
                    'company_id' => $book->company_id,
                    'item_id' => $item->id,
                    'received_date' => $payload['movement_date'],
                    'quantity_remaining' => $qty,
                    'unit_cost' => $unitCost,
                ]);
            }

            $newQty = Money::add(Money::normalize($item->quantity_on_hand), $qty);
            $newValue = Money::add(Money::normalize($item->total_value), $total);
            $newUnit = bccomp($newQty, '0', 4) === 0 ? '0.0000' : Money::normalize(bcdiv($newValue, $newQty, 4));

            $item->update([
                'quantity_on_hand' => $newQty,
                'total_value' => $newValue,
                'unit_cost' => $newUnit,
            ]);

            $movement = InventoryMovement::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'item_id' => $item->id,
                'movement_type' => 'receipt',
                'movement_date' => $payload['movement_date'],
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'reference' => $payload['reference'] ?? null,
                'posted_journal_id' => $journal->id,
                'created_by' => $user->id,
            ]);

            $this->audit->record('inventory.receipt', 'inventory_item', $item->id, null, [
                'quantity' => $qty,
                'total' => $total,
            ]);

            return $movement;
        });
    }

    public function issue(AccountingBook $book, User $user, InventoryItem $item, array $payload): InventoryMovement
    {
        $qty = Money::normalize($payload['quantity']);
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be positive.']);
        }
        if (bccomp($qty, Money::normalize($item->quantity_on_hand), 4) === 1) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient quantity on hand.']);
        }

        return DB::transaction(function () use ($book, $user, $item, $payload, $qty) {
            $cogs = '0.0000';

            if ($item->valuation_method === 'fifo') {
                $remaining = $qty;
                $layers = InventoryLayer::withoutGlobalScopes()
                    ->where('item_id', $item->id)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($layers as $layer) {
                    if (bccomp($remaining, '0', 4) <= 0) {
                        break;
                    }
                    $take = bccomp($remaining, Money::normalize($layer->quantity_remaining), 4) === 1
                        ? Money::normalize($layer->quantity_remaining)
                        : $remaining;
                    $cogs = Money::add($cogs, Money::multiply($take, Money::normalize($layer->unit_cost)));
                    $layer->update([
                        'quantity_remaining' => Money::normalize(bcsub(Money::normalize($layer->quantity_remaining), $take, 4)),
                    ]);
                    $remaining = Money::normalize(bcsub($remaining, $take, 4));
                }
            } else {
                $cogs = Money::multiply($qty, Money::normalize($item->unit_cost));
            }

            $unitCost = Money::normalize(bcdiv($cogs, $qty, 4));

            $journal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $payload['movement_date'],
                'description' => 'Inventory issue '.$item->sku,
                'reference' => $payload['reference'] ?? $item->sku,
                'currency_code' => $book->functional_currency_code,
                'source_type' => 'inventory_issue',
                'source_id_ref' => $item->id.'|'.$payload['movement_date'].'|'.$qty,
                'source_hash' => 'inventory_issue:'.$item->id.':'.md5($payload['movement_date'].$qty.($payload['reference'] ?? '')),
                'lines' => [
                    [
                        'account_id' => $item->cogs_account_id,
                        'debit' => $cogs,
                        'credit' => '0',
                        'description' => 'COGS '.$item->name,
                    ],
                    [
                        'account_id' => $item->inventory_account_id,
                        'debit' => '0',
                        'credit' => $cogs,
                        'description' => 'Stock out '.$item->name,
                    ],
                ],
            ], 'system');

            $newQty = Money::normalize(bcsub(Money::normalize($item->quantity_on_hand), $qty, 4));
            $newValue = Money::normalize(bcsub(Money::normalize($item->total_value), $cogs, 4));
            $newUnit = bccomp($newQty, '0', 4) === 0 ? '0.0000' : Money::normalize(bcdiv($newValue, $newQty, 4));

            $item->update([
                'quantity_on_hand' => $newQty,
                'total_value' => $newValue,
                'unit_cost' => $item->valuation_method === 'weighted_average' ? $newUnit : $item->unit_cost,
            ]);

            $movement = InventoryMovement::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'item_id' => $item->id,
                'movement_type' => 'issue',
                'movement_date' => $payload['movement_date'],
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $cogs,
                'reference' => $payload['reference'] ?? null,
                'posted_journal_id' => $journal->id,
                'created_by' => $user->id,
            ]);

            $this->audit->record('inventory.issue', 'inventory_item', $item->id, null, [
                'quantity' => $qty,
                'cogs' => $cogs,
            ]);

            return $movement;
        });
    }

    public function valuation(int $companyId, int $bookId): array
    {
        $items = InventoryItem::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('status', 'active')
            ->orderBy('sku')
            ->get();

        $total = $items->reduce(fn ($c, $i) => Money::add($c, Money::normalize($i->total_value)), '0.0000');

        return [
            'data' => $items->map(fn ($i) => [
                'id' => $i->id,
                'sku' => $i->sku,
                'name' => $i->name,
                'valuation_method' => $i->valuation_method,
                'quantity_on_hand' => Money::normalize($i->quantity_on_hand),
                'unit_cost' => Money::normalize($i->unit_cost),
                'total_value' => Money::normalize($i->total_value),
            ])->values(),
            'totals' => ['inventory_value' => $total],
        ];
    }
}
