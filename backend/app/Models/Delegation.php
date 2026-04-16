<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delegation extends Model
{
    protected $fillable = [
        'name',
        'destination',
        'description',
        'start_datetime',
        'end_datetime',
        'registration_open',
        'created_by',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'registration_open' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'delegation_user')
            ->withPivot('custom_start', 'custom_end', 'status')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs()
    {
        return $this->hasMany(DelegationLog::class)->orderByDesc('created_at');
    }
}
