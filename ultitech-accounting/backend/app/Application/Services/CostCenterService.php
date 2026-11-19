<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\CostCenter;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Collection;

class CostCenterService
{
    public function create(AccountingBook $book, array $payload): CostCenter
    {
        return CostCenter::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'code' => $payload['code'],
            'name' => $payload['name'],
            'status' => 'active',
        ]);
    }

    public function profitAndLoss(AccountingBook $book, string $from, string $to): array
    {
        $centers = CostCenter::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();

        $movements = JournalLine::withoutGlobalScopes()
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->join('acc_account_types', 'acc_account_types.id', '=', 'acc_accounts.account_type_id')
            ->where('acc_journal_lines.company_id', $book->company_id)
            ->where('acc_journal_lines.book_id', $book->id)
            ->whereIn('acc_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('acc_journal_entries.journal_date', '>=', $from)
            ->whereDate('acc_journal_entries.journal_date', '<=', $to)
            ->whereIn('acc_account_types.code', ['revenue', 'expense'])
            ->whereNotNull('acc_journal_lines.dimensions_json')
            ->select(
                'acc_journal_lines.dimensions_json',
                'acc_account_types.code as type_code',
                'acc_journal_lines.functional_debit',
                'acc_journal_lines.functional_credit'
            )
            ->get();

        $byCenter = [];
        foreach ($centers as $center) {
            $byCenter[$center->id] = [
                'cost_center_id' => $center->id,
                'code' => $center->code,
                'name' => $center->name,
                'revenue' => '0.0000',
                'expense' => '0.0000',
                'net' => '0.0000',
            ];
        }

        foreach ($movements as $row) {
            $dims = is_string($row->dimensions_json)
                ? json_decode($row->dimensions_json, true)
                : (array) $row->dimensions_json;
            $ccId = (int) ($dims['cost_center_id'] ?? 0);
            if (! isset($byCenter[$ccId])) {
                continue;
            }

            if ($row->type_code === 'revenue') {
                $amt = Money::normalize(bcsub($row->functional_credit, $row->functional_debit, 4));
                $byCenter[$ccId]['revenue'] = Money::add($byCenter[$ccId]['revenue'], $amt);
            } else {
                $amt = Money::normalize(bcsub($row->functional_debit, $row->functional_credit, 4));
                $byCenter[$ccId]['expense'] = Money::add($byCenter[$ccId]['expense'], $amt);
            }
        }

        foreach ($byCenter as &$row) {
            $row['net'] = Money::normalize(bcsub($row['revenue'], $row['expense'], 4));
        }

        return array_values($byCenter);
    }
}
