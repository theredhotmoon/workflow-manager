<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Models\ProcedureResponseTemplate;
use Illuminate\Http\Request;

class ProcedureResponseTemplateController extends Controller
{
    public function index(Procedure $procedure)
    {
        return response()->json(
            $procedure->responseTemplates()->orderBy('language')->orderBy('title')->get()
        );
    }

    public function store(Request $request, Procedure $procedure)
    {
        $procedure->refuseWritesIfArchived($request->user());
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'language' => ['sometimes', 'string', 'max:5'],
        ]);

        $template = $procedure->responseTemplates()->create($validated);

        return response()->json($template, 201);
    }

    public function update(Request $request, ProcedureResponseTemplate $template)
    {
        $template->procedure->refuseWritesIfArchived($request->user());
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'language' => ['sometimes', 'string', 'max:5'],
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    public function destroy(Request $request, ProcedureResponseTemplate $template)
    {
        $template->procedure->refuseWritesIfArchived($request->user());
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
