<?php

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\User;

// L14: a number item may have only a maximum (or only a minimum); max >= min is checked only
// when both are filled, on create and on update.

function cnrPayload(array $item): array
{
    return [
        'name' => 'Readings',
        'groups' => [['name' => 'Server room', 'items' => [array_merge(['label' => 'Temperature', 'type' => 'number'], $item)]]],
    ];
}

it('saves a number item with only a maximum, or only a minimum', function (array $range) {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson('/api/checklist-templates', cnrPayload($range))->assertCreated();

    $item = ChecklistItem::sole();
    expect($item->min_value === null ? null : (float) $item->min_value)->toBe($range['min_value'] ?? null)
        ->and($item->max_value === null ? null : (float) $item->max_value)->toBe($range['max_value'] ?? null);
})->with([
    'max only' => [['max_value' => 27.0]],
    'max only, min sent empty' => [['min_value' => null, 'max_value' => 27.0]],
    'min only' => [['min_value' => 18.0]],
]);

it('still refuses a maximum below the minimum', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson('/api/checklist-templates', cnrPayload(['min_value' => 20, 'max_value' => 10]))
        ->assertUnprocessable()->assertJsonValidationErrors('groups.0.items.0.max_value');
    $this->actingAs($admin)->postJson('/api/checklist-templates', cnrPayload(['min_value' => 10, 'max_value' => 10]))
        ->assertCreated();
});

it('saves a max-only range when a template is updated', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->postJson('/api/checklist-templates', cnrPayload(['min_value' => 18, 'max_value' => 27]))->assertCreated();
    $template = ChecklistTemplate::sole();
    $item = ChecklistItem::sole();

    $payload = cnrPayload(['id' => $item->id, 'min_value' => null, 'max_value' => 30]);
    $payload['groups'][0]['id'] = $item->checklist_group_id;
    $this->actingAs($admin)->putJson("/api/checklist-templates/{$template->id}", $payload)->assertOk();

    expect($item->fresh()->min_value)->toBeNull()
        ->and((float) $item->fresh()->max_value)->toBe(30.0);

    $payload = cnrPayload(['id' => $item->id, 'min_value' => 31, 'max_value' => 30]);
    $payload['groups'][0]['id'] = $item->checklist_group_id;
    $this->actingAs($admin)->putJson("/api/checklist-templates/{$template->id}", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('groups.0.items.0.max_value');
});
