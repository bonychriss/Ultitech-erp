<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BankingErpEnrichmentService;
use App\Application\Services\BankingErpWalletWriter;
use App\Application\Services\BankingGlAccountService;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankingController extends Controller
{
    public function __construct(
        private readonly BankingGlAccountService $glAccounts,
        private readonly BankingErpEnrichmentService $erpEnrichment,
        private readonly BankingErpWalletWriter $erpWriter,
    ) {}

    public function bankAccounts(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $user = $this->authUser($request);
        if ($user) {
            $this->erpEnrichment->refreshWallets($book, $user);
        }

        $rows = BankAccount::query()
            ->where('book_id', $book->id)
            ->with('glAccount')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $this->erpEnrichment->presentBankAccounts($book, $rows),
        ]);
    }

    public function storeBankAccount(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'currency_code' => ['required', 'string', 'size:3'],
            'gl_account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'account_type' => ['nullable', 'in:bank,mobile_money,cash'],
            'status' => ['nullable', 'in:active,inactive'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date_format:Y-m-d'],
            'institution_slug' => ['nullable', 'string', 'max:80'],
            'logo_base64' => ['nullable', 'string'],
            'logo_file_name' => ['nullable', 'string', 'max:120'],
            'clear_logo' => ['nullable', 'boolean'],
        ]);

        $exists = BankAccount::query()
            ->where('book_id', $book->id)
            ->where('code', $data['code'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A bank account with this code already exists.'], 422);
        }

        try {
            $logo = $this->erpWriter->resolveLogoPath(
                $data['institution_slug'] ?? null,
                $data['logo_base64'] ?? null,
                $data['logo_file_name'] ?? null,
                (bool) ($data['clear_logo'] ?? false),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $walletType = $data['account_type'] ?? 'bank';
        if ($walletType === 'cash') {
            $walletType = 'bank';
        }
        $status = $data['status'] ?? 'active';
        $erpType = ($walletType === 'mobile_money') ? 'mobile' : 'bank';

        $gl = $this->glAccounts->resolveOrCreate(
            $company,
            $book,
            $walletType,
            (string) $data['code'],
            (string) $data['name'],
            isset($data['gl_account_id']) ? (int) $data['gl_account_id'] : null,
        );

        $account = BankAccount::query()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'bank_name' => $data['bank_name'] ?? $data['name'],
            'account_number' => $data['account_number'] ?? '',
            'currency_code' => strtoupper($data['currency_code']),
            'gl_account_id' => $gl->id,
            'account_type' => $data['account_type'] ?? 'bank',
            'status' => $status,
        ]);

        $erpCompanyId = (int) data_get($request->attributes->get('erp_embed'), 'erp_company_id', 0);
        $erpId = $this->erpWriter->createClassicWallet((int) $company->id, [
            'name' => (string) $data['name'],
            'type' => $erpType,
            'currency' => strtoupper((string) $data['currency_code']),
            'status' => $status,
            'opening_balance' => (float) ($data['opening_balance'] ?? 0),
            'opening_date' => $data['opening_date'] ?? null,
            'account_image' => $logo['changed'] ? $logo['path'] : null,
            'company_id' => $erpCompanyId > 0 ? $erpCompanyId : null,
        ]);
        if ($erpId) {
            $this->erpWriter->mapLocalWallet(
                (int) $company->id,
                'financial_bank',
                $erpId,
                (int) $account->id,
                ['fa_code' => (string) $data['code']]
            );
            // Keep local code aligned with classic FA-{id} pattern used by sync.
            $account->forceFill(['code' => 'FA-'.$erpId])->save();
        }

        $slug = trim((string) ($data['institution_slug'] ?? ''));
        if ($slug === '' || str_starts_with($slug, 'custom-')) {
            $this->erpWriter->rememberCustomInstitution(
                (int) $company->id,
                (string) ($data['bank_name'] ?? $data['name']),
                $erpType,
                $logo['changed'] ? ($logo['path'] ?? null) : null,
                $erpCompanyId > 0 ? $erpCompanyId : null,
            );
        }

        $presented = $this->erpEnrichment->presentBankAccounts($book, collect([$account->fresh()->load('glAccount')]));

        return response()->json(['data' => $presented[0] ?? $account->load('glAccount')], 201);
    }

    public function updateBankAccount(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $account = BankAccount::query()
            ->where('book_id', $book->id)
            ->whereKey($id)
            ->first();
        if (! $account) {
            return response()->json(['message' => 'Bank account not found.'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'currency_code' => ['required', 'string', 'size:3'],
            'account_type' => ['nullable', 'in:bank,mobile_money'],
            'status' => ['nullable', 'in:active,inactive'],
            'institution_slug' => ['nullable', 'string', 'max:80'],
            'logo_base64' => ['nullable', 'string'],
            'logo_file_name' => ['nullable', 'string', 'max:120'],
            'clear_logo' => ['nullable', 'boolean'],
        ]);

        try {
            $logo = $this->erpWriter->resolveLogoPath(
                $data['institution_slug'] ?? null,
                $data['logo_base64'] ?? null,
                $data['logo_file_name'] ?? null,
                (bool) ($data['clear_logo'] ?? false),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account->forceFill([
            'name' => trim((string) $data['name']),
            'bank_name' => trim((string) ($data['bank_name'] ?? $data['name'])),
            'account_number' => trim((string) ($data['account_number'] ?? '')),
            'currency_code' => strtoupper((string) $data['currency_code']),
            'account_type' => $data['account_type'] ?? $account->account_type,
            'status' => $data['status'] ?? $account->status,
        ])->save();

        $erpType = ($account->account_type === 'mobile_money') ? 'mobile' : 'bank';
        $sync = [
            'name' => (string) $account->name,
            'status' => (string) $account->status,
            'currency' => (string) $account->currency_code,
            'type' => $erpType,
            'fallback_code' => (string) ($account->code ?? ''),
        ];
        if ($logo['changed']) {
            $sync['account_image'] = $logo['path'];
        }
        $this->erpWriter->syncMappedWallet(
            (int) $book->company_id,
            'financial_bank',
            (int) $account->id,
            $sync
        );

        $fresh = BankAccount::query()->whereKey($account->id)->with('glAccount')->get();
        $presented = $this->erpEnrichment->presentBankAccounts($book, $fresh);

        return response()->json(['data' => $presented[0] ?? $account->fresh()->load('glAccount')]);
    }

    public function cashAccounts(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $user = $this->authUser($request);
        if ($user) {
            $this->erpEnrichment->refreshWallets($book, $user);
        }

        $rows = CashAccount::query()
            ->where('book_id', $book->id)
            ->with('glAccount')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $this->erpEnrichment->presentCashAccounts($book, $rows),
        ]);
    }

    public function storeCashAccount(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'gl_account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date_format:Y-m-d'],
            'institution_slug' => ['nullable', 'string', 'max:80'],
            'logo_base64' => ['nullable', 'string'],
            'logo_file_name' => ['nullable', 'string', 'max:120'],
            'clear_logo' => ['nullable', 'boolean'],
        ]);

        $exists = CashAccount::query()
            ->where('book_id', $book->id)
            ->where('code', $data['code'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A cash account with this code already exists.'], 422);
        }

        try {
            $logo = $this->erpWriter->resolveLogoPath(
                $data['institution_slug'] ?? null,
                $data['logo_base64'] ?? null,
                $data['logo_file_name'] ?? null,
                (bool) ($data['clear_logo'] ?? false),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $data['status'] ?? 'active';

        $gl = $this->glAccounts->resolveOrCreate(
            $company,
            $book,
            'cash',
            (string) $data['code'],
            (string) $data['name'],
            isset($data['gl_account_id']) ? (int) $data['gl_account_id'] : null,
        );

        $account = CashAccount::query()->create([
            'company_id' => $company->id,
            'book_id' => $book->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'currency_code' => strtoupper($data['currency_code']),
            'gl_account_id' => $gl->id,
            'status' => $status,
        ]);

        $erpCompanyId = (int) data_get($request->attributes->get('erp_embed'), 'erp_company_id', 0);
        $erpId = $this->erpWriter->createClassicWallet((int) $company->id, [
            'name' => (string) $data['name'],
            'type' => 'cash',
            'currency' => strtoupper((string) $data['currency_code']),
            'status' => $status,
            'opening_balance' => (float) ($data['opening_balance'] ?? 0),
            'opening_date' => $data['opening_date'] ?? null,
            'account_image' => $logo['changed'] ? $logo['path'] : null,
            'company_id' => $erpCompanyId > 0 ? $erpCompanyId : null,
        ]);
        if ($erpId) {
            $this->erpWriter->mapLocalWallet(
                (int) $company->id,
                'financial_cash',
                $erpId,
                (int) $account->id,
                ['fa_code' => (string) $data['code']]
            );
            $account->forceFill(['code' => 'FA-'.$erpId])->save();
        }

        $presented = $this->erpEnrichment->presentCashAccounts($book, collect([$account->fresh()->load('glAccount')]));

        return response()->json(['data' => $presented[0] ?? $account->load('glAccount')], 201);
    }

    public function updateCashAccount(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $account = CashAccount::query()
            ->where('book_id', $book->id)
            ->whereKey($id)
            ->first();
        if (! $account) {
            return response()->json(['message' => 'Cash account not found.'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'status' => ['nullable', 'in:active,inactive'],
            'institution_slug' => ['nullable', 'string', 'max:80'],
            'logo_base64' => ['nullable', 'string'],
            'logo_file_name' => ['nullable', 'string', 'max:120'],
            'clear_logo' => ['nullable', 'boolean'],
        ]);

        try {
            $logo = $this->erpWriter->resolveLogoPath(
                $data['institution_slug'] ?? null,
                $data['logo_base64'] ?? null,
                $data['logo_file_name'] ?? null,
                (bool) ($data['clear_logo'] ?? false),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account->forceFill([
            'name' => trim((string) $data['name']),
            'currency_code' => strtoupper((string) $data['currency_code']),
            'status' => $data['status'] ?? $account->status,
        ])->save();

        $sync = [
            'name' => (string) $account->name,
            'status' => (string) $account->status,
            'currency' => (string) $account->currency_code,
            'type' => 'cash',
            'fallback_code' => (string) ($account->code ?? ''),
        ];
        if ($logo['changed']) {
            $sync['account_image'] = $logo['path'];
        }
        $this->erpWriter->syncMappedWallet(
            (int) $book->company_id,
            'financial_cash',
            (int) $account->id,
            $sync
        );

        $fresh = CashAccount::query()->whereKey($account->id)->with('glAccount')->get();
        $presented = $this->erpEnrichment->presentCashAccounts($book, $fresh);

        return response()->json(['data' => $presented[0] ?? $account->fresh()->load('glAccount')]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $book = $request->attributes->get('book');
        $bankAccountId = $request->query('bank_account_id');
        $filterBankId = $bankAccountId !== null && $bankAccountId !== ''
            ? (int) $bankAccountId
            : null;

        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min(1000, $limit));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

        $banks = BankAccount::query()
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();
        $cash = CashAccount::query()
            ->where('book_id', $book->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();

        $erpRows = $this->erpEnrichment->presentMovements(
            $book,
            $banks,
            $cash,
            $filterBankId,
            $limit,
            $dateFrom,
            $dateTo
        );
        if ($erpRows !== []) {
            return response()->json(['data' => $erpRows]);
        }

        // Fallback: local New Accounting bank transactions (if any).
        $query = BankTransaction::query()
            ->where('company_id', $company->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
        if ($filterBankId) {
            $query->where('bank_account_id', $filterBankId);
        }
        if ($dateFrom) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }

        return response()->json(['data' => $query->limit($limit)->get()]);
    }

    private function authUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        $attr = $request->attributes->get('auth_user');

        return $attr instanceof User ? $attr : null;
    }
}
