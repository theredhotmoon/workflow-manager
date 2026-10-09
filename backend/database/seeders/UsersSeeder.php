<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UsersSeeder extends Seeder
{
    use SeedsDevelopmentData;

    public function run()
    {
        $this->refuseOutsideDevelopment();

        $users = [
            ['name' => 'John 22', 'email' => 'nme@asd.com', 'role' => 'employee'],
            ['name' => 'Regular Employee', 'email' => 'regular@shiftchanger.com', 'role' => 'employee'],
            ['name' => 'Test Employee 2', 'email' => 'test2@shiftchanger.com', 'role' => 'employee'],
            ['name' => 'Test Employee Updated', 'email' => 'test@shiftchanger.com', 'role' => 'employee'],
            ['name' => 'Marcin Kefir', 'email' => 'marcin@shiftchanger.com', 'role' => 'employee'],
        ];

        foreach ($users as $u) {
            User::firstOrCreate(
                ['email' => $u['email']],
                array_merge($u, ['password' => Hash::make(DatabaseSeeder::DEMO_USER_PASSWORD)])
            );
        }

        echo "Created/verified " . count($users) . " users\n";
    }
}
