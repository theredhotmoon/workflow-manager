<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_admin' => 'boolean',
    ];

    protected $appends = [];

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->is_admin;
    }

    public function isScheduleMaintainer(): bool
    {
        return $this->role === 'schedule_maintainer';
    }

    public function canManageSchedule(): bool
    {
        return $this->isAdmin() || $this->isScheduleMaintainer();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user');
    }

    public function managedGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_managers');
    }

    public function managesUser(User $target): bool
    {
        if ($this->id === $target->id) {
            return false;
        }

        return $this->managedGroups()
            ->whereHas('users', fn ($q) => $q->where('users.id', $target->id))
            ->exists();
    }
}
