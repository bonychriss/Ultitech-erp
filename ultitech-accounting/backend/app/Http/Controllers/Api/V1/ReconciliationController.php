<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BankReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $service) {}

    public function statements(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => BankStatement::query()
                ->where('company_id', $company->id)
                ->with('lines')
                ->orderByDesc('statement_date')
                ->limit(50)
                ->get(),
        ]);
    }

    public function storeStatement(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:acc_bank_accounts,id'],
            'statement_number' => ['required', 'string', 'max:40'],
            'statement_date' => ['required', 'date'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'opening_balance' => ['nullable', 'numeric'],
            'closing_balance' => ['required', 'numeric'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_date' => ['required', 'date'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reference' => ['nullable', 'string'],
        ]);

        $book = $request->attributes->get('book');
        $bank = BankAccount::query()
            ->where('book_id', $book->id)
            ->findOrFail($payload['bank_account_id']);

        $statement = $this->service->createStatement($bank, $request->user(), $payload);

        return response()->json(['data' => $statement], 201);
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'statement_id' => ['required', 'integer'],
        ]);

        $book = $request->attributes->get('book');
        $bank = BankAccount::query()->where('book_id', $book->id)->findOrFail($payload['bank_account_id']);
        $statement = BankStatement::query()->where('bank_account_id', $bank->id)->findOrFail($payload['statement_id']);
        $recon = $this->service->startReconciliation($bank, $statement, $request->user());

        return response()->json(['data' => $recon], 201);
    }

    public function match(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'statement_line_id' => ['required', 'integer'],
            'bank_transaction_id' => ['required', 'integer'],
        ]);

        $recon = BankReconciliation::query()->findOrFail($id);
        $match = $this->service->match($recon, (int) $payload['statement_line_id'], (int) $payload['bank_transaction_id']);

        return response()->json(['data' => $match], 201);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $recon = BankReconciliation::query()->findOrFail($id);
        $completed = $this->service->complete($recon, $request->user());

        return response()->json(['data' => $completed]);
    }

    public function unmatched(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $bankAccountId = (int) $request->query('bank_account_id');

        return response()->json([
            'data' => $this->service->unmatchedTransactions((int) $company->id, $bankAccountId),
        ]);
    }
}
