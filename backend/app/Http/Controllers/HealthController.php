<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness probe for the container healthcheck and CI: 200 only when PHP-FPM answers and the
 * database accepts a query, 503 otherwise. Nginx's static /healthz only proves Nginx is up.
 * Says nothing about why the database is unreachable (the response is public).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable', 'database' => 'down'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'up']);
    }
}
