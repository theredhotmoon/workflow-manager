<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureFeedback extends Model
{
    protected $table = 'procedure_feedbacks';

    protected $fillable = ['procedure_id', 'user_id', 'type', 'description', 'proposed_changes', 'status', 'resolution', 'owner_comment', 'for_version'];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
