<?php

namespace App\Http\Controllers;

use App\Models\OfficeClosure;
use App\Models\Shift;
use App\Models\ShiftGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Month;

class OfficeClosureController extends Controller
{
    /**
     * List all closures, optionally filtered by month.
     */
    public function index(Request $request)
    {
        $request->validate(['month' => ['sometimes', 'nullable', 'date_format:Y-m']]);
        $query = OfficeClosure::with('creator:id,name')->orderBy('date');

        if ($request->filled('month')) {
            $start = Month::start($request->month);
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        }

        return response()->json($query->get());
    }

    /** The longest range one request may close: a (leap) year. */
    public const MAX_RANGE_DAYS = 366;

    /**
     * Create office closures for a date range (at most MAX_RANGE_DAYS days, last day included),
     * all or none. Days already closed keep their closure; `created` counts only the new ones.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => 'A closure can cover at most ' . self::MAX_RANGE_DAYS . " days at once ({$days} requested).",
            ]);
        }

        $created = DB::transaction(function () use ($start, $end, $validated, $request) {
            $created = 0;
            for ($current = $start->copy(); $current->lte($end); $current->addDay()) {
                $closure = OfficeClosure::firstOrCreate(
                    ['date' => $current->toDateString()],
                    [
                        'title' => $validated['title'],
                        'description' => $validated['description'] ?? null,
                        'created_by' => $request->user()->id,
                    ]
                );
                if ($closure->wasRecentlyCreated) {
                    $created++;
                }
            }

            return $created;
        });

        $alreadyClosed = $days - $created;
        $message = "Created {$created} closure(s)."
            . ($alreadyClosed > 0 ? " {$alreadyClosed} day(s) were already closed." : '');

        return response()->json([
            'message' => $message,
            'created' => $created,
            'already_closed' => $alreadyClosed,
        ], 201);
    }

    /**
     * Update an existing closure.
     */
    public function update(Request $request, OfficeClosure $officeClosure)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $officeClosure->update($validated);

        return response()->json($officeClosure);
    }

    /**
     * Delete a closure.
     */
    public function destroy(OfficeClosure $officeClosure)
    {
        $officeClosure->delete();
        return response()->json(['message' => 'Office closure removed.']);
    }

    /**
     * The month's closures, each with the office-mode shifts already planned on that day
     * (`office_shifts`, from groups that aren't archived): the generate dialog warns about closed
     * days from the closures, and these shifts are the conflicts that already exist.
     */
    public function checkConflicts(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $closures = OfficeClosure::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $officeShifts = Shift::with('shiftGroup:id,name')
            ->withCount('assignments')
            ->whereIn('date', $closures->map(fn ($c) => $c->date->toDateString()))
            ->where('work_mode', 'office')
            ->where(fn ($q) => $q->whereNull('shift_group_id')
                ->orWhereIn('shift_group_id', ShiftGroup::notArchived()->select('id')))
            ->orderBy('shift_group_id')
            ->orderBy('shift_number')
            ->get()
            ->groupBy(fn (Shift $shift) => $shift->date->toDateString());

        foreach ($closures as $closure) {
            $closure->setAttribute('office_shifts', $officeShifts->get($closure->date->toDateString(), collect())
                ->map(fn (Shift $shift) => [
                    'id' => $shift->id,
                    'date' => $shift->date->toDateString(),
                    'shift_number' => $shift->shift_number,
                    'shift_group_id' => $shift->shift_group_id,
                    'shift_group_name' => $shift->shiftGroup?->name,
                    'start_time' => $shift->start_time ? substr($shift->start_time, 0, 5) : null,
                    'end_time' => $shift->end_time ? substr($shift->end_time, 0, 5) : null,
                    'assigned_count' => $shift->assignments_count,
                ])->values()->all());
        }

        return response()->json($closures);
    }
}
