<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AccountBalanceService;
use App\Application\Services\FinancialReportingService;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly FinancialReportingService $reports,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $asOf = $request->query('as_of');

        $rows = $this->balances->trialBalance((int) $company->id, (int) $book->id, $asOf);
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('book_id', $book->id)
            ->whereIn('id', $rows->pluck('account_id'))
            ->get()
            ->keyBy('id');

        $data = $rows->map(function (array $row) use ($accounts) {
            $account = $accounts->get($row['account_id']);

            return [
                'account_id' => $row['account_id'],
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ];
        });

        $totalDebit = $data->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $totalCredit = $data->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');

        return response()->json([
            'data' => $data,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balanced' => Money::equals($totalDebit, $totalCredit),
            ],
        ]);
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'date'],
            'compare_to' => ['nullable', 'date', 'after_or_equal:compare_from'],
        ]);

        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->reports->profitAndLoss(
                (int) $company->id,
                (int) $book->id,
                $data['from'],
                $data['to'],
                $data['compare_from'] ?? null,
                $data['compare_to'] ?? null,
            )
        );
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['required', 'date'],
            'compare_as_of' => ['nullable', 'date'],
        ]);

        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->reports->balanceSheet(
                (int) $company->id,
                (int) $book->id,
                $data['as_of'],
                $data['compare_as_of'] ?? null,
            )
        );
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->reports->cashFlow((int) $company->id, (int) $book->id, $data['from'], $data['to'])
        );
    }

    public function equity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->reports->changesInEquity((int) $company->id, (int) $book->id, $data['from'], $data['to'])
        );
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer'],
        ]);

        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        return response()->json(
            $this->reports->generalLedger(
                (int) $company->id,
                (int) $book->id,
                isset($data['account_id']) ? (int) $data['account_id'] : null,
                $data['from'],
                $data['to'],
            )
        );
    }
}
