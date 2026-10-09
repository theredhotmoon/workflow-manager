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
     * Create an alarm for all admins and schedule maintainers.
     */
    public function notifyAdminsAndMaintainers(
        string $type,
        string $shortMessage,
        ?string $details = null,
        ?string $linkUrl = null,
        ?string $linkLabel = null
    ): void {
        $users = User::active()->scheduleManagers()->get();

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
