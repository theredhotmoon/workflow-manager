<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class E2eBaselineSeeder extends Seeder
{
    use SeedsDevelopmentData;

    public function run(): void
    {
        // Checked up front so a refused run doesn't leave the admin seeded without the rest.
        $this->refuseOutsideDevelopment();

        $this->call([
            DatabaseSeeder::class,
            UsersSeeder::class,
            ScheduleMaintainerSeeder::class,
        ]);
    }
}
