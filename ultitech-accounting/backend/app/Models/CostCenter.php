<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class CostCenter extends Model {
  use BelongsToCompany;
  protected $table = 'acc_cost_centers';
  protected $fillable = ['company_id','book_id','code','name','status'];
}
