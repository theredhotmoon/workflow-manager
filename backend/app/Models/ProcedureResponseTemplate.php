<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureResponseTemplate extends Model
{
    protected $fillable = ['procedure_id', 'title', 'body', 'language'];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }
}
