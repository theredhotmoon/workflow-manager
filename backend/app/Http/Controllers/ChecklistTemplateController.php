<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ShiftChecklist;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChecklistTemplateController extends Controller
{
    /**
     * List templates (builder, schedule pickers). Archived ones are left out unless
     * include_archived is set: the history filter still needs their names.
     */
    public function index(Request $request)
    {
        $templates = ChecklistTemplate::withCount('groups')
            ->when(!$request->boolean('include_archived'), fn ($q) => $q->notArchived())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($templates);
    }

    /**
     * List only active templates (for non-admin users filling forms).
     */
    public function active()
    {
        $templates = ChecklistTemplate::where('is_active', true)
            ->notArchived()
            ->with(['groups.items.procedure:id,identifier,title'])
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    /**
     * Get a single template with its current groups and items (retired ones are left out).
     */
    public function show(ChecklistTemplate $checklistTemplate)
    {
        $checklistTemplate->load(['groups.items.procedure:id,identifier,title']);
        return response()->json($checklistTemplate);
    }

    /**
     * Create a template with groups and items in one request.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.label' => ['required', 'string', 'max:500'],
            'groups.*.items.*.type' => ['sometimes', 'string', 'in:checkbox,text,number'],
            'groups.*.items.*.requires_confirmation' => ['sometimes', 'boolean'],
            'groups.*.items.*.procedure_id' => ['sometimes', 'nullable', 'exists:procedures,id'],
            'groups.*.items.*.min_value' => ['sometimes', 'nullable', 'numeric'],
            'groups.*.items.*.max_value' => ['sometimes', 'nullable', 'numeric', 'gte:groups.*.items.*.min_value'],
        ]);

        $template = DB::transaction(function () use ($request) {
            $template = ChecklistTemplate::create([
                'name' => $request->name,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            foreach ($request->groups as $gi => $groupData) {
                $group = $template->groups()->create([
                    'name' => $groupData['name'],
                    'sort_order' => $gi,
                ]);

                foreach ($groupData['items'] as $ii => $itemData) {
                    $type = $itemData['type'] ?? 'checkbox';
                    $group->items()->create([
                        'label' => $itemData['label'],
                        'type' => $type,
                        'requires_confirmation' => $itemData['requires_confirmation'] ?? false,
                        'sort_order' => $ii,
                        'procedure_id' => $itemData['procedure_id'] ?? null,
                        'min_value' => $type === 'number' ? ($itemData['min_value'] ?? null) : null,
                        'max_value' => $type === 'number' ? ($itemData['max_value'] ?? null) : null,
                    ]);
                }
            }

            return $template;
        });

        $template->load(['groups.items.procedure:id,identifier,title']);
        return response()->json($template, 201);
    }

    /**
     * Update a template. Groups and items sent with an id are updated in place, ones without an id
     * are added, and existing ones left out are retired (archived_at) rather than deleted: past
     * submissions keep their responses, and history still shows those items.
     */
    public function update(Request $request, ChecklistTemplate $checklistTemplate)
    {
        if ($checklistTemplate->archived_at !== null) {
            return response()->json(['message' => 'This template is archived and can no longer be edited.'], 422);
        }

        $groupIds = $checklistTemplate->allGroups()->pluck('id')->all();
        $itemIds = ChecklistItem::whereIn('checklist_group_id', $groupIds)->pluck('id')->all();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'groups' => ['required', 'array', 'min:1'],
            // Ids must be this template's own rows; anything else would move another template's history.
            'groups.*.id' => ['sometimes', 'nullable', 'integer', 'distinct', Rule::in($groupIds)],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.id' => ['sometimes', 'nullable', 'integer', 'distinct', Rule::in($itemIds)],
            'groups.*.items.*.label' => ['required', 'string', 'max:500'],
            'groups.*.items.*.type' => ['sometimes', 'string', 'in:checkbox,text,number'],
            'groups.*.items.*.requires_confirmation' => ['sometimes', 'boolean'],
            'groups.*.items.*.procedure_id' => ['sometimes', 'nullable', 'exists:procedures,id'],
            'groups.*.items.*.min_value' => ['sometimes', 'nullable', 'numeric'],
            'groups.*.items.*.max_value' => ['sometimes', 'nullable', 'numeric', 'gte:groups.*.items.*.min_value'],
        ]);

        DB::transaction(function () use ($request, $checklistTemplate, $groupIds, $itemIds) {
            $fields = [
                'name' => $request->name,
                'description' => $request->description,
            ];
            // Only an explicit is_active changes it: saving must not re-activate a deactivated template.
            if ($request->has('is_active')) {
                $fields['is_active'] = $request->boolean('is_active');
            }
            $checklistTemplate->update($fields);

            $keptGroupIds = [];
            $keptItemIds = [];
            foreach ($request->groups as $gi => $groupData) {
                $groupFields = ['name' => $groupData['name'], 'sort_order' => $gi, 'archived_at' => null];
                if (!empty($groupData['id'])) {
                    $group = ChecklistGroup::findOrFail($groupData['id']);
                    $group->forceFill($groupFields)->save();
                } else {
                    $group = ChecklistGroup::create(['checklist_template_id' => $checklistTemplate->id] + $groupFields);
                }
                $keptGroupIds[] = $group->id;

                foreach ($groupData['items'] as $ii => $itemData) {
                    $type = $itemData['type'] ?? 'checkbox';
                    $itemFields = [
                        'checklist_group_id' => $group->id,
                        'label' => $itemData['label'],
                        'type' => $type,
                        'requires_confirmation' => $itemData['requires_confirmation'] ?? false,
                        'sort_order' => $ii,
                        'procedure_id' => $itemData['procedure_id'] ?? null,
                        'min_value' => $type === 'number' ? ($itemData['min_value'] ?? null) : null,
                        'max_value' => $type === 'number' ? ($itemData['max_value'] ?? null) : null,
                    ];
                    if (!empty($itemData['id'])) {
                        $item = ChecklistItem::findOrFail($itemData['id']);
                        $item->forceFill($itemFields + ['archived_at' => null])->save();
                    } else {
                        $item = ChecklistItem::create($itemFields);
                    }
                    $keptItemIds[] = $item->id;
                }
            }

            // Retire what the builder no longer sends. Never delete: past responses point at these rows.
            $now = now();
            ChecklistItem::whereIn('id', array_diff($itemIds, $keptItemIds))
                ->whereNull('archived_at')
                ->update(['archived_at' => $now]);
            ChecklistGroup::whereIn('id', array_diff($groupIds, $keptGroupIds))
                ->whereNull('archived_at')
                ->update(['archived_at' => $now]);
        });

        $checklistTemplate->load(['groups.items.procedure:id,identifier,title']);
        return response()->json($checklistTemplate);
    }

    /**
     * Archive a template (the route stays DELETE, but nothing is deleted). It disappears from the
     * builder, filling and shift pickers; its submissions and responses stay. Shifts from tomorrow
     * on (business timezone) stop requiring it; past shifts and today's, whose handover may already
     * be submitted, keep what they had.
     */
    public function destroy(ChecklistTemplate $checklistTemplate)
    {
        DB::transaction(function () use ($checklistTemplate) {
            if ($checklistTemplate->archived_at === null) {
                $checklistTemplate->forceFill(['archived_at' => now()])->save();
            }

            ShiftChecklist::where('checklist_template_id', $checklistTemplate->id)
                ->whereHas('shift', fn ($q) => $q->where('date', '>', BusinessDate::todayString()))
                ->delete();
        });

        return response()->json(['message' => 'Template archived.']);
    }
}
