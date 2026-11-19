<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryLayer extends Model {
  use BelongsToCompany;
  protected $table = "acc_inventory_layers";
  protected $fillable = ["company_id","item_id","received_date","quantity_remaining","unit_cost"];
  protected function casts(): array { return ["received_date"=>"date"]; }
}
