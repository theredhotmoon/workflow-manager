<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill version snapshots for existing procedures that have none
        $procedures = DB::table('procedures')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('procedure_versions')
                    ->whereColumn('procedure_versions.procedure_id', 'procedures.id');
            })
            ->get();

        foreach ($procedures as $proc) {
            DB::table('procedure_versions')->insert([
                'procedure_id' => $proc->id,
                'version' => $proc->version,
                'title' => $proc->title,
                'purpose' => $proc->purpose,
                'requirements' => $proc->requirements,
                'content' => $proc->content,
                'change_reason' => 'Initial version',
                'published_by' => $proc->owner_id,
                'published_at' => $proc->created_at,
            ]);
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
};
