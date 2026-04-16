<?php

namespace App\Http\Controllers;

use App\Models\ProcedureTag;
use Illuminate\Http\Request;

class ProcedureTagController extends Controller
{
    public function index()
    {
        return response()->json(ProcedureTag::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:procedure_tags'],
            'color' => ['sometimes', 'string', 'max:7'],
        ]);

        $tag = ProcedureTag::create($validated);
        return response()->json($tag, 201);
    }

    public function update(Request $request, ProcedureTag $procedureTag)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', 'unique:procedure_tags,name,' . $procedureTag->id],
            'color' => ['sometimes', 'string', 'max:7'],
        ]);

        $procedureTag->update($validated);
        return response()->json($procedureTag);
    }

    public function destroy(ProcedureTag $procedureTag)
    {
        $procedureTag->delete();
        return response()->json(['message' => 'Tag deleted.']);
    }
}
