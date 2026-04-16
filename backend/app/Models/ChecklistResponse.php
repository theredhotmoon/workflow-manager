<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistResponse extends Model
{
    protected $fillable = ['checklist_submission_id', 'checklist_item_id', 'value', 'confirmed', 'group_comment'];

    protected $casts = [
        'confirmed' => 'boolean',
    ];

    public function submission()
    {
        return $this->belongsTo(ChecklistSubmission::class, 'checklist_submission_id');
    }

    public function item()
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
