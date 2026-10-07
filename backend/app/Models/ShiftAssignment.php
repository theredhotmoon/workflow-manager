<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftAssignment extends Model
{
    protected $fillable = ['shift_id', 'user_id', 'rest_policy'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(ShiftChangeRequest::class, 'shift_assignment_id');
    }
}
