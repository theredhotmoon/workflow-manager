<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureTag extends Model
{
    protected $fillable = ['name', 'color'];

    public function procedures()
    {
        return $this->belongsToMany(Procedure::class, 'procedure_tag', 'tag_id', 'procedure_id');
    }
}
