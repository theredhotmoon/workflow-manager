<?php

use App\Models\ShiftGroup;

it('leaves settlement fields null on a group created with none supplied', function () {
    // Note on scope: this only confirms the columns are nullable with no DB-level
    // default — it cannot exercise the migration's one-time backfill UPDATE, since
    // RefreshDatabase runs migrations against an empty table before any test creates
    // rows (the same limitation noted in sub-project 1's equivalent backfill test).
    // The calculator's own null-config fallback (tested in Task 2) is what actually
    // guarantees "no config" behaves like "monthly, 48h" at read time.
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    expect($group->settlement_mode)->toBeNull();
    expect($group->weekly_hour_cap)->toBeNull();
    expect($group->settlement_anchor_date)->toBeNull();
});

it('lets a group be created with explicit settlement config', function () {
    $group = ShiftGroup::create([
        'name' => 'Event Coverage',
        'sort_order' => 1,
        'settlement_mode' => 'quarterly',
        'settlement_anchor_date' => '2026-02-01',
        'weekly_hour_cap' => 40,
    ]);

    expect($group->settlement_mode)->toBe('quarterly');
    expect($group->settlement_anchor_date->toDateString())->toBe('2026-02-01');
    expect($group->weekly_hour_cap)->toBe(40.0);
    expect($group->settlement_custom_length_days)->toBeNull();
});
