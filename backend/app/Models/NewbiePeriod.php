<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewbiePeriod extends Model
{
    protected $fillable = ['user_id', 'start_date', 'end_date', 'created_by'];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
