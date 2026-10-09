<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistSubmission extends Model
{
    protected $fillable = [
        'checklist_template_id',
        'shift_id',
        'date',
        'outgoing_user_id',
        'incoming_user_id',
        'status',
        'general_comments',
        'submitted_at',
        'submitted_by_user_id',
        'confirmed_at',
        'confirmed_by_id',
        'submitted_on_time',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'submitted_on_time' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function outgoingUser()
    {
        return $this->belongsTo(User::class, 'outgoing_user_id');
    }

    public function incomingUser()
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }

    /** Who filed the handover: its outgoing user, or an admin filing on their behalf (null on old rows). */
    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Handovers $user may read: every one for an admin; otherwise only those naming them as the
     * outgoing or incoming user, or that they filed.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('outgoing_user_id', $user->id)
            ->orWhere('incoming_user_id', $user->id)
            ->orWhere('submitted_by_user_id', $user->id));
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->isAdmin()
            || in_array($user->id, [$this->outgoing_user_id, $this->incoming_user_id, $this->submitted_by_user_id], true);
    }

    /** Who confirmed the handover: always its incoming user. */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function responses()
    {
        return $this->hasMany(ChecklistResponse::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
