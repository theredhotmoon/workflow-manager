<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

// M24: every /api route, walked from the router, against the access rules of its group.
//
// - The four lists below are the reviewed snapshot of which group each route is in. Adding,
//   removing or moving a route fails the first test until the list is updated, so a route that
//   lands outside the admin groups (open to every logged-in user) shows up in review.
// - Guests get 401 everywhere but login. Admin-only routes answer 403 to employees and schedule
//   maintainers; admin:schedule routes 403 to employees. Allowed roles get past the middleware
//   (any status but 401/403: 404 for the dummy id, 422 for the empty body, 200...).
/// - Route parameters are filled with a dummy id (999999). App/Http/Kernel runs the active/role
//   middleware before route-model binding, so a denied role gets 403 even for a missing record
//   (without that, binding answered 404 first and let callers probe which ids exist).
//
// Object-level rules (whose handover, whose procedure) are tested where they live:
// HandoverAccessTest (M1/M2: filing, history scoping), PrivilegeGapsTest (H1 confirmation, H2
// procedure move/change-owner, own-handover filing), ProcedureEditingTest (procedure writes),
// PrivacyNeedToKnowTest (L3: a procedure's access log is for admins and its owner only).

function rpmExpected(): array
{
    return [
        // No session needed.
        'public' => [
            'POST /api/login',
        ],
        // Open to every logged-in, active user, whatever their role.
        'authenticated' => [
            'DELETE /api/delegations/{delegation}/self-unregister',
            'DELETE /api/procedure-faq-replies/{reply}',
            'DELETE /api/procedure-faq/{item}',
            'DELETE /api/user-availabilities/{userAvailability}',
            'GET /api/alarms',
            'GET /api/alarms/latest',
            'GET /api/alarms/unread-count',
            'GET /api/checklist-submissions',
            'GET /api/checklist-submissions/export',
            'GET /api/checklist-submissions/pending-confirmations',
            'GET /api/checklist-submissions/{checklistSubmission}',
            'GET /api/checklist-templates/active',
            'GET /api/current-shift',
            'GET /api/delegations/open',
            'GET /api/employees',
            'GET /api/my-checklist-alerts',
            'GET /api/my-delegations',
            'GET /api/my-shifts',
            'GET /api/procedure-tags',
            'GET /api/procedures',
            'GET /api/procedures-to-learn',
            'GET /api/procedures-tree',
            'GET /api/procedures/{procedure}',
            'GET /api/procedures/{procedure}/access-log',
            'GET /api/procedures/{procedure}/faq',
            'GET /api/procedures/{procedure}/templates',
            'GET /api/procedures/{procedure}/versions',
            'GET /api/procedures/{procedure}/versions/{version}',
            'GET /api/schedule',
            'GET /api/shift-change-requests/mine',
            'GET /api/shift-colors',
            'GET /api/shifts-by-date',
            'GET /api/shifts/{shift}',
            'GET /api/user',
            'GET /api/user-availabilities',
            'GET /api/user-availabilities/{userId}/month',
            'GET /api/user-groups',
            'POST /api/checklist-submissions',
            'POST /api/delegations/{delegation}/self-register',
            'POST /api/logout',
            'POST /api/procedure-faq/{item}/replies',
            'POST /api/procedures/check-read-status',
            'POST /api/procedures/{procedure}/faq',
            'POST /api/procedures/{procedure}/feedback',
            'POST /api/procedures/{procedure}/mark-read',
            'POST /api/shift-change-requests',
            'POST /api/user-availabilities',
            'PUT /api/alarms/read-all',
            'PUT /api/alarms/{id}/read',
            'PUT /api/change-my-password',
            'PUT /api/checklist-submissions/{checklistSubmission}/confirm',
            'PUT /api/procedure-faq-replies/{reply}/toggle-outdated',
            'PUT /api/procedure-faq/{item}/toggle-outdated',
        ],
        // middleware('admin')
        'admin' => [
            'DELETE /api/checklist-templates/{checklist_template}',
            'DELETE /api/delegations/{delegation}',
            'DELETE /api/newbie-periods/{newbiePeriod}',
            'DELETE /api/office-closures/{officeClosure}',
            'DELETE /api/procedure-tags/{procedureTag}',
            'DELETE /api/procedure-templates/{template}',
            'DELETE /api/procedures/{procedure}',
            'DELETE /api/procedures/{procedure}/draft',
            'DELETE /api/shift-colors/{shiftColor}',
            'DELETE /api/shift-groups/{shiftGroup}',
            'DELETE /api/user-groups/{userGroup}',
            'GET /api/checklist-templates/{checklist_template}',
            'GET /api/delegation-archive',
            'GET /api/delegation-archive/destinations',
            'GET /api/delegation-archive/{archivedDelegation}/logs',
            'GET /api/delegations',
            'GET /api/delegations/{delegation}/logs',
            'GET /api/newbie-periods/all',
            'GET /api/newbie-periods/user/{userId}',
            'GET /api/procedure-feedbacks',
            'GET /api/users',
            'GET /api/users/{user}',
            'PATCH /api/checklist-templates/{checklist_template}',
            'PATCH /api/user-groups/{userGroup}',
            'PATCH /api/users/{user}',
            'POST /api/checklist-templates',
            'POST /api/delegation-archive/{archivedDelegation}/unarchive',
            'POST /api/delegations',
            'POST /api/delegations/{delegation}/add-user',
            'POST /api/delegations/{delegation}/approve-user',
            'POST /api/delegations/{delegation}/archive',
            'POST /api/delegations/{delegation}/reject-user',
            'POST /api/delegations/{delegation}/remove-user',
            'POST /api/newbie-periods',
            'POST /api/office-closures',
            'POST /api/procedure-tags',
            'POST /api/procedures',
            'POST /api/procedures/{procedure}/publish',
            'POST /api/procedures/{procedure}/restore',
            'POST /api/procedures/{procedure}/templates',
            'POST /api/shift-colors',
            'POST /api/shift-colors/reorder',
            'POST /api/shift-groups',
            'POST /api/shift-groups/reorder',
            'POST /api/shift-groups/{shiftGroup}/restore',
            'POST /api/user-groups',
            'POST /api/users',
            'POST /api/users/{user}/deactivate',
            'POST /api/users/{user}/reactivate',
            'PUT /api/checklist-submissions/{checklistSubmission}/incoming',
            'PUT /api/checklist-templates/{checklist_template}',
            'PUT /api/delegations/{delegation}',
            'PUT /api/delegations/{delegation}/user-dates',
            'PUT /api/newbie-periods/{newbiePeriod}',
            'PUT /api/office-closures/{officeClosure}',
            'PUT /api/procedure-feedbacks/{feedback}',
            'PUT /api/procedure-tags/{procedureTag}',
            'PUT /api/procedure-templates/{template}',
            'PUT /api/procedures/{procedure}',
            'PUT /api/procedures/{procedure}/change-owner',
            'PUT /api/procedures/{procedure}/draft',
            'PUT /api/procedures/{procedure}/move',
            'PUT /api/procedures/{procedure}/tags',
            'PUT /api/shift-colors/{shiftColor}',
            'PUT /api/shift-groups/{shiftGroup}',
            'PUT /api/shift-groups/{shiftGroup}/streak-rules',
            'PUT /api/user-groups/{userGroup}',
            'PUT /api/user-groups/{userGroup}/users',
            'PUT /api/users/{user}',
            'PUT /api/users/{user}/password',
        ],
        // middleware('admin:schedule'): admins and schedule maintainers.
        'schedule' => [
            'DELETE /api/on-call-assignments/{onCallAssignment}',
            'DELETE /api/on-call-callbacks/{onCallCallback}',
            'DELETE /api/schedule/clear-month',
            'DELETE /api/schedule/unassign/{assignment}',
            'GET /api/checklist-templates',
            'GET /api/delegations/month',
            'GET /api/newbie-periods/month',
            'GET /api/office-closures',
            'GET /api/office-closures/conflicts',
            'GET /api/on-call-assignments',
            'GET /api/settlement-periods',
            'GET /api/shift-change-requests',
            'GET /api/shift-groups',
            'POST /api/on-call-assignments',
            'POST /api/on-call-assignments/{onCallAssignment}/callbacks',
            'POST /api/schedule/assign',
            'POST /api/schedule/auto-assign/polish-law',
            'POST /api/schedule/auto-assign/random',
            'POST /api/schedule/auto-assign/rotating',
            'POST /api/schedule/generate',
            'PUT /api/schedule/day-shifts',
            'PUT /api/schedule/shift-types',
            'PUT /api/schedule/shifts/{shift}/checklist',
            'PUT /api/schedule/shifts/{shift}/color',
            'PUT /api/schedule/shifts/{shift}/times',
            'PUT /api/schedule/shifts/{shift}/type',
            'PUT /api/schedule/shifts/{shift}/work-mode',
            'PUT /api/shift-change-requests/{id}/acknowledge',
        ],
    ];
}

