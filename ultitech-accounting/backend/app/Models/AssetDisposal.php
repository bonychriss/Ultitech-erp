<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AssetDisposal extends Model {
  use BelongsToCompany;
  protected $table = "acc_asset_disposals";
  protected $fillable = ["company_id","book_id","asset_id","disposal_date","proceeds","net_book_value","gain_loss","posted_journal_id","created_by"];
  protected function casts(): array { return ["disposal_date"=>"date"]; }
}
