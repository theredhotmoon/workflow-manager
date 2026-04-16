<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivedDelegationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'archived_delegation_id',
        'performed_by',
        'action',
        'details',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function delegation()
    {
        return $this->belongsTo(ArchivedDelegation::class, 'archived_delegation_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
