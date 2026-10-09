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
