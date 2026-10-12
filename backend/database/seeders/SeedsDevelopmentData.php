<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * For seeders whose users have published passwords (README, e2e fixtures): they may only run in
 * the local dev stack and in tests, never against a real deployment.
 */
trait SeedsDevelopmentData
{
    protected function isDevelopmentEnvironment(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** @throws RuntimeException outside local/testing, before anything is written */
    protected function refuseOutsideDevelopment(): void
    {
        if (! $this->isDevelopmentEnvironment()) {
            throw new RuntimeException(sprintf(
                '%s seeds demo users with published passwords, so it only runs when APP_ENV is local or testing (APP_ENV is "%s"). Nothing was seeded.',
                class_basename(static::class),
                app()->environment()
            ));
        }
    }
}
