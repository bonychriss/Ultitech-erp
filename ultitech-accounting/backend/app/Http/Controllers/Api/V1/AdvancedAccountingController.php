<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BudgetService;
use App\Application\Services\ConsolidationService;
use App\Application\Services\CostCenterService;
use App\Application\Services\FxRevaluationService;
use App\Application\Services\ImpairmentService;
use App\Application\Services\LeaseService;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\ConsolidationGroup;
use App\Models\CostCenter;
use App\Models\FxRevaluation;
use App\Models\ImpairmentTest;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedAccountingController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly CostCenterService $costCenters,
        private readonly FxRevaluationService $fx,
        private readonly ConsolidationService $consolidation,
        private readonly LeaseService $leases,
        private readonly ImpairmentService $impairment,
    ) {}

    public function costCenters(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => CostCenter::query()->where('book_id', $book->id)->orderBy('code')->get(),
        ]);
    }

    public function storeCostCenter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $center = $this->costCenters->create($request->attributes->get('book'), $data);

        return response()->json(['data' => $center], 201);
    }

    public function costCenterPl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $this->costCenters->profitAndLoss(
                $request->attributes->get('book'),
                $data['from'],
                $data['to']
            ),
        ]);
    }

    public function budgets(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => Budget::query()->where('book_id', $book->id)->with('lines')->orderByDesc('id')->get(),
        ]);
    }

    public function storeBudget(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'fiscal_year_id' => ['required', 'integer', 'exists:acc_fiscal_years,id'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id'],
            'lines.*.account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:acc_cost_centers,id'],
            'lines.*.amount' => ['required', 'numeric'],
        ]);

        $budget = $this->budgets->create($request->attributes->get('book'), $request->user(), $payload);

        return response()->json(['data' => $budget], 201);
    }

    public function approveBudget(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $budget = Budget::query()->where('book_id', $book->id)->findOrFail($id);

        return response()->json(['data' => $this->budgets->approve($budget)]);
    }

    public function budgetVersusActual(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $budget = Budget::query()->where('book_id', $book->id)->findOrFail($id);

        return response()->json(['data' => $this->budgets->versusActual($budget)]);
    }

    public function fxRevaluations(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => FxRevaluation::query()->where('book_id', $book->id)->with('lines')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function runFxRevaluation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id'],
        ]);

        $reval = $this->fx->run(
            $request->attributes->get('book'),
            $request->user(),
            (int) $data['period_id']
        );

        return response()->json(['data' => $reval], 201);
    }

    public function consolidationGroups(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => ConsolidationGroup::query()
                ->where('company_id', $company->id)
                ->with('members')
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function storeConsolidationGroup(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'parent_book_id' => ['required', 'integer', 'exists:acc_accounting_books,id'],
            'reporting_currency' => ['required', 'string', 'size:3'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.member_company_id' => ['required', 'integer', 'exists:acc_companies,id'],
            'members.*.member_book_id' => ['required', 'integer', 'exists:acc_accounting_books,id'],
            'members.*.ownership_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'members.*.is_parent' => ['nullable', 'boolean'],
        ]);

        $group = $this->consolidation->createGroup((int) $company->id, $payload);

        return response()->json(['data' => $group], 201);
    }

    public function runConsolidation(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $group = ConsolidationGroup::query()->where('company_id', $company->id)->findOrFail($id);
        $data = $request->validate(['as_of_date' => ['required', 'date']]);

        $run = $this->consolidation->run($group, $request->user(), $data['as_of_date']);

        return response()->json(['data' => $run], 201);
    }

    public function leases(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => Lease::query()->where('book_id', $book->id)->with('schedules')->orderByDesc('id')->get(),
        ]);
    }

    public function storeLease(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'lease_number' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'commencement_date' => ['required', 'date'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'payment_amount' => ['required', 'numeric', 'gt:0'],
            'annual_rate_percent' => ['required', 'numeric', 'min:0'],
            'rou_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'liability_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'interest_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'depreciation_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $lease = $this->leases->create($request->attributes->get('book'), $request->user(), $payload);

        return response()->json(['data' => $lease], 201);
    }

    public function postLeasePeriod(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $lease = Lease::query()->where('book_id', $book->id)->findOrFail($id);
        $data = $request->validate([
            'period_no' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
        ]);

        $schedule = $this->leases->postPeriod(
            $lease,
            $book,
            $request->user(),
            (int) $data['period_no'],
            (int) $data['cash_account_id']
        );

        return response()->json(['data' => $schedule]);
    }

    public function impairments(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => ImpairmentTest::query()->where('book_id', $book->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function impairAsset(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:acc_fixed_assets,id'],
            'test_date' => ['required', 'date'],
            'recoverable_amount' => ['required', 'numeric', 'min:0'],
            'impairment_expense_account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $test = $this->impairment->impairFixedAsset(
            $request->attributes->get('book'),
            $request->user(),
            $payload
        );

        return response()->json(['data' => $test], 201);
    }
}
