<?php

use App\Models\ShiftGroup;
use App\Models\User;

it('lets an admin set settlement config when creating a group', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson('/api/shift-groups', [
        'name' => 'Event Coverage',
        'settlement_mode' => 'quarterly',
        'settlement_anchor_date' => '2026-02-01',
        'weekly_hour_cap' => 40,
    ]);

    $response->assertCreated();
    expect($response->json('settlement_mode'))->toBe('quarterly');
    expect($response->json('weekly_hour_cap'))->toBe(40.0);
});

it('requires settlement_custom_length_days when mode is custom', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson('/api/shift-groups', [
        'name' => 'Custom Group',
        'settlement_mode' => 'custom',
        'settlement_anchor_date' => '2026-01-01',
        'weekly_hour_cap' => 40,
    ])->assertStatus(422)->assertJsonValidationErrors(['settlement_custom_length_days']);
});

it('lets an admin update settlement config on an existing group', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}", [
        'settlement_mode' => 'weekly',
        'settlement_anchor_date' => '2026-09-01',
        'weekly_hour_cap' => 36,
    ]);

    $response->assertOk();
    expect($response->json('settlement_mode'))->toBe('weekly');
    expect($response->json('weekly_hour_cap'))->toBe(36.0);
});
