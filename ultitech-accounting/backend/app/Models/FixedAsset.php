<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FixedAsset extends Model {
  use BelongsToCompany;
  protected $table = "acc_fixed_assets";
  protected $fillable = ["uuid","company_id","book_id","category_id","asset_code","name","acquisition_date","acquisition_cost","salvage_value","useful_life_months","accum_depreciation","net_book_value","status","acquisition_journal_id"];
  protected function casts(): array { return ["acquisition_date"=>"date"]; }
  public function category(): BelongsTo { return $this->belongsTo(AssetCategory::class, "category_id"); }
}
