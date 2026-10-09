<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Support\Month;
use Illuminate\Support\Carbon;

// Carbon::createFromFormat('Y-m', …) fills in *today's* day, so on the 29th-31st a short month
// overflows into the next one (on Oct 31, '2027-02' became March 3 → March). These tests pin the
// clock to the 31st; Laravel's TestCase::tearDown() resets it after each test.

it('parses a Y-m month to its first day regardless of today\'s day', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');

    expect(Month::start('2027-02')->toDateTimeString())->toBe('2027-02-01 00:00:00');
    expect(Month::start('2027-04')->toDateTimeString())->toBe('2027-04-01 00:00:00');
    expect(Month::start('2027-12')->toDateTimeString())->toBe('2027-12-01 00:00:00');
});

it('returns the requested month from the schedule endpoint on the 31st', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    Shift::create(['date' => '2027-02-10', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    Shift::create(['date' => '2027-03-05', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $response = $this->actingAs($maintainer)->getJson("/api/schedule?month=2027-02&shift_group_id={$group->id}");

    $response->assertOk();
    $dates = array_keys($response->json('schedule'));
    expect($dates)->toContain('2027-02-10');
    expect($dates)->not->toContain('2027-03-05');
});

it('returns the requested month from my-shifts on the 31st', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    foreach (['2027-02-10', '2027-03-05'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }

    $response = $this->actingAs($employee)->getJson('/api/my-shifts?month=2027-02');

    $response->assertOk();
    expect($response->getContent())->toContain('2027-02-10');
    expect($response->getContent())->not->toContain('2027-03-05');
});

it('no code parses a Y-m month with createFromFormat(\'Y-m\')', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    // array_merge, not [...$spread]: CI runs PHP 8.0, which can't unpack string-keyed arrays.
    foreach (array_merge(iterator_to_array($files, false), [new SplFileInfo(base_path('routes/api.php'))]) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && preg_match("/createFromFormat\(\s*['\"]Y-m['\"]/", file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], 'Use App\Support\Month::start() instead (createFromFormat(\'Y-m\') overflows on the 29th-31st)');
});
