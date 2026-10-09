<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** role is the only source of admin / schedule-maintainer status. */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SCHEDULE_MAINTAINER = 'schedule_maintainer';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_SCHEDULE_MAINTAINER, self::ROLE_EMPLOYEE];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    protected $appends = [];

    /**
     * Read-only is_admin, derived from role, for API clients that still read the old flag. Only added
     * when role was loaded, so a partial select (e.g. owner:id,name) doesn't claim false for an admin.
     */
    protected function getArrayableAppends()
    {
        $appends = parent::getArrayableAppends();

        return array_key_exists('role', $this->attributes)
            ? array_values(array_unique(array_merge($appends, ['is_admin'])))
            : $appends;
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    /** Users who can log in and be picked for new work; deactivated ones only appear in history. */
    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** Validation rule: the id of a user who isn't deactivated. */
    public static function activeExistsRule(): Exists
    {
        return Rule::exists('users', 'id')->whereNull('deactivated_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isScheduleMaintainer(): bool
    {
        return $this->role === self::ROLE_SCHEDULE_MAINTAINER;
    }

    public function canManageSchedule(): bool
    {
        return $this->isAdmin() || $this->isScheduleMaintainer();
    }

    /** Query counterparts of isAdmin() / canManageSchedule(). */
    public function scopeAdmins($query)
    {
        return $query->where('role', self::ROLE_ADMIN);
    }

    public function scopeNotAdmins($query)
    {
        return $query->where('role', '!=', self::ROLE_ADMIN);
    }

    public function scopeScheduleManagers($query)
    {
        return $query->whereIn('role', [self::ROLE_ADMIN, self::ROLE_SCHEDULE_MAINTAINER]);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user');
    }
}
