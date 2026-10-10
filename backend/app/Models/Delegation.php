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

    /**
     * Locks the delegation's row FOR UPDATE for the caller's transaction and returns it freshly read.
     * Delete, archive, approve and add-member all take this lock (it is the only row they lock, so
     * there is no lock order to get wrong): a member approved meanwhile is seen, not lost. 404 when
     * the delegation was deleted or archived in the meantime.
     */
    public static function lockOrGone(int $id): self
    {
        $delegation = static::whereKey($id)->lockForUpdate()->first();
        if (!$delegation) {
            abort(404, 'This delegation no longer exists.');
        }

        return $delegation;
    }

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
