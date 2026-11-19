<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AssetCategory extends Model {
  use BelongsToCompany;
  protected $table = "acc_asset_categories";
  protected $fillable = ["company_id","book_id","code","name","asset_account_id","accum_depr_account_id","depr_expense_account_id","depreciation_method_id","useful_life_months","salvage_percent"];
}
