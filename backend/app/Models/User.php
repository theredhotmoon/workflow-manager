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

    /** Set by withEmail(): serialise the email whoever (or no one) is viewing. */
    private bool $emailForced = false;

    /**
     * Need-to-know (L4): a user's email is serialised only for admins and for the user themselves.
     * The decision is made per serialisation from the logged-in viewer (User::viewer()), so every
     * payload that serialises a User (eager-loaded relations, partial selects, lists, toArray(),
     * toJson()) gets it, and a new endpoint can't leak colleagues' emails by default.
     *
     * Outside a request (console commands, queued jobs, exports run by a job) there is no viewer and
     * the email is hidden (fail-closed). Code there that really needs it reads $user->email directly,
     * or calls $user->withEmail() before serialising. makeVisible('email') does NOT override this.
     * Never use $user->only([...'email'...]) in a non-admin payload: only() reads attributes and
     * bypasses $hidden; filter collect($user->toArray())->only([...]) instead.
     */
    public function getHidden()
    {
        $hidden = parent::getHidden();

        return $this->emailForced || $this->emailVisibleTo(self::viewer())
            ? $hidden
            : array_values(array_unique([...$hidden, 'email']));
    }

    /** Escape hatch for code with no request viewer that must include the email (see getHidden()). */
    public function withEmail(): static
    {
        $this->emailForced = true;

        return $this;
    }

    public function emailVisibleTo(?self $viewer): bool
    {
        // Integer compare: an id read from a raw query or pivot can arrive as a string.
        return $viewer !== null && ($viewer->isAdmin() || ($this->getKey() !== null && (int) $viewer->getKey() === (int) $this->getKey()));
    }

    /** The logged-in user a response is being built for; null outside a request (console, queue). */
    public static function viewer(): ?self
    {
        $user = auth()->user();

        return $user instanceof self ? $user : null;
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
