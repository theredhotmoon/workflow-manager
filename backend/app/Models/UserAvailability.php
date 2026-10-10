<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAvailability extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    /**
     * Need-to-know (L1): the note is private to the user who wrote it and to schedule managers
     * (admins, schedule maintainers). Colleagues still see the entry's type and dates.
     */
    public function noteVisibleTo(?User $viewer): bool
    {
        return $viewer !== null && ($viewer->canManageSchedule() || (int) $viewer->id === (int) $this->user_id);
    }

    public function getHidden()
    {
        $hidden = parent::getHidden();

        return $this->noteVisibleTo(User::viewer()) ? $hidden : [...$hidden, 'note'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
