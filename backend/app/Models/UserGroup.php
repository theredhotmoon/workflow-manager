<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    protected $fillable = ['name', 'enum'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_group_user');
    }
}
