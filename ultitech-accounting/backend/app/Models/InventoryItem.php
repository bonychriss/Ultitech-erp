<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryItem extends Model {
  use BelongsToCompany;
  protected $table = "acc_inventory_items";
  protected $fillable = ["company_id","book_id","sku","name","valuation_method","inventory_account_id","cogs_account_id","quantity_on_hand","unit_cost","total_value","status"];
}
