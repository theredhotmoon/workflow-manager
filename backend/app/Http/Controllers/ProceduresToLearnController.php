<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Models\ProcedureUserRead;
use Illuminate\Http\Request;

class ProceduresToLearnController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // Get all procedures where user hasn't read the latest version
        $procedures = Procedure::with('owner:id,name')
            ->select('procedures.*')
            ->leftJoin('procedure_user_reads', function ($join) use ($userId) {
                $join->on('procedures.id', '=', 'procedure_user_reads.procedure_id')
                    ->where('procedure_user_reads.user_id', '=', $userId);
            })
            ->where(function ($q) {
                $q->whereNull('procedure_user_reads.version_read')
                    ->orWhereColumn('procedure_user_reads.version_read', '<', 'procedures.version');
            })
            ->orderByDesc('procedures.updated_at')
            ->get()
            ->map(function ($proc) {
                return [
                    'id' => $proc->id,
                    'identifier' => $proc->identifier,
                    'title' => $proc->title,
                    'version' => $proc->version,
                    'owner' => $proc->owner,
                    'updated_at' => $proc->updated_at,
                ];
            });

        return response()->json($procedures);
    }
}
