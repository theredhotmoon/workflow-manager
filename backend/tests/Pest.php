<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * A second database session, outside the test's RefreshDatabase transaction: what a concurrent
 * request would be. Used to hold a lock while the code under test tries to take it.
 */
function sideConnection(): ConnectionInterface
{
    config(['database.connections.pgsql_side' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_side');
}
