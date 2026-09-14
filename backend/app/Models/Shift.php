<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = ['date', 'shift_number', 'shift_group_id', 'start_time', 'end_time', 'work_mode', 'color', 'color_label'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function shiftGroup()
    {
        return $this->belongsTo(ShiftGroup::class);
    }

    public function assignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function shiftChecklists()
    {
        return $this->hasMany(ShiftChecklist::class)->orderBy('sort_order');
    }

    public function submissions()
    {
        return $this->hasMany(ChecklistSubmission::class);
    }
}
