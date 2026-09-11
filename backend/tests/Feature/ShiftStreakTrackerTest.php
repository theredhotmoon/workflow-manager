<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;
use App\Services\ShiftStreakTracker;
use Illuminate\Support\Carbon;

function makeStreakRule(int $groupId, string $shiftType, int $streakLength, int $restDays): ShiftStreakRule
{
    return ShiftStreakRule::create([
        'shift_group_id' => $groupId,
        'shift_type' => $shiftType,
        'streak_length' => $streakLength,
        'rest_days' => $restDays,
    ]);
}

it('increments the count on assignment without triggering rest below the streak length', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(2);
    expect($states[$key]['resting_until'])->toBeNull();
});

it('triggers a rest window and resets the count once the streak length is reached', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-03'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
    expect($states[$key]['resting_until']->toDateString())->toBe('2026-09-05');
});

it('leaves the count and rest window untouched when the assignment is a different shift_type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'day', Carbon::parse('2026-09-02'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(1);
});

it('leaves the count untouched when recordAssignment is called with a null shift_type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, null, Carbon::parse('2026-09-01'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
});

it('unions multiple simultaneously-active rest windows across different rules when checking isBlocked', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    makeStreakRule($group->id, 'night', 1, 2);
    makeStreakRule($group->id, 'day', 5, 1);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    // One night shift immediately triggers its 2-day rest window (streak_length 1).
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));

    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-02')))->toBeTrue();
    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-03')))->toBeTrue();
    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-04')))->toBeFalse();
});

it('floors the decrement at 0 when recordUnassignment is called with no prior assignment', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordUnassignment($states, $rules, $user->id, 'night');

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
});

it('persists only dirty states and reloads the persisted value', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->persist($states);

    expect(ShiftStreakState::where('user_id', $user->id)->where('shift_streak_rule_id', $rule->id)->first()->current_count)->toBe(1);

    $reloaded = $tracker->loadStates($rules, [$user->id]);
    $key = "{$user->id}:{$rule->id}";
    expect($reloaded[$key]['current_count'])->toBe(1);
});

it('does not reprocess an assignment date already covered by the last_processed_date watermark', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'), advanceWatermark: true);

    expect($tracker->wasAlreadyProcessed($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01')))->toBeTrue();
    expect($tracker->wasAlreadyProcessed($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02')))->toBeFalse();
});
