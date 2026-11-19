<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LeaseSchedule extends Model {
  protected $table = 'acc_lease_schedules';
  protected $fillable = ['lease_id','company_id','period_no','due_date','opening_liability','interest','payment','principal','closing_liability','depreciation','status','journal_id'];
  protected function casts(): array { return ['due_date'=>'date']; }
}
