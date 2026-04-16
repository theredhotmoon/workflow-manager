<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistTemplateController extends Controller
{
    /**
     * List all templates.
     */
    public function index()
    {
        $templates = ChecklistTemplate::withCount('groups')
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
            ->with(['groups.items.procedure:id,identifier,title'])
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    /**
     * Get a single template with all groups and items.
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
     * Update a template — full replace of groups and items.
     */
    public function update(Request $request, ChecklistTemplate $checklistTemplate)
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

        DB::transaction(function () use ($request, $checklistTemplate) {
            $checklistTemplate->update([
                'name' => $request->name,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            // Delete existing groups (cascades to items)
            $checklistTemplate->groups()->delete();

            foreach ($request->groups as $gi => $groupData) {
                $group = $checklistTemplate->groups()->create([
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
        });

        $checklistTemplate->load(['groups.items.procedure:id,identifier,title']);
        return response()->json($checklistTemplate);
    }

    /**
     * Delete a template.
     */
    public function destroy(ChecklistTemplate $checklistTemplate)
    {
        $checklistTemplate->delete();
        return response()->json(['message' => 'Template deleted successfully.']);
    }
}
