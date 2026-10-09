<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /** Templates that haven't been archived: the only ones new work (fills, shift requirements) may use. */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    /** The current groups; retired ones only live on in past submissions (see allGroups). */
    public function groups()
    {
        return $this->allGroups()->whereNull('archived_at');
    }

    /** Every group the template ever had, retired ones included. */
    public function allGroups()
    {
        return $this->hasMany(ChecklistGroup::class)->orderBy('sort_order');
    }

    public function submissions()
    {
        return $this->hasMany(ChecklistSubmission::class);
    }
}
