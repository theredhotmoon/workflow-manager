<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UsersSeeder extends Seeder
{
    public function run()
    {
        $users = [
            ['name' => 'John 22', 'email' => 'nme@asd.com', 'is_admin' => false],
            ['name' => 'Regular Employee', 'email' => 'regular@shiftchanger.com', 'is_admin' => false],
            ['name' => 'Test Employee 2', 'email' => 'test2@shiftchanger.com', 'is_admin' => false],
            ['name' => 'Test Employee Updated', 'email' => 'test@shiftchanger.com', 'is_admin' => false],
            ['name' => 'Marcin Kefir', 'email' => 'marcin@shiftchanger.com', 'is_admin' => false],
        ];

        foreach ($users as $u) {
            User::firstOrCreate(
                ['email' => $u['email']],
                array_merge($u, ['password' => Hash::make('password123')])
            );
        }

        echo "Created/verified " . count($users) . " users\n";
    }
}
