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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistSubmissionController extends Controller
{
    /**
     * List submissions with filters. Admins see every handover; anyone else only their own (as
     * outgoing, incoming or filer), and the filters below narrow that, never widen it.
     */
    public function index(Request $request)
    {
        $query = ChecklistSubmission::with(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name'])
            ->visibleTo($request->user())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->has('template_id')) {
            $query->where('checklist_template_id', $request->template_id);
        }
        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }
        if ($request->has('user_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('outgoing_user_id', $request->user_id)
                    ->orWhere('incoming_user_id', $request->user_id);
            });
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 20), 1000);
        return response()->json($query->paginate($perPage));
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
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'date' => ['required', 'date'],
            'outgoing_user_id' => ['required', 'integer', User::activeExistsRule()],
            'incoming_user_id' => ['nullable', 'integer', 'different:outgoing_user_id', User::activeExistsRule()],
            'general_comments' => ['nullable', 'string'],
            'responses' => ['required', 'array'],
            // Only items of the submitted template, each answered once. (`integer` first: a failed
            // rule skips the exists query, so a non-numeric id never reaches the database.)
            'responses.*.checklist_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('checklist_items', 'id')->whereIn('checklist_group_id',
                    ChecklistGroup::select('id')->where('checklist_template_id', (int) $request->input('checklist_template_id'))),
            ],
            'responses.*.value' => ['nullable'],
            'responses.*.group_comment' => ['nullable', 'string'],
        ], [
            'checklist_template_id.exists' => 'This checklist template has been archived and can no longer be filled.',
            'outgoing_user_id.exists' => 'The outgoing user has been deactivated. Pick someone else.',
            'incoming_user_id.exists' => 'The incoming user has been deactivated. Pick someone else.',
            'incoming_user_id.different' => 'The incoming employee must be someone other than the outgoing one.',
            'responses.*.checklist_item_id.exists' => 'This item does not belong to the chosen checklist template.',
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
