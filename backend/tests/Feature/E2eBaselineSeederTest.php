<?php

use App\Models\User;
use Database\Seeders\E2eBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an admin, a plain employee, and a schedule maintainer', function () {
    (new E2eBaselineSeeder)->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->role)->toBe('admin');

    $employee = User::where('email', 'regular@shiftchanger.com')->first();
    expect($employee)->not->toBeNull();
    expect($employee->role)->toBe('employee');

    $maintainer = User::where('email', 'schedule@company.com')->first();
    expect($maintainer)->not->toBeNull();
    expect($maintainer->role)->toBe('schedule_maintainer');
});

it('is idempotent when run twice', function () {
    (new E2eBaselineSeeder)->run();
    (new E2eBaselineSeeder)->run();

    expect(User::where('email', 'admin@shiftchanger.com')->count())->toBe(1);
});
