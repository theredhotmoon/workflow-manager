<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserAvailability;

class UserAvailabilityPolicy
{
    public function manage(User $actor, UserAvailability $availability): bool
    {
        return $this->canManageFor($actor, $availability->user_id);
    }

    public function manageFor(User $actor, int $targetUserId): bool
    {
        return $this->canManageFor($actor, $targetUserId);
    }

    private function canManageFor(User $actor, int $targetUserId): bool
    {
        if ($actor->id === $targetUserId) {
            return true;
        }

        if ($actor->canManageSchedule()) {
            return true;
        }

        $target = User::find($targetUserId);

        return $target !== null && $actor->managesUser($target);
    }
}
