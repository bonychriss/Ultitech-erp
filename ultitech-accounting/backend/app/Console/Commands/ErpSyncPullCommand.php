<?php

namespace App\Console\Commands;

use App\Application\Services\ErpSyncService;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class ErpSyncPullCommand extends Command
{
    protected $signature = 'erp:sync
        {--company=DEMO : Company code}
        {--scopes=accounts,customers,suppliers,invoices,journals,wallets : Comma-separated scopes}
        {--journal-limit=500 : Max journals to import this run}
        {--test : Only test read-only ERP connection}';

    protected $description = 'Read-only pull from UltiTech ERP into UltiTech Accounting (never writes ERP)';

    public function handle(ErpSyncService $sync, TenantContext $tenant): int
    {
        $company = Company::query()->where('code', $this->option('company'))->first();
        if (! $company) {
            $this->error('Company not found.');

            return self::FAILURE;
        }

        $book = AccountingBook::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_primary', true)
            ->first();
        if (! $book) {
            $this->error('Primary book not found.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', 'admin@ultitech.local')->first()
            ?? User::query()->first();
        if (! $user) {
            $this->error('No user available to attribute sync.');

            return self::FAILURE;
        }

        $tenant->setUser($user);
        $tenant->setCompany($company);
        $tenant->setBook($book);

        $connection = $sync->ensureConnection((int) $company->id);
        $test = $sync->test($connection);
        $this->info($test['message'] ?? json_encode($test));

        if ($this->option('test')) {
            return ($test['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($test['ok'] ?? false)) {
            return self::FAILURE;
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('scopes')))));
        $run = $sync->pull($book, $user, $scopes, (int) $this->option('journal-limit'));

        $this->info('Sync run '.$run->run_number.' → '.$run->status);
        $this->line(json_encode($run->stats_json, JSON_PRETTY_PRINT));

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
