<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConsolidationMember extends Model {
  protected $table = 'acc_consolidation_members';
  protected $fillable = ['group_id','company_id','member_company_id','member_book_id','ownership_percent','is_parent'];
  protected function casts(): array { return ['is_parent'=>'boolean']; }
}
