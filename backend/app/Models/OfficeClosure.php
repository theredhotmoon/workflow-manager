<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeClosure extends Model
{
    protected $fillable = ['date', 'title', 'description', 'created_by'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
