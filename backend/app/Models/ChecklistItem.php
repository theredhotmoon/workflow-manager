<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $fillable = ['checklist_group_id', 'label', 'type', 'requires_confirmation', 'sort_order', 'procedure_id', 'min_value', 'max_value'];

    protected $casts = [
        'requires_confirmation' => 'boolean',
        'min_value' => 'float',
        'max_value' => 'float',
    ];

    public function group()
    {
        return $this->belongsTo(ChecklistGroup::class, 'checklist_group_id');
    }

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }
}
