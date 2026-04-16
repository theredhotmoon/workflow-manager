<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     * Usage:
     *   middleware('admin')          → admin only
     *   middleware('admin:schedule') → admin OR schedule_maintainer
     */
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($scope === 'schedule') {
            if (!$user->canManageSchedule()) {
                return response()->json(['message' => 'Unauthorized. Admin or Schedule Maintainer access required.'], 403);
            }
        } else {
            if (!$user->isAdmin()) {
                return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
            }
        }

        return $next($request);
    }
}
