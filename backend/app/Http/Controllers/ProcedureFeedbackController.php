<?php

namespace App\Http\Controllers;

use App\Models\ProcedureFeedback;
use App\Models\Procedure;
use App\Models\Alarm;
use App\Services\AlarmService;
use Illuminate\Http\Request;

class ProcedureFeedbackController extends Controller
{
    public function index(Request $request)
    {
        $query = ProcedureFeedback::with(['procedure:id,identifier,title', 'user:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request, Procedure $procedure)
    {
        $procedure->refuseWritesIfArchived($request->user());
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:error,suggestion'],
            'description' => ['required', 'string', 'max:5000'],
            'proposed_changes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $feedback = $procedure->feedbacks()->create(array_merge($validated, [
            'user_id' => $request->user()->id,
            'status' => 'open',
            'for_version' => $procedure->version,
        ]));

        $feedback->load('user:id,name');

        // Notify procedure owner
        if ($procedure->owner_id && $procedure->owner_id !== $request->user()->id) {
            $typeLabel = $validated['type'] === 'error' ? 'issue' : 'suggestion';
            (new AlarmService())->notifyUser(
                Alarm::TYPE_PROCEDURE_NEW_ISSUE_REPORTED,
                $procedure->owner_id,
                "New {$typeLabel} reported on \"{$procedure->title}\"",
                $validated['description'],
                "/procedures/{$procedure->id}",
                'View Procedure'
            );
        }

        return response()->json($feedback, 201);
    }

    public function update(Request $request, ProcedureFeedback $feedback)
    {
        // Feedback on an archived procedure can still be settled (status, resolution), so the admin
        // list can be cleared; a new owner comment is content and is refused like other writes.
        if ($request->has('owner_comment')) {
            $feedback->procedure->refuseWritesIfArchived($request->user());
        }
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,reviewed,resolved'],
            'resolution' => ['sometimes', 'nullable', 'string', 'in:accepted,rejected,deferred'],
            'owner_comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $feedback->update($validated);
        $feedback->load(['user:id,name', 'procedure:id,title']);

        // Notify feedback author when issue is resolved
        if (isset($validated['status']) && $validated['status'] === 'resolved' && $feedback->user_id) {
            (new AlarmService())->notifyUser(
                Alarm::TYPE_PROCEDURE_ISSUE_SORTED,
                $feedback->user_id,
                "Your feedback on \"{$feedback->procedure->title}\" has been resolved",
                $validated['owner_comment'] ?? null,
                "/procedures/{$feedback->procedure_id}",
                'View Procedure'
            );
        }

        return response()->json($feedback);
    }
}
