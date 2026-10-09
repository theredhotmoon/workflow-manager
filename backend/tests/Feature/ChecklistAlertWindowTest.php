<?php

use App\Http\Controllers\ChecklistAlertController;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// M10: /my-checklist-alerts only looks back ALERT_LOOKBACK_DAYS business days.

beforeEach(function () {
    config(['app.business_timezone' => 'Europe/Warsaw']);
    // 08:00 UTC = 10:00 Warsaw on 2026-07-15.
    Carbon::setTestNow('2026-07-15 08:00:00');
});

function cawMissedShift(User $user, string $date, int $number = 1): Shift
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create([
        'date' => $date,
        'shift_number' => $number,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);
    $template = ChecklistTemplate::create(['name' => "Handover {$date} #{$number}", 'is_active' => true]);
    ShiftChecklist::create([
        'shift_id' => $shift->id,
        'checklist_template_id' => $template->id,
        'is_required' => true,
        'deadline_minutes' => 30,
        'deadline_reference' => 'shift_start',
    ]);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);

    return $shift;
}

it('uses a 7-day lookback window', function () {
    expect(ChecklistAlertController::ALERT_LOOKBACK_DAYS)->toBe(7);
});

it('reports a checklist missed 2 days ago but not one missed 30 days ago', function () {
    $user = User::factory()->create();
    $recent = cawMissedShift($user, '2026-07-13');
    $old = cawMissedShift($user, '2026-06-15');

    $overdue = $this->actingAs($user)->getJson('/api/my-checklist-alerts')->assertOk()->json('overdue_alerts');

    expect(collect($overdue)->pluck('shift_id')->all())->toBe([$recent->id]);
    expect(collect($overdue)->pluck('shift_id'))->not->toContain($old->id);
});

it('still reports a checklist missed exactly 7 days ago, but not 8', function () {
    $user = User::factory()->create();
    $edge = cawMissedShift($user, '2026-07-08');
    cawMissedShift($user, '2026-07-07');

    $overdue = $this->actingAs($user)->getJson('/api/my-checklist-alerts')->assertOk()->json('overdue_alerts');

    expect(collect($overdue)->pluck('shift_id')->all())->toBe([$edge->id]);
});

it('runs a fixed number of queries however many shifts are in the window', function () {
    $countQueries = function (User $user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->getJson('/api/my-checklist-alerts')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = User::factory()->create();
    cawMissedShift($few, '2026-07-14', 3);

    $many = User::factory()->create();
    foreach (['2026-07-09', '2026-07-10', '2026-07-11', '2026-07-12', '2026-07-13', '2026-07-14'] as $date) {
        cawMissedShift($many, $date, 1);
        cawMissedShift($many, $date, 2);
    }

    $fewCount = $countQueries($few);
    $manyCount = $countQueries($many);

    expect($manyCount)->toBe($fewCount);
    expect(count($this->actingAs($many)->getJson('/api/my-checklist-alerts')->json('overdue_alerts')))->toBe(12);
});
