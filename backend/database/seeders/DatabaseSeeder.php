<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use SeedsDevelopmentData;

    public const ADMIN_EMAIL = 'admin@shiftchanger.com';

    /** The published dev/e2e admin password; only ever used when APP_ENV is local or testing. */
    public const DEVELOPMENT_ADMIN_PASSWORD = '1234';

    /** The published password of the demo users (UsersSeeder). */
    public const DEMO_USER_PASSWORD = 'password123';

    public const MIN_ADMIN_PASSWORD_LENGTH = 12;

    /**
     * Seed the application's database: the first admin account.
     *
     * Outside local/testing the password must come from SEED_ADMIN_PASSWORD (at least 12
     * characters); without it the seeder throws before writing anything. Idempotent: an existing
     * admin@shiftchanger.com is left as it is (its password is never reset by a re-seed), and then
     * no SEED_ADMIN_PASSWORD is needed.
     *
     * @return void
     */
    public function run()
    {
        $existing = User::where('email', self::ADMIN_EMAIL)->first();
        if ($existing !== null) {
            $this->warnIfStillPublishedPassword($existing);

            return;
        }

        User::create([
            'email'    => self::ADMIN_EMAIL,
            'name'     => 'Admin User',
            'password' => Hash::make($this->adminPassword()),
            'role'     => 'admin',
        ]);
    }

    /**
     * A deployment seeded before SEED_ADMIN_PASSWORD existed got the admin with 1234. A re-seed
     * doesn't change it (that would lock out whoever uses it), but says so loudly.
     */
    private function warnIfStillPublishedPassword(User $admin): void
    {
        if ($this->isDevelopmentEnvironment() || !Hash::check(self::DEVELOPMENT_ADMIN_PASSWORD, $admin->password)) {
            return;
        }

        $message = sprintf(
            '%s still has the published default password "%s". Change it now (log in, Change password).',
            self::ADMIN_EMAIL,
            self::DEVELOPMENT_ADMIN_PASSWORD
        );
        Log::warning($message);
        if ($this->command !== null) {
            $this->command->warn($message);
        }
    }

    /** @throws RuntimeException outside local/testing when SEED_ADMIN_PASSWORD is missing or short */
    private function adminPassword(): string
    {
        // env(), not config(): this is read once by a one-off `artisan db:seed`, and a config value
        // would be frozen by the container's `config:cache` at start, so a password passed with
        // `docker compose exec -e SEED_ADMIN_PASSWORD=... backend php artisan db:seed` would be missed.
        $fromEnv = env('SEED_ADMIN_PASSWORD');
        $fromEnv = is_string($fromEnv) ? $fromEnv : '';

        if ($this->isDevelopmentEnvironment()) {
            return $fromEnv !== '' ? $fromEnv : self::DEVELOPMENT_ADMIN_PASSWORD;
        }

        if (mb_strlen($fromEnv) < self::MIN_ADMIN_PASSWORD_LENGTH) {
            throw new RuntimeException(sprintf(
                'Refusing to seed %s with a default password (APP_ENV is "%s"). Set SEED_ADMIN_PASSWORD to a password of at least %d characters and run the seeder again. Nothing was seeded.',
                self::ADMIN_EMAIL,
                app()->environment(),
                self::MIN_ADMIN_PASSWORD_LENGTH
            ));
        }

        // Both are shorter than the minimum today; checked by name too so lowering the minimum
        // can never let a published password through.
        foreach ([self::DEVELOPMENT_ADMIN_PASSWORD, self::DEMO_USER_PASSWORD] as $published) {
            if (hash_equals($published, $fromEnv)) {
                throw new RuntimeException(sprintf(
                    'Refusing to seed %s: SEED_ADMIN_PASSWORD is a published development password. Choose another one. Nothing was seeded.',
                    self::ADMIN_EMAIL
                ));
            }
        }

        return $fromEnv;
    }
}
