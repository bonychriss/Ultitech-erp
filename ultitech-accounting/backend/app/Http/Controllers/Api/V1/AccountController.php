<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\CompanyBootstrapService;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $scope = strtolower((string) $request->query('scope', 'working'));

        $query = Account::query()
            ->where('book_id', $book->id)
            ->orderBy('code');

        // Working CoA: standard template + user-created accounts only (hide ERP/FA clutter).
        if ($scope !== 'all') {
            $query->where(function ($q) {
                $q->whereIn('path', ['template', 'user', 'banking'])
                    ->orWhereIn('code', CompanyBootstrapService::STANDARD_CODES);
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'account_type_id' => ['required', 'integer', 'exists:acc_account_types,id'],
            'parent_id' => ['nullable', 'integer'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'is_postable' => ['boolean'],
            'allow_manual_entry' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);

        $data['code'] = $this->allocateNextCode(
            (int) $company->id,
            (int) $book->id,
            (int) $data['account_type_id'],
            isset($data['code']) ? trim((string) $data['code']) : null,
        );

        $account = Account::query()->create([
            ...$data,
            'company_id' => $company->id,
            'book_id' => $book->id,
            'is_system' => false,
            'status' => 'active',
            'level' => 1,
            'path' => 'user',
        ]);

        return response()->json(['data' => $account], 201);
    }

    /**
     * Pick a free code in the type band (1xxx…5xxx), scanning the full book CoA
     * so hidden ERP rows cannot collide with working-scope suggestions.
     */
    private function allocateNextCode(int $companyId, int $bookId, int $accountTypeId, ?string $preferred): string
    {
        $preferred = $preferred !== null && $preferred !== '' ? $preferred : null;
        if ($preferred !== null) {
            $taken = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->where('code', $preferred)
                ->exists();
            if (! $taken) {
                return $preferred;
            }
        }

        $typeCode = (string) AccountType::query()->where('id', $accountTypeId)->value('code');
        $series = match ($typeCode) {
            'asset' => '1',
            'liability' => '2',
            'equity' => '3',
            'revenue' => '4',
            'expense' => '5',
            default => '9',
        };

        $codes = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->pluck('code');

        $max = (int) ($series.'000');
        foreach ($codes as $code) {
            $code = (string) $code;
            if (preg_match('/^\d+$/', $code) === 1 && str_starts_with($code, $series)) {
                $max = max($max, (int) $code);
            }
        }

        $next = (string) ($max + 1);
        while (
            Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->where('code', $next)
                ->exists()
        ) {
            $next = (string) (((int) $next) + 1);
        }

        return $next;
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $account = Account::query()->where('book_id', $book->id)->findOrFail($id);

        if ($account->is_system) {
            // System accounts: name/description only — never type, postable, status, or code.
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:200'],
                'description' => ['nullable', 'string'],
            ]);

            if ($request->exists('is_postable')
                || $request->exists('allow_manual_entry')
                || $request->exists('status')
                || $request->exists('account_type_id')
                || $request->exists('normal_balance')
                || $request->exists('code')
                || $request->exists('parent_id')) {
                throw ValidationException::withMessages([
                    'account' => 'This is a system account. Only the display name can be changed.',
                ]);
            }

            $account->update($data);

            return response()->json(['data' => $account->fresh()]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_postable' => ['boolean'],
            'allow_manual_entry' => ['boolean'],
            'status' => ['in:active,inactive'],
        ]);

        $account->update($data);

        return response()->json(['data' => $account]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $account = Account::query()->where('book_id', $book->id)->findOrFail($id);

        if ($account->is_system) {
            throw ValidationException::withMessages([
                'account' => 'System accounts are locked and cannot be deleted.',
            ]);
        }

        $account->delete();

        return response()->json(['message' => 'Account deleted.']);
    }
}
