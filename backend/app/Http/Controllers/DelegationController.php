<?php

namespace App\Http\Controllers;

use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Month;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DelegationController extends Controller
{
    private function log(int $delegationId, int $userId, string $action, ?string $details = null): void
    {
        DelegationLog::create([
            'delegation_id' => $delegationId,
            'performed_by' => $userId,
            'action' => $action,
            'details' => $details,
        ]);
    }

    /**
     * The delegations (with users), one page at a time (per_page 1..100, default 20) — admin.
     * Newest start first; the id breaks ties, so pages never overlap or skip a delegation.
     */
    public function index(Request $request)
    {
        $request->validate(['per_page' => ['sometimes', 'nullable', 'integer']]);
        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        return response()->json(Delegation::with(['users:id,name,email', 'creator:id,name'])
            ->orderByDesc('start_datetime')
            ->orderByDesc('id')
            ->paginate($perPage));
    }

    /**
     * Get logs for a delegation — admin.
     */
    public function logs(Delegation $delegation)
    {
        $logs = $delegation->logs()->with('performer:id,name')->get();

        return response()->json($logs);
    }

    /**
     * Create delegation — admin.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_datetime' => ['required', 'date'],
            // Whole days, last day included: a one-day delegation starts and ends on the same date.
            'end_datetime' => ['required', 'date', 'after_or_equal:start_datetime'],
            'registration_open' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', User::activeExistsRule()],
        ]);

        $delegation = Delegation::create([
            'name' => $validated['name'],
            'destination' => $validated['destination'],
            'description' => $validated['description'] ?? null,
            'start_datetime' => self::dateOnly($validated['start_datetime']),
            'end_datetime' => self::dateOnly($validated['end_datetime']),
            'registration_open' => $validated['registration_open'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        $this->log($delegation->id, $request->user()->id, 'created', "Created delegation \"{$delegation->name}\"");

        if (! empty($validated['user_ids'])) {
            foreach ($validated['user_ids'] as $uid) {
                $delegation->users()->attach($uid, ['status' => 'approved']);
                $userName = User::find($uid)?->name ?? $uid;
                $this->log($delegation->id, $request->user()->id, 'user_added', "Added {$userName} (approved)");
            }
        }

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation, 201);
    }

    /**
     * Update delegation — admin.
     */
    public function update(Request $request, Delegation $delegation)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'destination' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_datetime' => ['sometimes', 'date'],
            'end_datetime' => ['sometimes', 'date'],
            'registration_open' => ['sometimes', 'boolean'],
        ]);

        foreach (['start_datetime', 'end_datetime'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] = self::dateOnly($validated[$key]);
            }
        }

        // Either date may change alone, so compare the dates the delegation will end up with.
        $start = $validated['start_datetime'] ?? $delegation->start_datetime?->format('Y-m-d');
        $end = $validated['end_datetime'] ?? $delegation->end_datetime?->format('Y-m-d');
        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages([
                'end_datetime' => 'The end date must be on or after the start date.',
            ]);
        }

        // Only what really changes is logged: dates compared as dates (the stored value carries a
        // midnight time), text with an empty value the same as none.
        $changes = [];
        foreach ($validated as $key => $value) {
            switch ($key) {
                case 'registration_open':
                    if ((bool) $value !== (bool) $delegation->registration_open) {
                        $changes[] = $value ? 'Registration opened' : 'Registration closed';
                    }
                    break;
                case 'start_datetime':
                case 'end_datetime':
                    $old = $delegation->$key?->format('Y-m-d');
                    if ($old !== $value) {
                        $label = $key === 'start_datetime' ? 'Start date' : 'End date';
                        $changes[] = "{$label} changed ({$old} → {$value})";
                    }
                    break;
                default:
                    if ((string) ($delegation->$key ?? '') !== (string) ($value ?? '')) {
                        $changes[] = ucfirst($key).' changed';
                    }
            }
        }

        $datesChanged = ($validated['start_datetime'] ?? null) !== null && $validated['start_datetime'] !== $delegation->start_datetime?->format('Y-m-d')
            || ($validated['end_datetime'] ?? null) !== null && $validated['end_datetime'] !== $delegation->end_datetime?->format('Y-m-d');

        // The edit, its log and the members' travel dates that no longer fit go in together. Locked
        // like add-member, so a member added or re-dated at the same moment is seen and clamped too.
        $adjusted = DB::transaction(function () use ($request, $delegation, $validated, $changes, $datesChanged) {
            Delegation::lockOrGone($delegation->id);
            $delegation->update($validated);

            if ($changes) {
                $this->log($delegation->id, $request->user()->id, 'updated', implode('; ', $changes));
            }

            return $datesChanged ? $this->clampMemberDates($delegation, $request->user()->id) : 0;
        });

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json(array_merge($delegation->toArray(), ['adjusted_members' => $adjusted]));
    }

    /**
     * Moves every member's own travel dates into the delegation's (new) days: each end is clamped
     * into the range, an unset end stays unset (it means the delegation's own day). A member whose
     * dates no longer overlap the delegation at all goes back to the delegation's dates. Each change
     * is logged; returns how many members changed.
     */
    private function clampMemberDates(Delegation $delegation, int $performedBy): int
    {
        $first = $delegation->start_datetime->format('Y-m-d');
        $last = $delegation->end_datetime->format('Y-m-d');
        $day = fn ($value): ?string => $value === null ? null : substr((string) $value, 0, 10);
        $range = fn (?string $start, ?string $end): string => ($start ?? 'start').' to '.($end ?? 'end');

        $members = $delegation->users()
            ->where(fn ($q) => $q->whereNotNull('delegation_user.custom_start')->orWhereNotNull('delegation_user.custom_end'))
            ->orderBy('users.id')
            ->get(['users.id', 'users.name']);

        $adjusted = 0;
        foreach ($members as $member) {
            $start = $day($member->pivot->custom_start);
            $end = $day($member->pivot->custom_end);
            $from = max($start ?? $first, $first);
            $to = min($end ?? $last, $last);
            $reset = $from > $to;
            $newStart = $reset || $start === null ? null : $from;
            $newEnd = $reset || $end === null ? null : $to;
            if ($newStart === $start && $newEnd === $end) {
                continue;
            }

            $delegation->users()->updateExistingPivot($member->id, ['custom_start' => $newStart, 'custom_end' => $newEnd]);
            $this->log($delegation->id, $performedBy, 'dates_adjusted', $reset
                ? "Travel dates for {$member->name} reset to the delegation's dates (were {$range($start, $end)}, outside {$first} to {$last})"
                : "Travel dates for {$member->name} moved into the delegation's dates: {$range($start, $end)} → {$range($newStart, $newEnd)}");
            $adjusted++;
        }

        return $adjusted;
    }

    /**
     * Add a user to delegation (admin assigns directly = approved).
     */
    public function addUser(Request $request, Delegation $delegation)
    {
        $request->validate([
            'user_id' => ['required', 'integer', User::activeExistsRule()],
            'custom_start' => ['sometimes', 'nullable', 'date'],
            'custom_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $pivotData = ['status' => 'approved'];
        if ($request->has('custom_start')) {
            $pivotData['custom_start'] = self::dateOnly($request->custom_start);
        }
        if ($request->has('custom_end')) {
            $pivotData['custom_end'] = self::dateOnly($request->custom_end);
        }
        // A date not sent stays as stored, so the check uses the member's current one.
        $current = $delegation->users()->where('users.id', $request->user_id)->first()?->pivot;
        $this->ensureTravelDatesFit(
            $delegation,
            array_key_exists('custom_start', $pivotData) ? $pivotData['custom_start'] : $current?->custom_start,
            array_key_exists('custom_end', $pivotData) ? $pivotData['custom_end'] : $current?->custom_end,
        );

        DB::transaction(function () use ($request, $delegation, $pivotData) {
            // Locked like destroy(), so a delete can't miss a member added at the same moment.
            Delegation::lockOrGone($delegation->id);
            $delegation->users()->syncWithoutDetaching([$request->user_id => $pivotData]);

            // If user already existed with different status, update it
            $delegation->users()->updateExistingPivot($request->user_id, $pivotData);

            $userName = User::find($request->user_id)?->name ?? $request->user_id;
            $this->log($delegation->id, $request->user()->id, 'user_added', "Added {$userName} (approved by admin)");
        });

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation);
    }

    /**
     * Update user custom dates on a delegation — admin.
     */
    public function updateUserDates(Request $request, Delegation $delegation)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'custom_start' => ['sometimes', 'nullable', 'date'],
            'custom_end' => ['sometimes', 'nullable', 'date'],
        ]);
        $member = $this->memberOr404($delegation, (int) $request->user_id);

        $customStart = self::dateOnly($request->custom_start);
        $customEnd = self::dateOnly($request->custom_end);

        DB::transaction(function () use ($request, $delegation, $member, $customStart, $customEnd) {
            // Checked against the locked row: a date change of the delegation at the same moment
            // either lands first (and these dates are checked against it) or clamps them after.
            $this->ensureTravelDatesFit(Delegation::lockOrGone($delegation->id), $customStart, $customEnd);

            $delegation->users()->updateExistingPivot($request->user_id, [
                'custom_start' => $customStart,
                'custom_end' => $customEnd,
            ]);

            $this->log($delegation->id, $request->user()->id, 'dates_updated', "Updated dates for {$member->name}");
        });

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation);
    }

    /**
     * Approve a user's request — admin.
     */
    public function approveUser(Request $request, Delegation $delegation)
    {
        $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        DB::transaction(function () use ($request, $delegation) {
            // Locked like destroy(), so a delete can't miss a member approved at the same moment.
            Delegation::lockOrGone($delegation->id);
            $member = $this->memberOr404($delegation, (int) $request->user_id);
            $delegation->users()->updateExistingPivot($member->id, ['status' => 'approved']);

            $this->log($delegation->id, $request->user()->id, 'user_approved', "Approved {$member->name}");
        });

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation);
    }

    /**
     * Reject a user's request — admin.
     */
    public function rejectUser(Request $request, Delegation $delegation)
    {
        $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $member = $this->memberOr404($delegation, (int) $request->user_id);
        $delegation->users()->updateExistingPivot($member->id, ['status' => 'rejected']);

        $this->log($delegation->id, $request->user()->id, 'user_rejected', "Rejected {$member->name}");

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation);
    }

    /**
     * Remove a user from a delegation — admin.
     */
    public function removeUser(Request $request, Delegation $delegation)
    {
        $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $member = $this->memberOr404($delegation, (int) $request->user_id);
        $delegation->users()->detach($member->id);
        $this->log($delegation->id, $request->user()->id, 'user_removed', "Removed {$member->name}");

        $delegation->load(['users:id,name,email', 'creator:id,name']);

        return response()->json($delegation);
    }

    /**
     * Delete a delegation — admin. Only while nobody has joined it: once a member is approved
     * (status "approved", whether added by an admin or accepted after a request) the delegation is
     * history and is archived instead (409). Pending requests and rejected ones don't count as
     * joined; they are deleted with it, as is its log.
     */
    public function destroy(Delegation $delegation)
    {
        return DB::transaction(function () use ($delegation) {
            // The delegation row is locked here and by addUser()/approveUser(), so a member added
            // or approved meanwhile is seen (and blocks the delete) rather than deleted with it.
            Delegation::lockOrGone($delegation->id);
            $joined = $delegation->users()->wherePivot('status', 'approved')->orderBy('name')->pluck('name');
            if ($joined->isNotEmpty()) {
                return response()->json([
                    'code' => 'has_members',
                    'message' => 'This delegation has members ('.$joined->implode(', ').'), so it can\'t be deleted. Archive it instead.',
                    'members' => $joined->values()->all(),
                ], 409);
            }

            $delegation->delete();

            return response()->json(['message' => 'Delegation deleted.']);
        });
    }

    /**
     * List open delegations — any authenticated user. Each lists its approved participants and the
     * viewer's own application (Delegation::membersFor), never other applicants' pending/rejected status;
     * others' application times and travel dates stay hidden (hideOthersApplicationDetails).
     */
    public function openDelegations(Request $request)
    {
        $delegations = Delegation::with([...Delegation::membersFor($request->user()), 'creator:id,name'])
            ->where('registration_open', true)
            // Open through its last day: delegations are date-only, compared with the business date.
            ->whereDate('end_datetime', '>=', BusinessDate::todayString())
            ->orderBy('start_datetime')
            ->get();

        // Attach current user's status
        $userId = $request->user()->id;
        $delegations->each(function ($del) use ($userId) {
            $pivot = $del->users->firstWhere('id', $userId)?->pivot;
            $del->my_status = $pivot?->status; // null = not applied, pending, approved, rejected
        });
        $delegations->each->hideOthersApplicationDetails($request->user());

        return response()->json($delegations);
    }

    /**
     * Self-register (request to join) — status = pending.
     */
    public function selfRegister(Request $request, Delegation $delegation)
    {
        if (! $delegation->registration_open) {
            return response()->json(['message' => 'Registration for this delegation is closed.'], 403);
        }
        // Open through its last day (business date), as the open list shows it.
        if ($delegation->end_datetime->format('Y-m-d') < BusinessDate::todayString()) {
            return response()->json(['message' => 'This delegation has ended; registration is closed.'], 422);
        }

        // Check if already in the list
        $existing = $delegation->users()->where('user_id', $request->user()->id)->first();
        if ($existing) {
            return response()->json(['message' => 'You have already applied for this delegation.'], 422);
        }

        try {
            // In a transaction (savepoint) so a racing duplicate's failed insert can't poison an outer one.
            DB::transaction(fn () => $delegation->users()->attach($request->user()->id, ['status' => 'pending']));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request (double click, second tab) applied first: delegation + user is unique.
            return response()->json(['message' => 'You have already applied for this delegation.'], 422);
        }
        $this->log($delegation->id, $request->user()->id, 'self_registered', "{$request->user()->name} requested to join");

        $delegation->load([...Delegation::membersFor($request->user()), 'creator:id,name']);

        return response()->json($delegation->hideOthersApplicationDetails($request->user()));
    }

    /**
     * Self-unregister — only if still pending.
     */
    public function selfUnregister(Request $request, Delegation $delegation)
    {
        $existing = $delegation->users()->where('user_id', $request->user()->id)->first();
        if (! $existing || $existing->pivot->status !== 'pending') {
            return response()->json(['message' => 'You can only withdraw a pending request.'], 403);
        }

        $delegation->users()->detach($request->user()->id);
        $this->log($delegation->id, $request->user()->id, 'self_unregistered', "{$request->user()->name} withdrew request");

        $delegation->load([...Delegation::membersFor($request->user()), 'creator:id,name']);

        return response()->json($delegation->hideOthersApplicationDetails($request->user()));
    }

    /**
     * Get the current user's delegations (all time, for history).
     */
    public function myDelegations(Request $request)
    {
        $userId = $request->user()->id;
        // The viewer's own membership row is eager loaded (one query, not one per delegation).
        $delegations = Delegation::with([
            'creator:id,name',
            'users' => fn ($q) => $q->select('users.id')->where('users.id', $userId),
        ])
            ->whereHas('users', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderByDesc('start_datetime')
            ->get();

        $delegations->each(function ($del) {
            $pivot = $del->users->first()?->pivot;
            $del->my_status = $pivot?->status;
            $del->my_custom_start = $pivot?->custom_start;
            $del->my_custom_end = $pivot?->custom_end;
            // Only the my_* fields go out, as before: not the membership list.
            $del->unsetRelation('users');
        });

        return response()->json($delegations);
    }

    /**
     * Delegations in a month, for the Schedule overlay and conflict checks (schedule managers), with
     * approved participants only for every role (Delegation::approvedMembers), as auto-fill counts
     * them. Admins manage applicants on /delegations.
     */
    public function forMonth(Request $request)
    {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $delegations = Delegation::with(Delegation::approvedMembers())
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_datetime', [$start, $end])
                    ->orWhereBetween('end_datetime', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_datetime', '<=', $start)
                            ->where('end_datetime', '>=', $end);
                    });
            })
            ->orderBy('start_datetime')
            ->get();

        return response()->json($delegations);
    }

    /**
     * The user as a member of the delegation (with pivot), or 404 naming them: approving,
     * rejecting, removing or re-dating someone who isn't on it changes nothing and logs nothing.
     */
    private function memberOr404(Delegation $delegation, int $userId): User
    {
        $member = $delegation->users()->where('users.id', $userId)->first();
        if (! $member) {
            $name = User::whereKey($userId)->value('name') ?? "User {$userId}";
            abort(response()->json(['message' => "{$name} is not on this delegation."], 404));
        }

        return $member;
    }

    /**
     * A member's own travel dates (either may be unset) lie within the delegation's days, and the
     * last is on or after the first.
     */
    private function ensureTravelDatesFit(Delegation $delegation, ?string $customStart, ?string $customEnd): void
    {
        $first = $delegation->start_datetime->format('Y-m-d');
        $last = $delegation->end_datetime->format('Y-m-d');
        $outside = "Travel dates must fall within the delegation ({$first} to {$last}).";
        $errors = [];
        if ($customStart !== null && ($customStart < $first || $customStart > $last)) {
            $errors['custom_start'] = $outside;
        }
        if ($customEnd !== null && ($customEnd < $first || $customEnd > $last)) {
            $errors['custom_end'] = $outside;
        } elseif ($customStart !== null && $customEnd !== null && $customEnd < $customStart) {
            $errors['custom_end'] = 'The travel end date must be on or after the travel start date.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Delegations are whole days in the business calendar. The UI sends "Y-m-d"; a value with a
     * time or an offset keeps the date as written ("2026-10-05T00:30:00+05:00" is the 5th), so
     * it is never shifted to another day by a timezone conversion.
     */
    private static function dateOnly(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->format('Y-m-d');
    }
}
