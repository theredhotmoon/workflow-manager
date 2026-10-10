<?php

namespace App\Http\Controllers;

use App\Models\ProcedureFaqItem;
use App\Models\ProcedureFaqReply;
use App\Models\Procedure;
use App\Models\Alarm;
use App\Services\AlarmService;
use Illuminate\Http\Request;

class ProcedureFaqController extends Controller
{
    public function index(Procedure $procedure)
    {
        $items = $procedure->faqItems()
            ->with(['author:id,name', 'replies' => fn($q) => $q->with('author:id,name')->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($items);
    }

    public function storeItem(Request $request, Procedure $procedure)
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $item = $procedure->faqItems()->create([
            'question' => $validated['question'],
            'created_by' => $request->user()->id,
            'for_version' => $procedure->version,
        ]);

        $item->load('author:id,name');

        // Notify procedure owner about new question
        if ($procedure->owner_id && $procedure->owner_id !== $request->user()->id) {
            (new AlarmService())->notifyUser(
                Alarm::TYPE_PROCEDURE_NEW_QUESTION_ADDED,
                $procedure->owner_id,
                "New question on procedure \"{$procedure->title}\"",
                $validated['question'],
                "/procedures/{$procedure->id}",
                'View Procedure'
            );
        }

        return response()->json($item, 201);
    }

    public function storeReply(Request $request, ProcedureFaqItem $item)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $procedure = $item->procedure;
        // An archived procedure is hidden from everyone but admins, its FAQ included.
        if ($procedure->isArchived() && !$request->user()->isAdmin()) {
            abort(404);
        }

        $reply = $item->replies()->create([
            'body' => $validated['body'],
            'created_by' => $request->user()->id,
            'for_version' => $procedure->version,
        ]);

        $reply->load('author:id,name');

        // Notify question author about the reply
        if ($item->created_by && $item->created_by !== $request->user()->id) {
            (new AlarmService())->notifyUser(
                Alarm::TYPE_PROCEDURE_QUESTION_REPLIED,
                $item->created_by,
                "Your question on \"{$procedure->title}\" received a reply",
                $validated['body'],
                "/procedures/{$procedure->id}",
                'View Procedure'
            );
        }

        return response()->json($reply, 201);
    }

    public function toggleOutdatedItem(Request $request, ProcedureFaqItem $item)
    {
        $procedure = $item->procedure;
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $item->update(['is_outdated' => !$item->is_outdated]);
        return response()->json($item);
    }

    public function toggleOutdatedReply(Request $request, ProcedureFaqReply $reply)
    {
        $procedure = $reply->faqItem->procedure;
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $reply->update(['is_outdated' => !$reply->is_outdated]);
        return response()->json($reply);
    }

    public function destroyItem(Request $request, ProcedureFaqItem $item)
    {
        if ($item->created_by !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        $item->delete();
        return response()->json(['message' => 'FAQ item deleted.']);
    }

    public function destroyReply(Request $request, ProcedureFaqReply $reply)
    {
        if ($reply->created_by !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        $reply->delete();
        return response()->json(['message' => 'Reply deleted.']);
    }
}
