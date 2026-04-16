<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'delegation_id',
        'performed_by',
        'action',
        'details',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function delegation()
    {
        return $this->belongsTo(Delegation::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
