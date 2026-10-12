<?php

namespace App\Services;

use App\Models\Alarm;
use App\Models\User;

class AlarmService
{
    /**
     * Create an alarm for a specific user.
     */
    public function createAlarm(
        string $type,
        int $userId,
        string $shortMessage,
        ?string $details = null,
        ?string $linkUrl = null,
        ?string $linkLabel = null
    ): Alarm {
        return Alarm::create([
            'type' => $type,
            'user_id' => $userId,
            'short_message' => $shortMessage,
            'details' => $details,
            'link_url' => $linkUrl,
            'link_label' => $linkLabel,
        ]);
    }

    /**
     * Create an alarm for all active admins and schedule maintainers, except $exceptUserId (the
     * manager whose own action it reports).
     */
    public function notifyAdminsAndMaintainers(
        string $type,
        string $shortMessage,
        ?string $details = null,
        ?string $linkUrl = null,
        ?string $linkLabel = null,
        ?int $exceptUserId = null
    ): void {
        $users = User::active()->scheduleManagers()
            ->when($exceptUserId !== null, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->get();

        foreach ($users as $user) {
            $this->createAlarm($type, $user->id, $shortMessage, $details, $linkUrl, $linkLabel);
        }
    }

    /**
     * Create an alarm for a specific user by user ID.
     */
    public function notifyUser(
        string $type,
        int $userId,
        string $shortMessage,
        ?string $details = null,
        ?string $linkUrl = null,
        ?string $linkLabel = null
    ): Alarm {
        return $this->createAlarm($type, $userId, $shortMessage, $details, $linkUrl, $linkLabel);
    }
}
