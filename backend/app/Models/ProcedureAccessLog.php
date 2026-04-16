<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureAccessLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['procedure_id', 'user_id', 'version_read', 'accessed_at'];
    protected $casts = ['accessed_at' => 'datetime'];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
