<?php

namespace Tests\Feature;

use App\Application\Services\AccountBalanceService;
use App\Application\Services\ErpSyncService;
use App\Application\Services\JournalReversalService;
use App\Integration\Erp\ReadOnlyErpGateway;
use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\JournalEntry;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Proves ERP GL ? JournalPostingService ? Accounting GL ? Trial Balance,
 * with idempotent re-import and no operational-table TB fabrication.
 */
class ErpJournalSyncGlTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private Account $ar;
    private Account $revenue;
    private Account $vat;
    private Account $cash;
    private Account $ap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootTenant();
        config(['erp.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_erp_journal_import_posts_balanced_gl_and_feeds_trial_balance(): void
    {
        $this->bindFakeErpGateway([
            (object) [
                'id' => 101,
                'entry_number' => 'GL-2026-000101',
                'entry_date' => '2026-03-15',
                'description' => 'Sales invoice INV-2026-0099 taxable sale',
                'source_type' => 'revenue_entry',
                'source_key' => 'rev:99:v1',
                'currency' => 'TZS',
                'total_amount' => '1180000.00',
                'lines' => [
                    (object) ['line_no' => 1, 'account_id' => 10, 'debit' => '1180000', 'credit' => '0', 'memo' => 'AR', 'currency' => 'TZS'],
                    (object) ['line_no' => 2, 'account_id' => 20, 'debit' => '0', 'credit' => '1000000', 'memo' => 'Sales', 'currency' => 'TZS'],
                    (object) ['line_no' => 3, 'account_id' => 30, 'debit' => '0', 'credit' => '180000', 'memo' => 'VAT', 'currency' => 'TZS'],
                ],
            ],
            (object) [
                'id' => 102,
                'entry_number' => 'GL-2026-000102',
                'entry_date' => '2026-03-16',
                'description' => 'Receipt against INV-2026-0099',
                'source_type' => 'invoice_payment',
                'source_key' => 'pay:1:v1',
                'currency' => 'TZS',
                'total_amount' => '1180000.00',
                'lines' => [
                    (object) ['line_no' => 1, 'account_id' => 40, 'debit' => '1180000', 'credit' => '0', 'memo' => 'Bank', 'currency' => 'TZS'],
                    (object) ['line_no' => 2, 'account_id' => 10, 'debit' => '0', 'credit' => '1180000', 'memo' => 'Clear AR', 'currency' => 'TZS'],
                ],
            ],
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'C-99',
            'name' => 'Test Customer',
            'currency_code' => 'TZS',
            'status' => 'active',
        ]);
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-0099',
            'invoice_date' => '2026-03-15',
            'currency_code' => 'TZS',
            'subtotal' => '1000000.0000',
            'tax_total' => '180000.0000',
            'total' => '1180000.0000',
            'amount_paid' => '0.0000',
            'balance_due' => '1180000.0000',
            'status' => 'open',
            'description' => 'Mirrored ERP invoice',
            'created_by' => $this->user->id,
        ]);

        $connection = app(ErpSyncService::class)->ensureConnection((int) $this->company->id);
        // Map ERP GL account ids ? local CoA (no hardcoding in sync; maps drive posting).
        foreach ([
            '10' => $this->ar->id,
            '20' => $this->revenue->id,
            '30' => $this->vat->id,
            '40' => $this->cash->id,
        ] as $erpId => $localId) {
            \App\Models\ErpIdMap::query()->create([
                'company_id' => $this->company->id,
                'connection_id' => $connection->id,
                'entity_type' => 'account',
                'erp_id' => $erpId,
                'local_id' => $localId,
            ]);
        }

        $run = app(ErpSyncService::class)->pull($this->book, $this->user, ['journals'], 50);
        $this->assertContains($run->status, ['completed', 'completed_with_errors']);
        $this->assertSame(2, (int) data_get($run->stats_json, 'journals_imported'));

        $journals = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('book_id', $this->book->id)
            ->where('source_type', 'erp_gl_journal')
            ->orderBy('id')
            ->with('lines')
            ->get();

        $this->assertCount(2, $journals);
        foreach ($journals as $journal) {
            $this->assertSame('posted', $journal->status);
            $this->assertTrue(Money::equals((string) $journal->total_debit, (string) $journal->total_credit));
            $this->assertStringStartsWith('erp:gl_journal:', (string) $journal->source_hash);
        }

        $sale = $journals->first();
        $this->assertSame('1180000.0000', Money::normalize($sale->total_debit));
        $this->assertCount(3, $sale->lines);

        $tb = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $this->book->id);
        $byId = $tb->keyBy('account_id');
        $this->assertTrue(Money::equals(
            $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000'),
            $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000')
        ));
        $this->assertTrue(Money::equals(Money::normalize($byId[$this->revenue->id]['credit'] ?? '0'), '1000000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($byId[$this->vat->id]['credit'] ?? '0'), '180000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($byId[$this->cash->id]['debit'] ?? '0'), '1180000.0000'));
        // AR debits and credits both present from sale + receipt (net zero economically).
        $this->assertTrue(Money::equals(Money::normalize($byId[$this->ar->id]['debit'] ?? '0'), '1180000.0000'));
        $this->assertTrue(Money::equals(Money::normalize($byId[$this->ar->id]['credit'] ?? '0'), '1180000.0000'));

        $invoice->refresh();
        $this->assertNotNull($invoice->posted_journal_id);
        $this->assertSame((int) $sale->id, (int) $invoice->posted_journal_id);

        // Second pull must not duplicate.
        $run2 = app(ErpSyncService::class)->pull($this->book, $this->user, ['journals'], 50);
        $this->assertSame(0, (int) data_get($run2->stats_json, 'journals_imported'));
        $this->assertSame(
            2,
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'erp_gl_journal')
                ->count()
        );

        // Company/book isolation: other book must not see these balances.
        $bookB = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'IFRS-B',
            'name' => 'Secondary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => false,
            'status' => 'active',
        ]);
        $tbB = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $bookB->id);
        $this->assertTrue($tbB->isEmpty());

        // Reversal of imported journal still works and remains GL-sourced.
        $reversal = app(JournalReversalService::class)->reverse($sale->fresh('lines'), $this->user, 'ERP import correction');
        $this->assertSame('posted', $reversal->status);
        $this->assertTrue(Money::equals((string) $reversal->total_debit, (string) $reversal->total_credit));
        $sale->refresh();
        $this->assertSame('reversed', $sale->status);
    }

    public function test_sync_advances_past_already_imported_journals(): void
    {
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = (object) [
                'id' => $i,
                'entry_number' => 'GL-'.$i,
                'entry_date' => '2026-03-10',
                'description' => 'Batch '.$i,
                'source_type' => 'expense',
                'source_key' => 'exp:'.$i,
                'currency' => 'TZS',
                'total_amount' => '100.00',
                'lines' => [
                    (object) ['line_no' => 1, 'account_id' => 50, 'debit' => '100', 'credit' => '0', 'memo' => null, 'currency' => 'TZS'],
                    (object) ['line_no' => 2, 'account_id' => 60, 'debit' => '0', 'credit' => '100', 'memo' => null, 'currency' => 'TZS'],
                ],
            ];
        }
        $this->bindFakeErpGateway($entries);

        $connection = app(ErpSyncService::class)->ensureConnection((int) $this->company->id);
        \App\Models\ErpIdMap::query()->create([
            'company_id' => $this->company->id,
            'connection_id' => $connection->id,
            'entity_type' => 'account',
            'erp_id' => '50',
            'local_id' => $this->ar->id,
        ]);
        \App\Models\ErpIdMap::query()->create([
            'company_id' => $this->company->id,
            'connection_id' => $connection->id,
            'entity_type' => 'account',
            'erp_id' => '60',
            'local_id' => $this->ap->id,
        ]);

        $first = app(ErpSyncService::class)->pull($this->book, $this->user, ['journals'], 2);
        $this->assertSame(2, (int) data_get($first->stats_json, 'journals_imported'));

        $second = app(ErpSyncService::class)->pull($this->book, $this->user, ['journals'], 10);
        $this->assertSame(3, (int) data_get($second->stats_json, 'journals_imported'));
        $this->assertSame(
            5,
            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'erp_gl_journal')
                ->count()
        );
    }

    /**
     * @param  list<object>  $entries
     */
    private function bindFakeErpGateway(array $entries): void
    {
        $byId = [];
        foreach ($entries as $entry) {
            $byId[(int) $entry->id] = $entry;
        }

        $gateway = Mockery::mock(ReadOnlyErpGateway::class);
        $gateway->shouldReceive('useDatabase')->andReturnNull();
        $gateway->shouldReceive('testConnection')->andReturn([
            'ok' => true,
            'database' => 'fake_erp',
            'read_only' => true,
            'message' => 'ok',
        ]);
        $gateway->shouldReceive('tableExists')->andReturn(true);
        $gateway->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings = []) use ($byId, $entries) {
            if (str_contains($sql, 'FROM gl_journal_entries')) {
                $after = (int) ($bindings[0] ?? 0);
                $limit = 100;
                if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) {
                    $limit = (int) $m[1];
                }
                $out = [];
                foreach ($entries as $entry) {
                    if ((int) $entry->id <= $after) {
                        continue;
                    }
                    $clone = clone $entry;
                    unset($clone->lines);
                    $out[] = $clone;
                    if (count($out) >= $limit) {
                        break;
                    }
                }

                return $out;
            }

            if (str_contains($sql, 'FROM gl_journal_lines')) {
                $entryId = (int) ($bindings[0] ?? 0);
                $entry = $byId[$entryId] ?? null;

                return $entry?->lines ?? [];
            }

            return [];
        });

        $this->app->instance(ReadOnlyErpGateway::class, $gateway);
    }

    private function bootTenant(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'ERP GL Tester',
            'email' => 'erp-gl@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ERPGL',
            'legal_name' => 'ERP GL Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        UserCompanyAccess::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role_id' => $adminRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);
        $this->book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);
        PostingSequence::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'sequence_key' => 'journal',
            'prefix' => 'JE',
            'next_number' => 1,
            'padding' => 6,
        ]);
        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'TZS',
            'to_currency' => 'TZS',
            'rate_date' => '2026-01-01',
            'rate' => 1,
            'source' => 'system',
            'created_by' => $this->user->id,
        ]);
        $year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 3,
            'name' => 'Mar 2026',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'status' => 'open',
        ]);

        $types = \App\Models\AccountType::query()->pluck('id', 'code');
        $this->ar = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['asset'],
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->cash = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['asset'],
            'code' => '1010',
            'name' => 'Bank',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->ap = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['liability'],
            'code' => '2000',
            'name' => 'Accounts Payable',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->vat = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['liability'],
            'code' => '2100',
            'name' => 'VAT Payable',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->revenue = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['revenue'],
            'code' => '4000',
            'name' => 'Sales Revenue',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}
