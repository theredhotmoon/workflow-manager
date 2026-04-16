<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftColor extends Model
{
    protected $fillable = ['name', 'hex', 'sort_order'];
}
