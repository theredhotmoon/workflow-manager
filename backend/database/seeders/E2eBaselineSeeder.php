<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class E2eBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DatabaseSeeder::class,
            UsersSeeder::class,
            ScheduleMaintainerSeeder::class,
        ]);
    }
}
