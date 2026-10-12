<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Readiness probe for the container healthcheck and CI: 200 only when PHP-FPM answers and the
 * database accepts a query, 503 otherwise. Nginx's static /healthz only proves Nginx is up.
 * Says nothing about why the database is unreachable (the response is public).
 */
class HealthController extends Controller
{
    /** Seconds to wait for a database connection before answering 503. */
    public const CONNECT_TIMEOUT = 3;

    public function __invoke(): JsonResponse
    {
        // Its own connection, a copy of the default one with a short connect timeout: an
        // unreachable (black-holed) database would otherwise hold a php-fpm worker for the OS's TCP
        // timeout (minutes) on every probe, and the probes alone could use up the worker pool.
        $default = config('database.default');
        config(['database.connections.health' => array_replace(
            config("database.connections.{$default}"),
            ['options' => [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]],
        )]);

        try {
            DB::connection('health')->select('select 1');
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable', 'database' => 'down'], 503);
        } finally {
            DB::purge('health');
        }

        return response()->json(['status' => 'ok', 'database' => 'up']);
    }
}
