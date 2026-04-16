<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\ProcedureOwnerChange;
use App\Models\ProcedureUserRead;
use App\Models\ProcedureVersion;
use Illuminate\Http\Request;

class ProcedureController extends Controller
{
    public function index(Request $request)
    {
        $query = Procedure::with(['owner:id,name', 'tags:id,name,color']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('identifier', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%");
            });
        }

        if ($tagIds = $request->input('tags')) {
            $ids = is_array($tagIds) ? $tagIds : explode(',', $tagIds);
            $query->whereHas('tags', fn($q) => $q->whereIn('procedure_tags.id', $ids));
        }

        // Date range filters
        if ($createdAfter = $request->input('created_after')) {
            $query->where('created_at', '>=', $createdAfter);
        }
        if ($createdBefore = $request->input('created_before')) {
            $query->where('created_at', '<=', $createdBefore . ' 23:59:59');
        }
        if ($updatedAfter = $request->input('updated_after')) {
            $query->where('updated_at', '>=', $updatedAfter);
        }
        if ($updatedBefore = $request->input('updated_before')) {
            $query->where('updated_at', '<=', $updatedBefore . ' 23:59:59');
        }

        // Location tree filter — filter by parent_id (node_id) or all assigned ('root')
        if ($nodeId = $request->input('node_id')) {
            if ($nodeId === 'root') {
                $query->whereNotNull('parent_id');
            } else {
                // Collect all descendant IDs including the node itself
                $descendantIds = $this->collectDescendantIds((int) $nodeId);
                $query->whereIn('id', $descendantIds);
            }
        }

        // Sorting
        $sortableColumns = ['title', 'identifier', 'created_at', 'updated_at'];
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortDir = $request->input('sort_dir', 'desc');
        if (in_array($sortBy, $sortableColumns)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('updated_at');
        }

        // Pagination
        $perPage = (int) $request->input('per_page', 30);
        $perPage = min(max($perPage, 1), 100);
        $paginated = $query->paginate($perPage);

        // Attach last access for current user
        $userId = $request->user()->id;
        foreach ($paginated->items() as $proc) {
            $last = $proc->accessLogs()->where('user_id', $userId)->orderByDesc('accessed_at')->first();
            $proc->last_accessed = $last?->accessed_at;
        }

