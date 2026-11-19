<?php

namespace App\Application\Services;

use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ErpBridgeService
{
    /**
     * @return array{token:string,user:array<string,mixed>,companies:list<array<string,mixed>>,embed:array<string,mixed>}
     */
    public function exchange(string $payloadB64, string $signature, ?string $backUrl = null): array
    {
        if (! config('erp_bridge.enabled')) {
            throw ValidationException::withMessages(['bridge' => 'ERP bridge is disabled.']);
        }

        $secret = (string) config('erp_bridge.secret');
        if ($secret === '') {
            throw ValidationException::withMessages(['bridge' => 'ERP_BRIDGE_SECRET is not configured.']);
        }

        $expected = hash_hmac('sha256', $payloadB64, $secret);
        if (! hash_equals($expected, strtolower($signature)) && ! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages(['bridge' => 'Invalid bridge signature.']);
        }

        $padded = strtr($payloadB64, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($padded, true);
        if ($json === false) {
            throw ValidationException::withMessages(['bridge' => 'Invalid bridge payload encoding.']);
        }

        /** @var array<string,mixed>|null $claims */
        $claims = json_decode($json, true);
        if (! is_array($claims)) {
            throw ValidationException::withMessages(['bridge' => 'Invalid bridge payload.']);
        }

        $exp = (int) ($claims['exp'] ?? 0);
        $iat = (int) ($claims['iat'] ?? 0);
        $now = time();
        $maxAge = (int) config('erp_bridge.max_age_seconds', 180);
        if ($exp < $now || $iat > ($now + 30) || ($now - $iat) > $maxAge) {
            throw ValidationException::withMessages(['bridge' => 'Bridge token expired. Open Accounting again from ERP.']);
        }

        $slug = strtolower(trim((string) ($claims['company_slug'] ?? '')));
        $companyName = trim((string) ($claims['company_name'] ?? ''));
        $erpCompanyId = (int) ($claims['erp_company_id'] ?? 0);
        if ($slug === '' || $erpCompanyId <= 0) {
            throw ValidationException::withMessages(['bridge' => 'Company context missing from ERP bridge.']);
        }

        $username = trim((string) ($claims['username'] ?? ''));
        $fullName = trim((string) ($claims['full_name'] ?? $username));
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $local = preg_replace('/[^a-z0-9._+-]/i', '', $username !== '' ? $username : 'erpuser') ?: 'erpuser';
            $email = strtolower($local).'@erp.ultitech.local';
        }
        if ($fullName === '') {
            $fullName = $email;
        }

        $payload = DB::transaction(function () use ($claims, $slug, $companyName, $erpCompanyId, $email, $fullName, $backUrl) {
            $company = $this->ensureCompany($slug, $companyName !== '' ? $companyName : ucfirst($slug), $erpCompanyId, $claims);
            $user = $this->ensureUser($email, $fullName, $claims);
            $this->ensureAccess($user, $company);

            $user->forceFill(['last_login_at' => now()])->save();
            $token = $user->createToken('erp-bridge')->plainTextToken;

            $companies = $user->companyAccess()
                ->where('status', 'active')
                ->with(['company', 'role.permissions'])
                ->get()
                ->map(fn ($access) => [
                    'id' => $access->company->id,
                    'uuid' => $access->company->uuid,
                    'code' => $access->company->code,
                    'legal_name' => $access->company->legal_name,
                    'role' => $access->role->code,
                    'permissions' => $access->role->permissions->pluck('code'),
                    'is_default' => (bool) $access->is_default || (int) $access->company_id === (int) $company->id,
                ])
                ->values()
                ->all();

            return [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'companies' => $companies,
                'company_id' => $company->id,
                'company' => $company,
                'auth_user' => $user,
                'embed' => [
                    'mode' => 'erp',
                    'back_url' => $backUrl ?: (string) ($claims['back_url'] ?? ''),
                    'erp_company_id' => $erpCompanyId,
                    'erp_company_slug' => $slug,
                    'erp_company_name' => $company->legal_name,
                    'erp_db' => (string) ($claims['erp_db'] ?? ''),
                    'company_logo_url' => trim((string) ($claims['company_logo_url'] ?? '')),
                    'locked_company' => true,
                ],
            ];
        });

        /** @var Company $company */
        $company = $payload['company'];
        /** @var User $authUser */
        $authUser = $payload['auth_user'];
        unset($payload['company'], $payload['auth_user']);

        // Outside the SSO transaction: seed CoA/banking and optionally pull ERP masters.
        app(CompanyBootstrapService::class)->hydrateForErpCompany($company, $authUser);

        $book = AccountingBook::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_primary', true)
            ->first()
            ?? AccountingBook::withoutGlobalScopes()->where('company_id', $company->id)->first();

        $payload['book_id'] = $book?->id;

        $bankRows = $book
            ? BankAccount::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('book_id', $book->id)
                ->where('status', 'active')
                ->with('glAccount:id,code,name')
                ->orderBy('code')
                ->get()
            : collect();
        $cashRows = $book
            ? CashAccount::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('book_id', $book->id)
                ->where('status', 'active')
                ->with('glAccount:id,code,name')
                ->orderBy('code')
                ->get()
            : collect();

        // Prefetch with live ERP balances so Cash & Bank is not blank until proxy refetch.
        $bankPrefetch = $bankRows->values()->all();
        $cashPrefetch = $cashRows->values()->all();
        if ($book && config('erp.enabled')) {
            try {
                $enrichment = app(BankingErpEnrichmentService::class);
                $bankPrefetch = $enrichment->presentBankAccounts($book, $bankRows);
                $cashPrefetch = $enrichment->presentCashAccounts($book, $cashRows);
            } catch (\Throwable $e) {
                // Keep raw desks if enrichment fails; UI can still refetch.
            }
        }

        $payload['prefetch'] = [
            'bank_accounts' => $bankPrefetch,
            'cash_accounts' => $cashPrefetch,
            'accounts' => $book
                ? Account::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('book_id', $book->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereIn('path', ['template', 'user', 'banking'])
                            ->orWhereIn('code', \App\Application\Services\CompanyBootstrapService::STANDARD_CODES);
                    })
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'normal_balance', 'is_postable', 'is_system', 'status', 'account_type_id', 'book_id', 'company_id', 'path'])
                    ->values()
                    ->all()
                : [],
        ];

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function ensureCompany(string $slug, string $legalName, int $erpCompanyId, array $claims): Company
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $slug) ?: 'ERP');

        $company = Company::query()->where('code', $code)->first();
        if (! $company) {
            $company = Company::query()->create([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'legal_name' => $legalName,
                'trading_name' => $legalName,
                'country_code' => 'TZ',
                'base_currency_code' => 'TZS',
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
            ]);
        } else {
            $company->forceFill([
                'legal_name' => $legalName,
                'trading_name' => $legalName,
                'status' => 'active',
            ])->save();
        }

        $settings = CompanySetting::query()->firstOrNew(['company_id' => $company->id]);
        $json = is_array($settings->settings_json) ? $settings->settings_json : [];
        $logoUrl = trim((string) ($claims['company_logo_url'] ?? ''));
        $json['erp_bridge'] = [
            'erp_company_id' => $erpCompanyId,
            'erp_company_slug' => $slug,
            'erp_db' => (string) ($claims['erp_db'] ?? ''),
            'company_logo_url' => $logoUrl,
            'linked_at' => now()->toIso8601String(),
            'source' => 'erp_bridge',
        ];
        if ($logoUrl !== '') {
            $json['company_logo_url'] = $logoUrl;
        }
        $json['date_format'] = $json['date_format'] ?? 'Y-m-d';
        $json['number_format'] = $json['number_format'] ?? 'en_US';
        $settings->settings_json = $json;
        $settings->save();

        $this->ensureBookScaffold($company);

        return $company->fresh();
    }

    private function ensureBookScaffold(Company $company): void
    {
        $framework = Framework::query()->where('code', 'IFRS')->first();
        $jurisdiction = Jurisdiction::query()->where('code', 'TZ')->first();
        if (! $framework || ! $jurisdiction) {
            return;
        }

        $book = AccountingBook::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'IFRS-TZ'],
            [
                'name' => 'Primary IFRS Tanzania Book',
                'framework_id' => $framework->id,
                'jurisdiction_id' => $jurisdiction->id,
                'functional_currency_code' => $company->base_currency_code ?: 'TZS',
                'is_primary' => true,
                'status' => 'active',
            ]
        );

        PostingSequence::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'book_id' => $book->id, 'sequence_key' => 'journal'],
            ['prefix' => 'JE', 'next_number' => 1, 'padding' => 6]
        );

        $yearLabel = 'FY'.now()->year;
        $year = FiscalYear::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => $yearLabel],
            [
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'open',
            ]
        );

        if ($year->periods()->count() === 0) {
            $start = Carbon::parse($year->start_date);
            for ($i = 1; $i <= 12; $i++) {
                FiscalPeriod::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'fiscal_year_id' => $year->id,
                    'period_number' => $i,
                    'name' => $start->format('M Y'),
                    'start_date' => $start->copy()->startOfMonth()->toDateString(),
                    'end_date' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                ]);
                $start->addMonth();
            }
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function ensureUser(string $email, string $fullName, array $claims): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $user->forceFill([
                'name' => $fullName,
                'is_active' => true,
            ])->save();

            return $user;
        }

        return User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $fullName,
            'email' => $email,
            'password' => Str::password(32),
            'is_active' => true,
        ]);
    }

    private function ensureAccess(User $user, Company $company): void
    {
        $roleCode = (string) config('erp_bridge.default_role_code', 'admin');
        $role = Role::query()->whereNull('company_id')->where('code', $roleCode)->first()
            ?? Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();

        UserCompanyAccess::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id],
            ['role_id' => $role->id, 'is_default' => true, 'status' => 'active']
        );
    }
}