/** The group each api route is in, from its middleware: [group => ["METHOD /uri", ...]]. */
function rpmActual(): array
{
    $groups = ['public' => [], 'authenticated' => [], 'admin' => [], 'schedule' => []];
    foreach (Route::getRoutes() as $route) {
        if (!str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $group = match (true) {
            in_array('admin', $middleware, true) => 'admin',
            in_array('admin:schedule', $middleware, true) => 'schedule',
            in_array('auth:sanctum', $middleware, true) => 'authenticated',
            default => 'public',
        };
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $groups[$group][] = "{$method} /{$route->uri()}";
        }
    }

    return array_map(fn ($list) => collect($list)->sort()->values()->all(), $groups);
}

/**
 * [method, uri, route] triples of a group as reviewed (rpmExpected, not the live router: a route
 * that slips out of its group is still walked with its intended rules), parameters filled with a
 * dummy id.
 */
function rpmRequests(string $group): array
{
    return collect(rpmExpected()[$group])->map(function ($route) {
        [$method, $uri] = explode(' ', $route, 2);

        return [$method, preg_replace('/\{[^}]+\}/', '999999', $uri), $route];
    })->all();
}

beforeEach(function () {
    // One user walks ~100 routes; keep the per-minute API limit out of the way.
    config(['app.api_rate_limit' => 100000]);
});

