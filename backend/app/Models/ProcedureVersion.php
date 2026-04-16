<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'procedure_id',
        'version',
        'title',
        'purpose',
        'requirements',
        'content',
        'change_reason',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
