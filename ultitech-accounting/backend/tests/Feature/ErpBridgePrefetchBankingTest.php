<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Company;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpBridgePrefetchBankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_bridge_prefetch_includes_bank_and_cash_accounts(): void
    {
        $now = time();
        $claims = [
            'erp_user_id' => 9,
            'username' => 'bank.user',
            'full_name' => 'Bank User',
            'email' => 'bank.user@example.com',
            'role' => 'finance',
            'erp_company_id' => 99,
            'company_slug' => 'ultimate',
            'company_name' => 'Ultimate Trading',
            'erp_db' => 'new_trading_voucher-35313030c7e2',
            'iat' => $now,
            'exp' => $now + 120,
            'nonce' => 'bank1',
        ];

        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payload, 'test-bridge-secret');

        $response = $this->postJson('/api/v1/auth/erp-bridge', [
            'payload' => $payload,
            'sig' => $sig,
        ])->assertOk();

        $company = Company::query()->where('code', 'ULTIMATE')->first();
        $this->assertNotNull($company);

        $this->assertGreaterThanOrEqual(1, BankAccount::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertGreaterThanOrEqual(1, CashAccount::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $bankCodes = collect($response->json('prefetch.bank_accounts'))->pluck('code')->all();
        $cashCodes = collect($response->json('prefetch.cash_accounts'))->pluck('code')->all();
        $this->assertNotEmpty($bankCodes);
        $this->assertNotEmpty($cashCodes);
        // Bootstrap desks or Balances wallets (financial_accounts → FA-{id}).
        $this->assertTrue(
            in_array('MAIN-BANK', $bankCodes, true)
            || collect($bankCodes)->contains(fn ($c) => str_starts_with((string) $c, 'FA-'))
        );
        $this->assertTrue(
            in_array('PETTY', $cashCodes, true)
            || collect($cashCodes)->contains(fn ($c) => str_starts_with((string) $c, 'FA-'))
        );

        $accounts = $response->json('prefetch.accounts');
        $this->assertIsArray($accounts);
        $this->assertGreaterThanOrEqual(10, count($accounts));
        $this->assertNotEmpty($accounts[0]['code'] ?? null);
    }
}
