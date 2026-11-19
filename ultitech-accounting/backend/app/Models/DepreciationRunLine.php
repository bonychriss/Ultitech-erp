<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DepreciationRunLine extends Model {
  protected $table = "acc_depreciation_run_lines";
  protected $fillable = ["run_id","company_id","asset_id","amount"];
}
