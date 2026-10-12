<?php

namespace App\Models;

use App\Rules\ActiveShiftGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ShiftGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
        'is_default',
        'sort_order',
        'settlement_mode',
        'settlement_anchor_date',
        'settlement_custom_length_days',
        'weekly_hour_cap',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'settlement_anchor_date' => 'date:Y-m-d',
        'settlement_custom_length_days' => 'integer',
        'weekly_hour_cap' => 'float',
        'archived_at' => 'datetime',
    ];

    /**
     * Groups new work can be planned in. An archived group keeps its past shifts, hours and on-call
     * duties (reports still count them) but is left out of the schedule tabs, pickers and auto-fill.
     */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public const ARCHIVED_OR_MISSING_MESSAGE = 'The selected shift group does not exist or is archived.';

    /** Validation rule for a shift_group_id that new work is planned in (not archived). */
    public static function activeExistsRule(): ActiveShiftGroup
    {
        return new ActiveShiftGroup;
    }

    /*
     * Lock order (archive vs planning writes): the shift group row is always the FIRST row a
     * planning transaction locks, before shifts, users, assignments or on-call rows.
     * - Archiving (ShiftGroupController::destroy) takes it FOR UPDATE, then reads and removes the
     *   group's future work.
     * - Every writer that creates or removes work in a group takes it FOR SHARE (shared locks don't
     *   block each other) and re-checks archived_at under that lock. Moves lock both groups, in id
     *   order.
     * Whichever commits first wins: a writer that waited sees archived_at and refuses; an archive
     * that waited then sees (READ COMMITTED) the work the writer committed, lists it in its 409 or
     * removes it on confirm. No transaction takes these locks in another order, so they can't
     * deadlock with each other.
     * - Removing future work (group archive, user deactivation with "remove future shifts") deletes
     *   on-call duties before it locks and removes assignments, in both places.
     * - Change request filing locks the shift FOR SHARE, then the assignment, the order a day-count
     *   reduction uses (shifts FOR UPDATE, then their assignments).
     */

    /**
     * Locks the groups FOR SHARE (in id order) inside the caller's transaction and returns them by id.
     *
     * @param  array<int|null>  $ids
     * @return Collection<int, self>
     */
    public static function lockShared(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        if ($ids === []) {
            return collect();
        }

        return static::whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
    }

    /**
     * For endpoints that take a shift_group_id: locks the group FOR SHARE and refuses (422 on
     * shift_group_id, the validation message) if it was archived after validation.
     */
    public static function lockActiveOrFail(int|string|null $id): ?self
    {
        $group = static::lockShared([$id])->first();
        if ($group?->isArchived()) {
            throw ValidationException::withMessages(['shift_group_id' => self::ARCHIVED_OR_MISSING_MESSAGE]);
        }

        return $group;
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function streakRules()
    {
        return $this->hasMany(ShiftStreakRule::class);
    }

    public function onCallAssignments()
    {
        return $this->hasMany(OnCallAssignment::class);
    }
}
