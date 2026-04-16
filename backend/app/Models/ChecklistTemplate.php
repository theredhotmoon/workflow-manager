<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function groups()
    {
        return $this->hasMany(ChecklistGroup::class)->orderBy('sort_order');
    }

    public function submissions()
    {
        return $this->hasMany(ChecklistSubmission::class);
    }
}
