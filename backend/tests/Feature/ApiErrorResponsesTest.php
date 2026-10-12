<?php

use App\Models\Alarm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// F1, F2 and the L7 safety net: every api/* answer is JSON with the right status, never a 500
// because the request lacked "Accept: application/json", never the SPA's HTML shell, and never a
// 500 because a filter value PostgreSQL can't read slipped past validation.

it('answers 401 JSON to a protected API route requested without Accept: application/json (F1)', function () {
    $response = $this->get('/api/user');

    $response->assertUnauthorized();
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('message'))->toBe('Unauthenticated.');
});

it('still answers 401 JSON with Accept: application/json (F1)', function () {
    $this->getJson('/api/schedule?month=2026-10')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

it('answers 404 JSON to an unknown API path instead of the SPA shell (F2)', function () {
    $this->actingAs(User::factory()->create());

    foreach (['/api/no-such-endpoint', '/api/procedures/1/no-such-tab', '/api'] as $path) {
        $response = $this->get($path);
        $response->assertNotFound();
        expect($response->headers->get('Content-Type'))->toContain('application/json');
    }
});

it('still serves the SPA shell for app routes (F2)', function () {
    // CI has no built frontend, so there is no Vite manifest to render.
    $this->withoutVite();

    $this->get('/schedule/2026-10')->assertOk()->assertViewIs('app');
    $this->get('/apiary')->assertOk()->assertViewIs('app');
});

it('answers 404 to an alarm id that is not a uuid (L7)', function () {
    $this->actingAs(User::factory()->create())
        ->putJson('/api/alarms/not-a-uuid/read')
        ->assertNotFound();
});

it('answers 404 to a non-numeric user id in the path (L7)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->getJson('/api/newbie-periods/user/abc')->assertNotFound();
    $this->actingAs($admin)->getJson('/api/user-availabilities/abc/month?month=2026-10')->assertNotFound();
});

it('renders an unreadable filter value on a GET as 422, not 500 (L7 safety net)', function () {
    // A route that forwards a raw value to PostgreSQL, as a missed filter would.
    Route::middleware('api')->get('/api/__test-unvalidated', function (Request $request) {
        return response()->json(DB::table('users')->where('id', $request->input('id'))->get());
    });

    $this->getJson('/api/__test-unvalidated?id=abc')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'One of the values sent is not valid.');
});

it('renders an out-of-range number on a GET as 422, not 500 (review M1)', function () {
    // shift_number is a 4-byte integer column: 3000000000 can't be compared with it (22003).
    Route::middleware('api')->get('/api/__test-out-of-range', function (Request $request) {
        return response()->json(DB::table('shifts')->where('shift_number', $request->input('n'))->get());
    });

    $this->getJson('/api/__test-out-of-range?n=3000000000')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'One of the values sent is not valid.');
});
