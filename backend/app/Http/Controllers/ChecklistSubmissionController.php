<?php

namespace App\Http\Controllers;

use App\Models\ChecklistGroup;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistResponse;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChecklist;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistSubmissionController extends Controller
{
    /** Columns the archive can be sorted by. */
    private const SORTS = ['date', 'template', 'outgoing', 'incoming', 'status', 'timing', 'submitted_at'];

    private const STATUS_LABELS = ['draft' => 'Draft', 'pending_confirmation' => 'Pending', 'completed' => 'Completed'];

    /**
     * List submissions with filters, one page at a time (per_page up to 100), plus how many of the
     * matching handovers are in each status. Admins see every handover; anyone else only their own
     * (as outgoing, incoming or filer), and the filters below narrow that, never widen it.
     */
    public function index(Request $request)
    {
        $query = $this->archiveQuery($request);

        $statusCounts = array_fill_keys(['completed', 'pending_confirmation', 'draft'], 0);
        $counts = $query->clone()->reorder()->toBase()
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        foreach ($counts as $status => $n) {
            $statusCounts[$status] = (int) $n;
        }

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));
        $page = $query->with(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name'])->paginate($perPage);

        return response()->json(array_merge($page->toArray(), ['status_counts' => $statusCounts]));
    }

    /**
     * Every handover the archive list would show for the same filters and sort, as a CSV download.
     * Streamed in chunks, so there is no row cap and the whole archive is never held in memory.
     */
    public function export(Request $request)
    {
        $query = $this->archiveQuery($request)->with(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name']);
        $filename = 'checklist-archive-' . BusinessDate::todayString() . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            self::csvRow($out, ['Date', 'Template', 'Outgoing Employee', 'Incoming Employee', 'Status', 'Timing', 'Submitted At']);
            foreach ($query->lazy(500) as $submission) {
                self::csvRow($out, [
                    $submission->date?->format('Y-m-d') ?? '',
                    $submission->template?->name ?? '',
                    $submission->outgoingUser?->name ?? '',
                    $submission->incomingUser?->name ?? '',
                    self::STATUS_LABELS[$submission->status] ?? $submission->status,
                    match ($submission->submitted_on_time) { true => 'On time', false => 'Late', default => '' },
                    $submission->submitted_at?->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i') ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The archive's filtered, scoped and sorted query, shared by index() and export() so the two
     * can never disagree on which handovers a user may see or which ones match.
     */
    private function archiveQuery(Request $request): Builder
    {
        $request->validate([
            'template_id' => ['sometimes', 'nullable', 'integer'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'nullable', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'nullable', Rule::in(self::SORTS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = ChecklistSubmission::query()->visibleTo($request->user());

        if ($request->filled('template_id')) {
            $query->where('checklist_template_id', $request->template_id);
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }
        if ($request->filled('user_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('outgoing_user_id', $request->user_id)
                    ->orWhere('incoming_user_id', $request->user_id);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            // Template or either person's name, case-insensitively; % and _ are matched literally.
            $like = '%' . addcslashes(mb_strtolower($request->search), '\\%_') . '%';
            $nameMatches = fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]);
            $query->where(fn ($q) => $q->whereHas('template', $nameMatches)
                ->orWhereHas('outgoingUser', $nameMatches)
                ->orWhereHas('incomingUser', $nameMatches));
        }

        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $nameOf = fn (string $table, string $column) => DB::table($table)->select('name')
            ->whereColumn("{$table}.id", "checklist_submissions.{$column}")->limit(1);
        match ($request->input('sort') ?? 'date') {
            'template' => $query->orderBy($nameOf('checklist_templates', 'checklist_template_id'), $direction),
            'outgoing' => $query->orderBy($nameOf('users', 'outgoing_user_id'), $direction),
            'incoming' => $query->orderBy($nameOf('users', 'incoming_user_id'), $direction),
            'status' => $query->orderBy('status', $direction),
            'timing' => $query->orderBy('submitted_on_time', $direction),
            'submitted_at' => $query->orderBy('submitted_at', $direction),
            default => $query->orderBy('date', $direction),
        };

        // Newest first within equal keys, then by id so pages never overlap or skip a row.
        return $query->orderBy('date', 'desc')->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /** One CSV line; a cell a spreadsheet would run as a formula gets a leading quote. */
    private static function csvRow($handle, array $cells): void
    {
        $safe = array_map(
            fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) === 1 ? "'" . $cell : (string) $cell,
            $cells,
        );
        fputcsv($handle, $safe, ',', '"', '');
    }

    /**
     * Handovers waiting for the current user's confirmation: only those naming them as the incoming
     * user (the history list, index(), shows both directions).
     */
    public function pendingConfirmations(Request $request)
    {
        return response()->json(ChecklistSubmission::with(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name'])
            ->where('status', 'pending_confirmation')
            ->where('incoming_user_id', $request->user()->id)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get());
    }

    /**
     * Outgoing user creates and fills a submission.
     */
    public function store(Request $request)
    {
        $request->validate([
            // An archived template can't start a new handover.
            'checklist_template_id' => ['required', Rule::exists('checklist_templates', 'id')->whereNull('archived_at')],
            // `integer` first: a failed rule skips the exists query (no bigint cast error, 422 not 500).
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            // A calendar day, as the form and alert links send it; compared with the shift's date.
            'date' => ['required', 'date_format:Y-m-d'],
            'outgoing_user_id' => ['required', 'integer', User::activeExistsRule()],
            'incoming_user_id' => ['nullable', 'integer', 'different:outgoing_user_id', User::activeExistsRule()],
            'general_comments' => ['nullable', 'string'],
            'responses' => ['required', 'array'],
            // Only current items (not retired, nor in a retired group) of the submitted template, each
            // answered once: the fill form only offers those. Past handovers keep their answers to
            // items retired since. (`integer` first: a failed rule skips the exists query, so a
            // non-numeric id never reaches the database.)
            'responses.*.checklist_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('checklist_items', 'id')->whereNull('archived_at')->whereIn('checklist_group_id',
                    ChecklistGroup::select('id')
                        ->where('checklist_template_id', (int) $request->input('checklist_template_id'))
                        ->whereNull('archived_at')),
            ],
            'responses.*.value' => ['nullable'],
            'responses.*.group_comment' => ['nullable', 'string'],
        ], [
            'checklist_template_id.exists' => 'This checklist template has been archived and can no longer be filled.',
            'outgoing_user_id.exists' => 'The outgoing user has been deactivated. Pick someone else.',
            'incoming_user_id.exists' => 'The incoming user has been deactivated. Pick someone else.',
            'incoming_user_id.different' => 'The incoming employee must be someone other than the outgoing one.',
            'responses.*.checklist_item_id.exists' => 'This item is not (or no longer) part of the chosen checklist template.',
            'responses.*.checklist_item_id.distinct' => 'Each checklist item can be answered only once.',
        ]);
        // `different` compares strictly ("5" vs 5 passes it), so the checks below compare integers.
        $this->ensureTwoPeople($request);
        $this->ensureShiftBelongsToHandover($request);

        $submission = DB::transaction(function () use ($request) {
            $hasConfirmationItems = ChecklistTemplate::find($request->checklist_template_id)
                ->groups()
                ->whereHas('items', fn($q) => $q->where('requires_confirmation', true))
                ->exists();

            $status = ($request->incoming_user_id && $hasConfirmationItems)
                ? 'pending_confirmation'
                : 'completed';

            // Calculate submitted_on_time
            $submittedOnTime = null;
            if ($request->shift_id) {
                $sc = ShiftChecklist::where('shift_id', $request->shift_id)
                    ->where('checklist_template_id', $request->checklist_template_id)
                    ->first();
                if ($sc) {
                    $deadline = $sc->getDeadlineDatetime($sc->shift);
                    $submittedOnTime = $deadline ? BusinessDate::now()->lte($deadline) : true;
                }
            }

            $submission = ChecklistSubmission::create([
                'checklist_template_id' => $request->checklist_template_id,
                'shift_id' => $request->shift_id,
                'date' => $request->date,
                'outgoing_user_id' => $request->outgoing_user_id,
                'incoming_user_id' => $request->incoming_user_id,
                'status' => $status,
                'general_comments' => $request->general_comments,
                'submitted_at' => now(),
                'submitted_by_user_id' => $request->user()->id,
                'confirmed_at' => $status === 'completed' ? now() : null,
                'submitted_on_time' => $submittedOnTime,
            ]);

            foreach ($request->responses as $responseData) {
                ChecklistResponse::create([
                    'checklist_submission_id' => $submission->id,
                    'checklist_item_id' => $responseData['checklist_item_id'],
                    'value' => is_bool($responseData['value'])
                        ? ($responseData['value'] ? 'true' : 'false')
                        : $responseData['value'],
                    'confirmed' => false,
                    'group_comment' => $responseData['group_comment'] ?? null,
                ]);
            }

            return $submission;
        });

        $submission->load(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name', 'submittedBy:id,name', 'responses']);
        return response()->json($submission, 201);
    }

    /**
     * Keeps a handover's two-person check honest: the outgoing user is the caller (only an admin may
     * file on someone's behalf), and the incoming user is someone else, never the filing admin.
     * Ids are compared as integers, so "5" and 5 are the same person.
     */
    private function ensureTwoPeople(Request $request): void
    {
        $caller = $request->user();
        $callerId = (int) $caller->id;
        $outgoing = (int) $request->outgoing_user_id;
        $incoming = $request->filled('incoming_user_id') ? (int) $request->incoming_user_id : null;

        if ($outgoing !== $callerId && !$caller->isAdmin()) {
            throw ValidationException::withMessages([
                'outgoing_user_id' => 'You can only file a handover as its outgoing employee yourself.',
            ]);
        }
        if ($incoming === null) {
            return;
        }
        if ($incoming === $outgoing) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'The incoming employee must be someone other than the outgoing one.',
            ]);
        }
        if ($incoming === $callerId) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'You cannot name yourself as incoming on a handover you file for someone else.',
            ]);
        }
    }

    /**
     * A handover's shift, when given, is the outgoing user's shift on the handover's date: neither
     * the employee nor an admin filing for them can pin it on someone else's shift (which would also
     * clear that shift's checklist alert for its real crew) or on another day's.
     */
    private function ensureShiftBelongsToHandover(Request $request): void
    {
        if (!$request->filled('shift_id')) {
            return;
        }
        $shift = Shift::find($request->shift_id);

        if ($shift->date->toDateString() !== Carbon::parse($request->date)->toDateString()) {
            throw ValidationException::withMessages([
                'shift_id' => 'The shift is not on the handover date.',
            ]);
        }
        $assigned = ShiftAssignment::where('shift_id', $shift->id)
            ->where('user_id', (int) $request->outgoing_user_id)
            ->exists();
        if (!$assigned) {
            throw ValidationException::withMessages([
                'shift_id' => 'The outgoing employee is not assigned to this shift.',
            ]);
        }
    }

    /**
     * View a filled submission with all responses: admins any, anyone else only their own (see
     * ChecklistSubmission::isVisibleTo()).
     */
    public function show(Request $request, ChecklistSubmission $checklistSubmission)
    {
        if (!$checklistSubmission->isVisibleTo($request->user())) {
            return response()->json(['message' => 'You can only view handovers you are part of.'], 403);
        }

        $checklistSubmission->load([
            'template',
            'outgoingUser:id,name',
            'incomingUser:id,name',
            'submittedBy:id,name',
            'responses',
        ]);

        // The template as this submission saw it: current groups/items plus any retired since that
        // this submission answered, so history keeps showing them with their labels.
        $template = $checklistSubmission->template;
        if ($template) {
            $answered = $checklistSubmission->responses->pluck('checklist_item_id')->all();
            $shown = fn ($q) => $q->where(fn ($w) => $w->whereNull('archived_at')->orWhereIn('id', $answered));
            $groups = $template->allGroups()
                ->where(fn ($w) => $w->whereNull('archived_at')
                    ->orWhereHas('allItems', fn ($i) => $i->whereIn('id', $answered)))
                ->with(['allItems' => $shown, 'allItems.procedure:id,identifier,title,version'])
                ->get();
            foreach ($groups as $group) {
                $group->setRelation('items', $group->allItems)->unsetRelation('allItems');
            }
            $template->setRelation('groups', $groups);
        }

        return response()->json($checklistSubmission);
    }

    /**
     * Incoming user confirms flagged items. Only they can: not the outgoing user, and not an admin on
     * their behalf, so the handover keeps its two-person check.
     */
    public function confirm(Request $request, ChecklistSubmission $checklistSubmission)
    {
        if ($request->user()->id !== $checklistSubmission->incoming_user_id) {
            return response()->json(['message' => 'Only the incoming user named on this handover can confirm it.'], 403);
        }

        if ($checklistSubmission->status !== 'pending_confirmation') {
            return response()->json(['message' => 'This submission is not awaiting confirmation.'], 422);
        }

        $request->validate([
            'confirmed_item_ids' => ['required', 'array'],
            'confirmed_item_ids.*' => ['integer', 'exists:checklist_responses,id'],
            'general_comments' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($request, $checklistSubmission) {
            // Mark confirmed responses
            ChecklistResponse::where('checklist_submission_id', $checklistSubmission->id)
                ->whereIn('id', $request->confirmed_item_ids)
                ->update(['confirmed' => true]);

            $checklistSubmission->update([
                'status' => 'completed',
                'confirmed_at' => now(),
                'confirmed_by_id' => $request->user()->id,
                'general_comments' => $request->general_comments ?? $checklistSubmission->general_comments,
            ]);
        });

        $checklistSubmission->load(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name', 'confirmedBy:id,name', 'responses']);
        return response()->json($checklistSubmission);
    }
}
