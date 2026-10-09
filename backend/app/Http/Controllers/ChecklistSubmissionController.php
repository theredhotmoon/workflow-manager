<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSubmission;
use App\Models\ChecklistResponse;
use App\Models\ChecklistTemplate;
use App\Models\ShiftChecklist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChecklistSubmissionController extends Controller
{
    /**
     * List submissions with filters.
     */
    public function index(Request $request)
    {
        $query = ChecklistSubmission::with(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name'])
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
     * Outgoing user creates and fills a submission.
     */
    public function store(Request $request)
    {
        $request->validate([
            // An archived template can't start a new handover.
            'checklist_template_id' => ['required', Rule::exists('checklist_templates', 'id')->whereNull('archived_at')],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'date' => ['required', 'date'],
            'outgoing_user_id' => ['required', User::activeExistsRule()],
            'incoming_user_id' => ['nullable', User::activeExistsRule()],
            'general_comments' => ['nullable', 'string'],
            'responses' => ['required', 'array'],
            'responses.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'responses.*.value' => ['nullable'],
            'responses.*.group_comment' => ['nullable', 'string'],
        ]);

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
                    $shift = $sc->shift;
                    $deadline = $sc->getDeadlineDatetime(
                        $shift->date->format('Y-m-d'),
                        $shift->start_time ? substr($shift->start_time, 0, 5) : null,
                        $shift->end_time ? substr($shift->end_time, 0, 5) : null
                    );
                    $submittedOnTime = $deadline ? now()->lte($deadline) : true;
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

        $submission->load(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name', 'responses']);
        return response()->json($submission, 201);
    }

    /**
     * View a filled submission with all responses.
     */
    public function show(ChecklistSubmission $checklistSubmission)
    {
        $checklistSubmission->load([
            'template',
            'outgoingUser:id,name',
            'incomingUser:id,name',
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
     * Incoming user confirms flagged items.
     */
    public function confirm(Request $request, ChecklistSubmission $checklistSubmission)
    {
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
                'general_comments' => $request->general_comments ?? $checklistSubmission->general_comments,
            ]);
        });

        $checklistSubmission->load(['template:id,name', 'outgoingUser:id,name', 'incomingUser:id,name', 'responses']);
        return response()->json($checklistSubmission);
    }
}
