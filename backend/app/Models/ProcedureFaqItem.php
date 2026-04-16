<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureFaqItem extends Model
{
    protected $fillable = ['procedure_id', 'question', 'created_by', 'for_version', 'is_outdated'];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function replies()
    {
        return $this->hasMany(ProcedureFaqReply::class, 'faq_item_id');
    }
}
