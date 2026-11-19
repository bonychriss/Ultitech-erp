<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Lease extends Model {
  use BelongsToCompany;
  protected $table = 'acc_leases';
  protected $fillable = ['uuid','company_id','book_id','lease_number','name','commencement_date','term_months','payment_amount','annual_rate_percent','initial_liability','rou_asset_cost','liability_balance','rou_net_book_value','rou_account_id','liability_account_id','interest_account_id','depreciation_account_id','commencement_journal_id','status'];
  protected function casts(): array { return ['commencement_date'=>'date']; }
  public function schedules(): HasMany { return $this->hasMany(LeaseSchedule::class, 'lease_id'); }
}
