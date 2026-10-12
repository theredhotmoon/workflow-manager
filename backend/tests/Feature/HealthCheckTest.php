<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\DB;

// L35: the readiness probe behind the container healthcheck and the CI wait.

it('answers 200 when the database accepts a query', function () {
    $this->get('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'up']);
});

it('answers 503 without details when the database is unreachable', function () {
    // A default connection that refuses (nothing listens on port 1). The suite's own connection,
    // which holds the RefreshDatabase transaction, is put back before the test ends.
    $default = config('database.default');
    config([
        'database.connections.unreachable' => array_merge(config("database.connections.{$default}"), [
            'host' => '127.0.0.1',
            'port' => 1,
        ]),
        'database.default' => 'unreachable',
    ]);

    try {
        $response = $this->get('/api/health');
    } finally {
        config(['database.default' => $default]);
        DB::purge('unreachable');
    }

    $response->assertStatus(503)->assertExactJson(['status' => 'unavailable', 'database' => 'down']);
    expect($response->getContent())->not->toContain('SQLSTATE');
});

it('needs no session or login and is not held to the per-user API limit', function () {
    config(['app.api_rate_limit' => 2]);

    foreach (range(1, 5) as $attempt) {
        $this->get('/api/health')->assertOk()->assertCookieMissing(config('session.cookie'));
    }
});

it('has its own generous per-IP limit', function () {
    foreach (range(1, 60) as $attempt) {
        $this->get('/api/health')->assertOk();
    }

    $this->get('/api/health')->assertStatus(429);
});

it('connects with a short timeout, so an unreachable database answers 503 quickly', function () {
    $this->get('/api/health')->assertOk();

    expect(config('database.connections.health.options'))
        ->toBe([PDO::ATTR_TIMEOUT => HealthController::CONNECT_TIMEOUT])
        ->and(HealthController::CONNECT_TIMEOUT)->toBeLessThanOrEqual(5);
});
