<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistGroup extends Model
{
    protected $fillable = ['checklist_template_id', 'name', 'sort_order'];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /** The current items; retired ones only live on in past submissions (see allItems). */
    public function items()
    {
        return $this->allItems()->whereNull('archived_at');
    }

    /** Every item the group ever had, retired ones included. */
    public function allItems()
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }
}