it('keeps every api route in its reviewed access group', function () {
    $expected = array_map(fn ($list) => collect($list)->sort()->values()->all(), rpmExpected());
    $actual = rpmActual();

    foreach ($expected as $group => $routes) {
        $added = array_values(array_diff($actual[$group], $routes));
        $removed = array_values(array_diff($routes, $actual[$group]));
        expect(['added' => $added, 'removed' => $removed])
            ->toBe(['added' => [], 'removed' => []], "Routes in group '{$group}' changed; review and update rpmExpected().");
    }
});

it('puts every route but login behind a session and an active account', function () {
    foreach (Route::getRoutes() as $route) {
        if (!str_starts_with($route->uri(), 'api/') || $route->uri() === 'api/login') {
            continue;
        }
        expect($route->gatherMiddleware())->toContain('auth:sanctum', 'active');
    }
});

it('answers 401 to a guest on every route but login', function () {
    foreach (['authenticated', 'admin', 'schedule'] as $group) {
        foreach (rpmRequests($group) as [$method, $uri, $route]) {
            $status = test()->json($method, $uri)->getStatusCode();
            expect($status)->toBe(401, "{$route} answered {$status} to a guest");
        }
    }
});

it('enforces the admin and admin:schedule groups on every route', function (string $role, bool $admin, bool $schedule) {
    $user = User::factory()->create(['role' => $role]);

    foreach (['admin' => $admin, 'schedule' => $schedule] as $group => $allowed) {
        foreach (rpmRequests($group) as [$method, $uri, $route]) {
            $status = test()->actingAs($user)->json($method, $uri)->getStatusCode();
            if ($allowed) {
                expect($status)->not->toBeIn([401, 403], "{$route} refused a {$role} ({$status})");
            } else {
                expect($status)->toBe(403, "{$route} answered {$status} to a {$role}");
            }
        }
    }
})->with([
    'employee' => ['employee', false, false],
    'schedule maintainer' => ['schedule_maintainer', false, true],
    'admin' => ['admin', true, true],
]);

it('lets every role past the middleware of the routes open to all', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    foreach (rpmRequests('authenticated') as [$method, $uri, $route]) {
        if ($route === 'POST /api/logout') {
            continue; // would end the session the rest of the walk runs on
        }
        $status = test()->actingAs($user)->json($method, $uri)->getStatusCode();
        expect($status)->not->toBe(401, "{$route} answered 401 to a {$role}");
    }
})->with(['employee', 'schedule_maintainer', 'admin']);
