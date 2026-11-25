<?php
/**
 * Local-only E2E: Payment Voucher (Jan 2027) ? ERP GL bridge ? Accounting sync ? Trial Balance.
 * Tag: E2E-JAN2027 so rows are identifiable.
 */
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Application\Services\AccountBalanceService;
use App\Application\Services\ErpSyncService;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\User;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

$TAG = 'E2E-JAN2027';
$companyId = 2; // ULTIMATE
$bookId = 2;

function out(string $msg): void
{
    echo $msg.PHP_EOL;
}

out('=== E2E Jan 2027: PV ? GL ? Accounting TB ===');

$erp = DB::connection('erp_ultimate');
$pdo = $erp->getPdo();

// --- 1) Ensure Accounting FY2027 + Jan period ---
$year = DB::table('acc_fiscal_years')->where('company_id', $companyId)->where('name', 'FY2027')->first();
if (! $year) {
    $yearId = DB::table('acc_fiscal_years')->insertGetId([
        'company_id' => $companyId,
        'name' => 'FY2027',
        'start_date' => '2027-01-01',
        'end_date' => '2027-12-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    out("Created FY2027 id={$yearId}");
} else {
    $yearId = (int) $year->id;
    out("FY2027 already exists id={$yearId}");
}

$months = [
    1 => ['Jan 2027', '2027-01-01', '2027-01-31'],
    2 => ['Feb 2027', '2027-02-01', '2027-02-28'],
];
foreach ($months as $num => [$name, $start, $end]) {
    $exists = DB::table('acc_fiscal_periods')
        ->where('company_id', $companyId)
        ->where('start_date', $start)
        ->exists();
    if (! $exists) {
        DB::table('acc_fiscal_periods')->insert([
            'company_id' => $companyId,
            'fiscal_year_id' => $yearId,
            'period_number' => $num,
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        out("Created period {$name}");
    } else {
        out("Period {$name} already exists");
    }
}

// Cleanup prior E2E PVs (local only)
$old = $erp->table('payment_vouchers')->where('description', 'like', '%'.$TAG.'%')->get(['id']);
foreach ($old as $o) {
    $erp->table('voucher_items')->where('voucher_id', $o->id)->delete();
    $erp->table('account_transactions')->where('reference_type', 'payment_voucher')->where('reference_id', $o->id)->delete();
    // reverse/unlink GL if linked
    $links = $erp->table('gl_source_links')
        ->whereIn('source_table', ['payment_vouchers_payable', 'account_transactions'])
        ->where('source_id', $o->id)
        ->get();
    // also find journals by description
    $erpIds = $erp->table('gl_journal_entries')
        ->where('description', 'like', '%'.$TAG.'%')
        ->orWhere('description', 'like', '%PV/UGT/2027/E2E%')
        ->pluck('id');
    foreach ($erpIds as $eid) {
        $erp->table('gl_journal_lines')->where('entry_id', $eid)->delete();
        $erp->table('gl_source_links')->where('entry_id', $eid)->delete();
        $erp->table('gl_journal_entries')->where('id', $eid)->delete();
        DB::table('acc_erp_id_maps')->where('company_id', $companyId)->where('entity_type', 'gl_journal')->where('erp_id', (string) $eid)->delete();
        $local = DB::table('acc_journal_entries')->where('company_id', $companyId)->where('source_hash', 'erp:gl_journal:'.$eid)->first();
        if ($local) {
            DB::table('acc_journal_lines')->where('journal_id', $local->id)->delete();
            DB::table('acc_journal_entries')->where('id', $local->id)->delete();
        }
    }
    $erp->table('payment_vouchers')->where('id', $o->id)->delete();
}
unset($links);
out('Prior E2E rows cleaned (if any)');

// --- 2) Create sample PVs ---
// A) Unpaid approved ? AP accrual (Dr Operating Expenses / Cr AP)
$pvA = [
    'company_id' => 1,
    'voucher_no' => 'PV/UGT/2027/E2E-A',
    'payee_name' => 'E2E Sample Landlord Ltd',
    'payee_id' => null,
    'description' => "{$TAG} unpaid accrual - office operating cost Jan 2027",
    'currency' => 'TZS',
    'total_amount' => '2500000.00',
    'supporting_documents' => 0,
    'applicant' => 'E2E Tester',
    'department_manager' => 'E2E Tester',
    'general_manager' => 'E2E Tester',
    'status' => 'approved',
    'created_by' => 1,
    'date_created' => '2027-01-15',
    'prepared_by' => 'E2E Tester',
    'checked_by' => 'E2E Tester',
    'purpose' => 'general',
    'payment_purpose' => 'general',
    'is_paid' => 0,
    'is_posted' => 0,
    'is_draft' => 0,
    'approved_by' => 1,
    'approved_at' => '2027-01-15 10:00:00',
    'created_at' => '2027-01-15 09:00:00',
    'updated_at' => '2027-01-15 10:00:00',
];
$idA = $erp->table('payment_vouchers')->insertGetId($pvA);
$erp->table('voucher_items')->insert([
    'company_id' => 1,
    'voucher_id' => $idA,
    'payment_type' => 'Bank Transfer',
    'budget_type' => 'Operational Expenses',
    'tax_type' => null,
    'name' => 'Office operating cost',
    'amount' => '2500000.00',
    'description' => "{$TAG} operating expense line",
]);
out("Created unpaid PV #{$idA} {$pvA['voucher_no']} TZS 2,500,000 ? Operational Expenses");

// B) Paid approved ? bank payment journal (Dr Misc / Cr Bank via bridge)
$pvB = [
    'company_id' => 1,
    'voucher_no' => 'PV/UGT/2027/E2E-B',
    'payee_name' => 'E2E Sample Supplies Co',
    'payee_id' => null,
    'description' => "{$TAG} paid voucher - misc supplies Jan 2027",
    'currency' => 'TZS',
    'total_amount' => '750000.00',
    'supporting_documents' => 0,
    'applicant' => 'E2E Tester',
    'department_manager' => 'E2E Tester',
    'general_manager' => 'E2E Tester',
    'status' => 'approved',
    'created_by' => 1,
    'date_created' => '2027-01-20',
    'prepared_by' => 'E2E Tester',
    'checked_by' => 'E2E Tester',
    'purpose' => 'general',
    'payment_purpose' => 'general',
    'is_paid' => 1,
    'paid_by' => 1,
    'paid_at' => '2027-01-20 14:00:00',
    'payment_account_id' => 6, // UBA Bank
    'is_posted' => 0,
    'is_draft' => 0,
    'approved_by' => 1,
    'approved_at' => '2027-01-20 11:00:00',
    'created_at' => '2027-01-20 10:00:00',
    'updated_at' => '2027-01-20 14:00:00',
];
$idB = $erp->table('payment_vouchers')->insertGetId($pvB);
$erp->table('voucher_items')->insert([
    'company_id' => 1,
    'voucher_id' => $idB,
    'payment_type' => 'Bank Transfer',
    'budget_type' => 'Others / Miscellaneous',
    'tax_type' => null,
    'name' => 'Misc office supplies',
    'amount' => '750000.00',
    'description' => "{$TAG} misc expense line",
]);
$erp->table('account_transactions')->insert([
    'company_id' => null,
    'account_id' => 6,
    'transaction_date' => '2027-01-20 14:00:00',
    'type' => 'debit',
    'amount' => '750000.00',
    'reference_type' => 'payment_voucher',
    'reference_id' => $idB,
    'description' => "Payment for Voucher #{$pvB['voucher_no']} to {$pvB['payee_name']} ({$TAG})",
    'created_by' => 1,
    'created_at' => '2027-01-20 14:00:00',
]);
out("Created paid PV #{$idB} {$pvB['voucher_no']} TZS 750,000 ? Misc + bank tx");

// --- 3) ERP GL bridge ---
require_once dirname(__DIR__, 2).'/includes/gl/gl_bridge.php';
$stats = gl_bridge_sync_all($pdo, ['only' => ['payable', 'transactions']]);
out('GL bridge stats: '.json_encode($stats));

$glA = $erp->table('gl_journal_entries')
    ->where('source_type', 'payment_voucher_payable')
    ->where('source_ref', 'payment_voucher#'.$idA)
    ->first();
$glB = $erp->table('gl_journal_entries')
    ->where(function ($q) use ($idB, $TAG) {
        $q->where('source_ref', 'like', '%'.$idB.'%')
            ->orWhere('description', 'like', '%E2E-B%')
            ->orWhere('description', 'like', '%'.$TAG.'%');
    })
    ->whereIn('source_type', ['payment_voucher', 'payment_voucher_payable'])
    ->orderByDesc('id')
    ->get();

out('ERP GL for unpaid PV A: '.($glA ? "entry #{$glA->id} {$glA->entry_number} {$glA->source_type}" : 'MISSING'));
out('ERP GL candidates for paid PV B: '.$glB->count());
foreach ($glB as $g) {
    out("  #{$g->id} {$g->entry_number} {$g->source_type} {$g->description}");
}

if (! $glA) {
    // fallback search
    $glA = $erp->table('gl_journal_entries')->where('description', 'like', '%E2E-A%')->orWhere('description', 'like', '%PV/UGT/2027/E2E-A%')->first();
    out('Fallback GL A: '.($glA ? "#{$glA->id}" : 'still missing'));
}

// Show lines for A
if ($glA) {
    $lines = $erp->table('gl_journal_lines')->where('entry_id', $glA->id)->orderBy('line_no')->get();
    out('GL A lines:');
    foreach ($lines as $ln) {
        $acc = $erp->table('erp_accounts')->where('id', $ln->account_id)->first(['code', 'name']);
        out('  '.($acc->code ?? '?').' '.($acc->name ?? '?')." Dr={$ln->debit} Cr={$ln->credit}");
    }
}

// Find B more reliably via source links / transactions
$tx = $erp->table('account_transactions')->where('reference_type', 'payment_voucher')->where('reference_id', $idB)->first();
$glBEntry = null;
if ($tx) {
    $link = $erp->table('gl_source_links')->where('source_table', 'account_transactions')->where('source_id', $tx->id)->where('active', 1)->first();
    if ($link) {
        $glBEntry = $erp->table('gl_journal_entries')->where('id', $link->entry_id)->first();
    }
}
if (! $glBEntry) {
    $glBEntry = $erp->table('gl_journal_entries')->where('description', 'like', '%PV/UGT/2027/E2E-B%')->orderByDesc('id')->first();
}
out('ERP GL for paid PV B: '.($glBEntry ? "entry #{$glBEntry->id} {$glBEntry->entry_number} {$glBEntry->source_type}" : 'MISSING'));
if ($glBEntry) {
    $lines = $erp->table('gl_journal_lines')->where('entry_id', $glBEntry->id)->orderBy('line_no')->get();
    out('GL B lines:');
    foreach ($lines as $ln) {
        $acc = $erp->table('erp_accounts')->where('id', $ln->account_id)->first(['code', 'name']);
        out('  '.($acc->code ?? '?').' '.($acc->name ?? '?')." Dr={$ln->debit} Cr={$ln->credit}");
    }
}

// --- 4) Sync into Accounting ---
$company = Company::query()->findOrFail($companyId);
$book = AccountingBook::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $bookId)->firstOrFail();
$user = User::query()->where('email', 'admin@ultitech.local')->first() ?? User::query()->firstOrFail();
app(TenantContext::class)->setUser($user);
app(TenantContext::class)->setCompany($company);
app(TenantContext::class)->setBook($book);

$run = app(ErpSyncService::class)->pull($book, $user, ['journals'], 500);
out('Accounting sync: '.$run->status.' imported='.data_get($run->stats_json, 'journals_imported').' skipped='.data_get($run->stats_json, 'journals_skipped'));
if (! empty(data_get($run->stats_json, 'errors'))) {
    out('Sync errors: '.json_encode(data_get($run->stats_json, 'errors')));
}

// --- 5) Verify local journals + TB ---
$localA = $glA ? DB::table('acc_journal_entries')->where('company_id', $companyId)->where('source_hash', 'erp:gl_journal:'.$glA->id)->first() : null;
$localB = $glBEntry ? DB::table('acc_journal_entries')->where('company_id', $companyId)->where('source_hash', 'erp:gl_journal:'.$glBEntry->id)->first() : null;
out('Accounting journal A: '.($localA ? "{$localA->journal_number} status={$localA->status} Dr={$localA->total_debit} Cr={$localA->total_credit}" : 'MISSING'));
out('Accounting journal B: '.($localB ? "{$localB->journal_number} status={$localB->status} Dr={$localB->total_debit} Cr={$localB->total_credit}" : 'MISSING'));

$tb = app(AccountBalanceService::class)->trialBalance($companyId, $bookId, '2027-01-31');
$accounts = DB::table('acc_accounts')->where('company_id', $companyId)->where('book_id', $bookId)->pluck('code', 'id');
$want = ['5011', '5010', '2001', '1002', '1003', '1001'];
out('Trial Balance as of 2027-01-31 (selected):');
$found = [];
foreach ($tb as $row) {
    $code = $accounts[$row['account_id']] ?? null;
    if (! $code || ! in_array($code, $want, true)) {
        continue;
    }
    $found[$code] = $row;
    out("  {$code} Dr={$row['debit']} Cr={$row['credit']}");
}

$okA = $localA
    && $localA->status === 'posted'
    && Money::equals((string) $localA->total_debit, (string) $localA->total_credit)
    && isset($found['5011'])
    && Money::compare($found['5011']['debit'], '2499999') > 0;

$okB = $localB
    && $localB->status === 'posted'
    && Money::equals((string) $localB->total_debit, (string) $localB->total_credit)
    && isset($found['5010'])
    && Money::compare($found['5010']['debit'], '749999') > 0;

out('');
out('RESULT unpaid PV ? AP accrual ? TB Operating Expenses: '.($okA ? 'PASS' : 'FAIL'));
out('RESULT paid PV ? bank payment GL ? TB Misc Expenses: '.($okB ? 'PASS' : 'FAIL'));
out('OVERALL: '.(($okA && $okB) ? 'PASS' : 'FAIL'));
exit(($okA && $okB) ? 0 : 1);
