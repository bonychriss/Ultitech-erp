<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryMovement extends Model {
  use BelongsToCompany;
  protected $table = "acc_inventory_movements";
  protected $fillable = ["company_id","book_id","item_id","movement_type","movement_date","quantity","unit_cost","total_cost","reference","posted_journal_id","created_by"];
  protected function casts(): array { return ["movement_date"=>"date"]; }
}
