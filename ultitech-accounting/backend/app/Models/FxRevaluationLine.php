<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FxRevaluationLine extends Model {
  protected $table = 'acc_fx_revaluation_lines';
  protected $fillable = ['fx_revaluation_id','company_id','account_id','currency_code','foreign_balance','book_functional','closing_rate','revalued_functional','difference'];
}
