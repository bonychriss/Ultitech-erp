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
        if (is_string($asOf) && trim($asOf) === '') {
            $asOf = null;
        }
        $includeZeros = filter_var($request->query('include_zeros', false), FILTER_VALIDATE_BOOLEAN);

        $rows = $this->balances->trialBalance((int) $company->id, (int) $book->id, $asOf)
            ->keyBy('account_id');

        $accountsQuery = Account::withoutGlobalScopes()
            ->with('accountType')
            ->where('company_id', $company->id)
            ->where('book_id', $book->id);

        if ($includeZeros) {
            $accountsQuery->where('is_postable', true)->where('status', 'active');
        } else {
            $accountsQuery->whereIn('id', $rows->keys());
        }

        $accounts = $accountsQuery
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        // If activity exists on an account missing from CoA filter, still include it.
        foreach ($rows->keys() as $accountId) {
            if (! $accounts->has($accountId)) {
                $extra = Account::withoutGlobalScopes()
                    ->with('accountType')
                    ->where('company_id', $company->id)
                    ->where('book_id', $book->id)
                    ->where('id', $accountId)
                    ->first();
                if ($extra) {
                    $accounts->put($accountId, $extra);
                }
            }
        }

        $data = $accounts->map(function ($account) use ($rows) {
            $row = $rows->get($account->id) ?? [
                'account_id' => (int) $account->id,
                'opening_debit' => '0.0000',
                'opening_credit' => '0.0000',
                'movement_debit' => '0.0000',
                'movement_credit' => '0.0000',
                'debit' => '0.0000',
                'credit' => '0.0000',
            ];

            // Classical TB: one side only (net debit or net credit).
            $net = Money::sub($row['debit'], $row['credit']);
            if (Money::compare($net, '0') < 0) {
                $debit = '0.0000';
                $credit = Money::sub('0', $net);
            } else {
                $debit = $net;
                $credit = '0.0000';
            }

            $openingNet = Money::sub($row['opening_debit'], $row['opening_credit']);
            if (Money::compare($openingNet, '0') < 0) {
                $openingDebit = '0.0000';
                $openingCredit = Money::sub('0', $openingNet);
            } else {
                $openingDebit = $openingNet;
                $openingCredit = '0.0000';
            }

            return [
                'account_id' => (int) $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->accountType?->name,
                'account_type_code' => $account->accountType?->code,
                'fs_classification' => $account->accountType?->fs_classification,
                'normal_balance' => $account->normal_balance,
                'currency_code' => $account->currency_code ?: null,
                'is_postable' => (bool) $account->is_postable,
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'movement_debit' => Money::normalize($row['movement_debit'] ?? 0),
                'movement_credit' => Money::normalize($row['movement_credit'] ?? 0),
                'debit' => $debit,
                'credit' => $credit,
            ];
        })
            ->when(
                ! $includeZeros,
                fn ($collection) => $collection->filter(
                    fn (array $row) => ! Money::isZero($row['debit']) || ! Money::isZero($row['credit'])
                )
            )
            ->sortBy(fn (array $row) => (string) ($row['account_code'] ?? ''))
            ->values();

        $totalDebit = $data->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $totalCredit = $data->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');
        $difference = Money::normalize(bcsub($totalDebit, $totalCredit, 4));

        return response()->json([
            'as_of' => $asOf,
            'currency_code' => $book->functional_currency_code ?? $book->currency_code ?? 'TZS',
            'book' => [
                'id' => $book->id,
                'name' => $book->name ?? null,
            ],
            'data' => $data,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'difference' => $difference,
                'balanced' => Money::isZero($difference),
                'accounts' => $data->count(),
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
            'columns' => ['nullable', 'in:total,quarter,month'],
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
                $data['columns'] ?? 'total',
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
