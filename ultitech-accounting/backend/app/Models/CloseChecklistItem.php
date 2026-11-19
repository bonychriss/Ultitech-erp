<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CloseChecklistItem extends Model {
  protected $table = 'acc_close_checklist_items';
  protected $fillable = ['checklist_id','company_id','code','label','is_required','is_passed','details'];
  protected function casts(): array { return ['is_required'=>'boolean','is_passed'=>'boolean']; }
}