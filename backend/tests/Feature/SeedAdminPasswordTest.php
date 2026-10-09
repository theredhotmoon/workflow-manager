<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\E2eBaselineSeeder;
use Database\Seeders\ScheduleMaintainerSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Sets (or, with null, removes) SEED_ADMIN_PASSWORD everywhere env() looks. */
function setSeedAdminPassword(?string $value): void
{
    if ($value === null) {
        unset($_ENV['SEED_ADMIN_PASSWORD'], $_SERVER['SEED_ADMIN_PASSWORD']);
        putenv('SEED_ADMIN_PASSWORD');

        return;
    }
    $_ENV['SEED_ADMIN_PASSWORD'] = $value;
    $_SERVER['SEED_ADMIN_PASSWORD'] = $value;
    putenv("SEED_ADMIN_PASSWORD={$value}");
}

const DEMO_EMAILS = [
    'regular@shiftchanger.com',
    'test2@shiftchanger.com',
    'test@shiftchanger.com',
    'marcin@shiftchanger.com',
    'nme@asd.com',
    'schedule@company.com',
];

beforeEach(function () {
    $this->originalEnv = $this->app['env'];
    setSeedAdminPassword(null);
});

afterEach(function () {
    $this->app['env'] = $this->originalEnv;
    setSeedAdminPassword(null);
});

it('refuses to seed in production without SEED_ADMIN_PASSWORD and creates nothing', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new DatabaseSeeder())->run())
        ->toThrow(RuntimeException::class, 'SEED_ADMIN_PASSWORD');

    expect(User::count())->toBe(0);
});

it('refuses a SEED_ADMIN_PASSWORD shorter than 12 characters in production', function () {
    $this->app['env'] = 'production';
    setSeedAdminPassword('elevenchars');

    expect(fn () => (new DatabaseSeeder())->run())
        ->toThrow(RuntimeException::class, 'at least 12 characters');

    expect(User::count())->toBe(0);
});

it('refuses a published development password as SEED_ADMIN_PASSWORD in production', function (string $published) {
    $this->app['env'] = 'production';
    setSeedAdminPassword($published);

    expect(fn () => (new DatabaseSeeder())->run())->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
})->with(['1234', 'password123']);

it('applies the same rule to any environment other than local and testing', function () {
    $this->app['env'] = 'staging';

    expect(fn () => (new DatabaseSeeder())->run())->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
});

it('seeds the admin with SEED_ADMIN_PASSWORD in production, and no demo users', function () {
    $this->app['env'] = 'production';
    setSeedAdminPassword('a-long-production-secret');

    (new DatabaseSeeder())->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->role)->toBe('admin');
    expect(Hash::check('a-long-production-secret', $admin->password))->toBeTrue();
    expect(Hash::check('1234', $admin->password))->toBeFalse();
    expect(User::whereIn('email', DEMO_EMAILS)->count())->toBe(0);
    expect(User::count())->toBe(1);
});

it('accepts a password of exactly 12 characters in production', function () {
    $this->app['env'] = 'production';
    setSeedAdminPassword('twelve-chars');

    (new DatabaseSeeder())->run();

    expect(Hash::check('twelve-chars', User::where('email', 'admin@shiftchanger.com')->value('password')))
        ->toBeTrue();
});

it('refuses the demo-user seeders outside local and testing', function (string $seeder) {
    $this->app['env'] = 'production';
    setSeedAdminPassword('a-long-production-secret');

    expect(fn () => (new $seeder())->run())
        ->toThrow(RuntimeException::class, 'only runs when APP_ENV is local or testing');

    // E2eBaselineSeeder checks before calling DatabaseSeeder, so not even the admin is left behind.
    expect(User::count())->toBe(0);
})->with([UsersSeeder::class, ScheduleMaintainerSeeder::class, E2eBaselineSeeder::class]);

it('does not reset an existing admin password when re-seeded', function () {
    $this->app['env'] = 'production';
    setSeedAdminPassword('a-long-production-secret');
    (new DatabaseSeeder())->run();

    setSeedAdminPassword('a-different-long-secret');
    (new DatabaseSeeder())->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect(User::where('email', 'admin@shiftchanger.com')->count())->toBe(1);
    expect(Hash::check('a-long-production-secret', $admin->password))->toBeTrue();
});

it('re-seeds production without SEED_ADMIN_PASSWORD when the admin already exists', function () {
    $this->app['env'] = 'production';
    setSeedAdminPassword('a-long-production-secret');
    (new DatabaseSeeder())->run();
    setSeedAdminPassword(null);

    (new DatabaseSeeder())->run();

    expect(Hash::check('a-long-production-secret', User::where('email', 'admin@shiftchanger.com')->value('password')))
        ->toBeTrue();
});

it('warns on a production re-seed when the existing admin still has the password 1234', function () {
    User::factory()->create(['email' => 'admin@shiftchanger.com', 'role' => 'admin', 'password' => Hash::make('1234')]);
    $this->app['env'] = 'production';
    Log::spy();

    (new DatabaseSeeder())->run();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'still has the published default password'))
        ->once();
    expect(Hash::check('1234', User::where('email', 'admin@shiftchanger.com')->value('password')))->toBeTrue();
});

it('does not warn about 1234 in local and testing', function () {
    User::factory()->create(['email' => 'admin@shiftchanger.com', 'role' => 'admin', 'password' => Hash::make('1234')]);
    $this->app['env'] = 'local';
    Log::spy();

    (new DatabaseSeeder())->run();

    Log::shouldNotHaveReceived('warning');
});

it('keeps the dev defaults in local and testing: admin with 1234 and the demo users', function (string $env) {
    $this->app['env'] = $env;

    (new E2eBaselineSeeder())->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect(Hash::check('1234', $admin->password))->toBeTrue();
    expect(User::whereIn('email', DEMO_EMAILS)->count())->toBe(count(DEMO_EMAILS));

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->postJson('/api/login', ['email' => 'admin@shiftchanger.com', 'password' => '1234'])->assertOk();
})->with(['local', 'testing']);

it('lets SEED_ADMIN_PASSWORD override the dev default locally', function () {
    $this->app['env'] = 'local';
    setSeedAdminPassword('my-local-pass');

    (new DatabaseSeeder())->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect(Hash::check('my-local-pass', $admin->password))->toBeTrue();
    expect(Hash::check('1234', $admin->password))->toBeFalse();
});