        return response()->json($paginated);
    }

    /**
     * Collect all descendant procedure IDs under a given parent.
     */
    private function collectDescendantIds(int $parentId): array
    {
        $ids = [$parentId];
        $childIds = Procedure::where('parent_id', $parentId)->pluck('id')->toArray();
        foreach ($childIds as $childId) {
            $ids = array_merge($ids, $this->collectDescendantIds($childId));
        }
        return $ids;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'requirements' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'owner_id' => ['required', 'exists:users,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['exists:procedure_tags,id'],
        ]);

        $validated['identifier'] = Procedure::nextIdentifier();
        $validated['version'] = 1;

        $procedure = Procedure::create(collect($validated)->except(['tag_ids'])->toArray());

        if (!empty($validated['tag_ids'])) {
            $procedure->tags()->sync($validated['tag_ids']);
        }

        $procedure->load(['owner:id,name', 'tags:id,name,color']);

        // Save initial version snapshot
        ProcedureVersion::create([
            'procedure_id' => $procedure->id,
            'version' => 1,
            'title' => $procedure->title,
            'purpose' => $procedure->purpose,
            'requirements' => $procedure->requirements,
            'content' => $procedure->content,
            'change_reason' => 'Initial version',
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        return response()->json($procedure, 201);
    }

    public function show(Request $request, Procedure $procedure)
    {
        $procedure->load([
            'owner:id,name',
            'tags:id,name,color',
            'faqItems' => fn($q) => $q->with(['author:id,name', 'replies' => fn($r) => $r->with('author:id,name')->orderBy('created_at')]),
            'responseTemplates',
            'feedbacks' => fn($q) => $q->with('user:id,name')->orderByDesc('created_at'),
            'ownerChanges' => fn($q) => $q->with(['oldOwner:id,name', 'newOwner:id,name', 'changedBy:id,name'])->orderByDesc('created_at'),
        ]);

        // Log access
        ProcedureAccessLog::create([
            'procedure_id' => $procedure->id,
            'user_id' => $request->user()->id,
            'version_read' => $procedure->version,
            'accessed_at' => now(),
        ]);

        // Auto mark-as-read
        ProcedureUserRead::upsert(
            ['procedure_id' => $procedure->id, 'user_id' => $request->user()->id, 'version_read' => $procedure->version, 'read_at' => now()],
            ['procedure_id', 'user_id'],
            ['version_read', 'read_at']
        );

        // Attach last access per user
        $lastAccess = $procedure->accessLogs()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('accessed_at')
            ->skip(1)->first();
        $procedure->previous_access = $lastAccess?->accessed_at;

        // Include draft info for owner/admin
        $isOwnerOrAdmin = $request->user()->id === $procedure->owner_id || $request->user()->is_admin;
        $procedure->can_edit = $isOwnerOrAdmin;

        // Include latest version's change_reason
        $latestVersion = $procedure->versions()->where('version', $procedure->version)->first();
        $procedure->change_reason = $latestVersion?->change_reason;
        $procedure->version_count = $procedure->version; // max version for navigation

        return response()->json($procedure);
    }

    public function update(Request $request, Procedure $procedure)
    {
        // Owner-only check
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->is_admin) {
            return response()->json(['message' => 'Only the procedure owner can edit this procedure.'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'requirements' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'owner_id' => ['sometimes', 'exists:users,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['exists:procedure_tags,id'],
        ]);

        // Bump version if content changed
        if (isset($validated['content']) && $validated['content'] !== $procedure->content) {
            $validated['version'] = $procedure->version + 1;
        }

        $procedure->update(collect($validated)->except(['tag_ids'])->toArray());

        if (isset($validated['tag_ids'])) {
            $procedure->tags()->sync($validated['tag_ids']);
        }

        $procedure->load(['owner:id,name', 'tags:id,name,color']);
        return response()->json($procedure);
    }

    /**
     * Save draft without publishing.
     */
    public function saveDraft(Request $request, Procedure $procedure)
    {
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->is_admin) {
            return response()->json(['message' => 'Only the procedure owner can edit this procedure.'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'requirements' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['exists:procedure_tags,id'],
        ]);

        $procedure->update([
            'has_draft' => true,
            'draft_title' => $validated['title'],
            'draft_purpose' => $validated['purpose'] ?? null,
            'draft_requirements' => $validated['requirements'] ?? null,
            'draft_content' => $validated['content'] ?? null,
        ]);

        if (isset($validated['tag_ids'])) {
            $procedure->tags()->sync($validated['tag_ids']);
        }

        $procedure->load(['owner:id,name', 'tags:id,name,color']);
        return response()->json($procedure);
    }

    /**
     * Publish draft as new version.
     */
    public function publishDraft(Request $request, Procedure $procedure)
    {
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->is_admin) {
            return response()->json(['message' => 'Only the procedure owner can publish this procedure.'], 403);
        }

        if (!$procedure->has_draft) {
            return response()->json(['message' => 'No draft to publish.'], 422);
        }

        $validated = $request->validate([
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);

        $newVersion = $procedure->version + 1;

        $procedure->update([
            'title' => $procedure->draft_title,
            'purpose' => $procedure->draft_purpose,
            'requirements' => $procedure->draft_requirements,
            'content' => $procedure->draft_content,
            'version' => $newVersion,
            'has_draft' => false,
            'draft_title' => null,
            'draft_purpose' => null,
            'draft_requirements' => null,
            'draft_content' => null,
        ]);

        // Save version snapshot
        ProcedureVersion::create([
            'procedure_id' => $procedure->id,
            'version' => $newVersion,
            'title' => $procedure->title,
            'purpose' => $procedure->purpose,
            'requirements' => $procedure->requirements,
            'content' => $procedure->content,
            'change_reason' => $validated['change_reason'],
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        // Mark open feedbacks for previous versions as resolved
        $procedure->feedbacks()
            ->where('status', 'open')
            ->where('for_version', '<', $newVersion)
            ->update(['status' => 'resolved']);

        $procedure->load(['owner:id,name', 'tags:id,name,color']);
        return response()->json($procedure);
    }

    /**
     * Discard draft without publishing.
     */
    public function discardDraft(Request $request, Procedure $procedure)
    {
        if ($request->user()->id !== $procedure->owner_id && !$request->user()->is_admin) {
            return response()->json(['message' => 'Only the procedure owner can discard the draft.'], 403);
        }

        $procedure->update([
            'has_draft' => false,
            'draft_title' => null,
            'draft_purpose' => null,
            'draft_requirements' => null,
            'draft_content' => null,
        ]);

        return response()->json(['message' => 'Draft discarded.']);
    }

    /**
     * Mark procedure as read by current user.
     */
    public function markAsRead(Request $request, Procedure $procedure)
    {
        ProcedureUserRead::upsert(
            ['procedure_id' => $procedure->id, 'user_id' => $request->user()->id, 'version_read' => $procedure->version, 'read_at' => now()],
            ['procedure_id', 'user_id'],
            ['version_read', 'read_at']
        );

        return response()->json(['message' => 'Marked as read.']);
    }

    public function destroy(Procedure $procedure)
    {
        $procedure->delete();
        return response()->json(['message' => 'Procedure deleted.']);
    }

    public function syncTags(Request $request, Procedure $procedure)
    {
        $validated = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['exists:procedure_tags,id'],
        ]);

        $procedure->tags()->sync($validated['tag_ids']);
        $procedure->load('tags:id,name,color');
        return response()->json($procedure->tags);
    }

    public function accessLog(Request $request, Procedure $procedure)
    {
        $query = $procedure->accessLogs()
            ->with('user:id,name')
            ->orderByDesc('accessed_at');

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($version = $request->input('version')) {
            $query->where('version_read', $version);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('accessed_at', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('accessed_at', '<=', $to);
        }

        return response()->json($query->limit(200)->get());
    }

    /**
     * List all versions of a procedure.
     */
    public function versionsList(Procedure $procedure)
    {
        $versions = $procedure->versions()
            ->with('publisher:id,name')
            ->orderByDesc('version')
            ->get(['id', 'procedure_id', 'version', 'change_reason', 'published_by', 'published_at']);

        return response()->json($versions);
    }

    /**
     * Get a specific version of a procedure.
     */
    public function getVersion(Procedure $procedure, int $version)
    {
        $ver = $procedure->versions()
            ->with('publisher:id,name')
            ->where('version', $version)
            ->first();

        if (!$ver) {
            return response()->json(['message' => 'Version not found.'], 404);
        }

        return response()->json($ver);
    }

    /**
     * Get procedures as a tree structure.
     */
    public function tree()
    {
        $all = Procedure::select('id', 'identifier', 'title', 'parent_id', 'sort_order')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $grouped = $all->groupBy(fn($p) => $p->parent_id ?? 'root');

        $buildTree = function ($parentId) use (&$buildTree, $grouped) {
            $key = $parentId ?? 'root';
            $nodes = $grouped->get($key, collect());
            return $nodes->map(function ($proc) use ($buildTree) {
                return [
                    'id' => $proc->id,
                    'identifier' => $proc->identifier,
                    'title' => $proc->title,
                    'children' => $buildTree($proc->id),
                ];
            })->values();
        };

        return response()->json($buildTree(null));
    }

    /**
     * Move a procedure to a new parent.
     */
    public function move(Request $request, Procedure $procedure)
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:procedures,id'],
        ]);

        // Prevent circular references
        if ($validated['parent_id']) {
            $parentId = $validated['parent_id'];
            $current = Procedure::find($parentId);
            while ($current) {
                if ($current->id === $procedure->id) {
                    return response()->json(['message' => 'Cannot move a procedure under itself.'], 422);
                }
                $current = $current->parent;
            }
        }

        $procedure->update([
            'parent_id' => $validated['parent_id'],
        ]);

        return response()->json(['message' => 'Procedure moved.']);
    }

    public function changeOwner(Request $request, Procedure $procedure)
    {
        $validated = $request->validate([
            'new_owner_id' => ['required', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        if ((int) $validated['new_owner_id'] === $procedure->owner_id) {
            return response()->json(['message' => 'New owner is the same as current owner.'], 422);
        }

        ProcedureOwnerChange::create([
            'procedure_id' => $procedure->id,
            'old_owner_id' => $procedure->owner_id,
            'new_owner_id' => $validated['new_owner_id'],
            'changed_by_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        $procedure->update(['owner_id' => $validated['new_owner_id']]);

        $procedure->load('owner:id,name');

        return response()->json([
            'message' => 'Owner changed successfully.',
            'procedure' => $procedure,
        ]);
    }

    /**
     * Check which procedures a user has NOT read at the latest version.
     * Accepts: procedure_ids[] (array of IDs)
     * Returns: array of unread procedures with id, identifier, title, version.
     */
    public function checkReadStatus(Request $request)
    {
        $request->validate([
            'procedure_ids' => ['required', 'array'],
            'procedure_ids.*' => ['integer'],
        ]);

        $userId = $request->user()->id;
        $procedureIds = array_unique($request->input('procedure_ids'));

        $procedures = Procedure::whereIn('id', $procedureIds)
            ->select('id', 'identifier', 'title', 'version')
            ->get();

        $reads = ProcedureUserRead::where('user_id', $userId)
            ->whereIn('procedure_id', $procedureIds)
            ->pluck('version_read', 'procedure_id');

        $unread = [];
        foreach ($procedures as $proc) {
            $readVersion = $reads->get($proc->id);
            if (!$readVersion || $readVersion < $proc->version) {
                $unread[] = [
                    'id' => $proc->id,
                    'identifier' => $proc->identifier,
                    'title' => $proc->title,
                    'version' => $proc->version,
                    'read_version' => $readVersion,
                ];
            }
        }

        return response()->json($unread);
    }
}
