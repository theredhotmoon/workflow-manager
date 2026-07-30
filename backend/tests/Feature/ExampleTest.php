<?php

use Illuminate\Support\Facades\Schema;

it('migrates the full schema into the test database', function () {
    expect(Schema::hasTable('users'))->toBeTrue();
});
