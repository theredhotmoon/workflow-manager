<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureOwnerChange extends Model
{
    protected $fillable = [
        'procedure_id',
        'old_owner_id',
        'new_owner_id',
        'changed_by_id',
        'reason',
    ];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function oldOwner()
    {
        return $this->belongsTo(User::class, 'old_owner_id');
    }

    public function newOwner()
    {
        return $this->belongsTo(User::class, 'new_owner_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
