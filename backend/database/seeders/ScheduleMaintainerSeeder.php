<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ScheduleMaintainerSeeder extends Seeder
{
    use SeedsDevelopmentData;

    public function run(): void
    {
        $this->refuseOutsideDevelopment();

        User::firstOrCreate(
            ['email' => 'schedule@company.com'],
            [
                'name' => 'Schedule Maintainer',
                'password' => Hash::make('schedule123'),
                'role' => 'schedule_maintainer',
            ]
        );
    }
}
