<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureFaqReply extends Model
{
    protected $fillable = ['faq_item_id', 'body', 'created_by', 'for_version', 'is_outdated'];

    public function faqItem()
    {
        return $this->belongsTo(ProcedureFaqItem::class, 'faq_item_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
