<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class BankStatementLine extends Model {
  use BelongsToCompany;
  protected $table = "acc_bank_statement_lines";
  protected $fillable = ["company_id","statement_id","line_no","line_date","description","debit","credit","reference","is_matched"];
  protected function casts(): array { return ["line_date"=>"date","is_matched"=>"boolean"]; }
}
