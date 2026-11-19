<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DepreciationMethod extends Model {
  protected $table = "acc_depreciation_methods";
  protected $fillable = ["code","name"];
}
