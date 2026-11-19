<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpBridgeSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_erp_bridge_creates_company_user_and_token(): void
    {
        $now = time();
        $claims = [
            'erp_user_id' => 42,
            'username' => 'finance.user',
            'full_name' => 'Finance User',
            'email' => 'finance.user@example.com',
            'role' => 'finance',
            'erp_company_id' => 7,
            'company_slug' => 'ultimate',
            'company_name' => 'Ultimate Trading',
            'erp_db' => 'new_trading_voucher-35313030c7e2',
            'back_url' => 'http://localhost/public_html/ultimate/accounting/',
            'iat' => $now,
            'exp' => $now + 120,
            'nonce' => 'abc123',
        ];

        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payload, 'test-bridge-secret');

        $this->postJson('/api/v1/auth/erp-bridge', [
            'payload' => $payload,
            'sig' => $sig,
            'back_url' => $claims['back_url'],
        ])->assertOk()
            ->assertJsonPath('user.email', 'finance.user@example.com')
            ->assertJsonPath('embed.erp_company_slug', 'ultimate')
            ->assertJsonPath('embed.locked_company', true)
            ->assertJsonStructure(['token', 'company_id', 'companies']);

        $company = Company::query()->where('code', 'ULTIMATE')->first();
        $this->assertNotNull($company);
        $this->assertSame('Ultimate Trading', $company->legal_name);

        $settings = CompanySetting::query()->where('company_id', $company->id)->first();
        $this->assertSame(7, data_get($settings?->settings_json, 'erp_bridge.erp_company_id'));
    }

    public function test_erp_bridge_rejects_bad_signature(): void
    {
        $now = time();
        $claims = [
            'erp_company_id' => 1,
            'company_slug' => 'ultimate',
            'company_name' => 'X',
            'iat' => $now,
            'exp' => $now + 60,
        ];
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

        $this->postJson('/api/v1/auth/erp-bridge', [
            'payload' => $payload,
            'sig' => 'deadbeef',
        ])->assertStatus(422);
    }
}
