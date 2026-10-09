<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ScheduleMaintainerSeeder extends Seeder
{
    public function run(): void
    {
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
