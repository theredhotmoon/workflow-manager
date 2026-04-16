<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivedDelegation extends Model
{
    protected $fillable = [
        'original_id',
        'name',
        'destination',
        'description',
        'start_datetime',
        'end_datetime',
        'registration_open',
        'created_by',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'archived_at' => 'datetime',
        'registration_open' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'archived_delegation_user', 'archived_delegation_id')
            ->withPivot('custom_start', 'custom_end', 'status')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archivedByUser()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function logs()
    {
        return $this->hasMany(ArchivedDelegationLog::class)->orderByDesc('created_at');
    }
}
