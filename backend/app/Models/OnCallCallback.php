<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnCallCallback extends Model
{
    protected $fillable = ['on_call_assignment_id', 'starts_at', 'ends_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function onCallAssignment(): BelongsTo
    {
        return $this->belongsTo(OnCallAssignment::class);
    }
}
