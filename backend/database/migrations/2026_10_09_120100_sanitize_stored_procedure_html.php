<?php

use App\Services\ProcedureHtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Procedure HTML is now sanitised on every write (ProcedureHtmlSanitizer). This cleans what was
 * stored before: published content, pending drafts and every version snapshot. Only rows that
 * change are written, and cleaning is idempotent, so running it again does nothing. Nothing to undo.
 */
return new class extends Migration {
    public function up(): void
    {
        $sanitizer = app(ProcedureHtmlSanitizer::class);

        $this->clean($sanitizer, 'procedures', ['content', 'draft_content']);
        $this->clean($sanitizer, 'procedure_versions', ['content']);
    }

    public function down(): void
    {
        // Removed markup is not restored.
    }

    private function clean(ProcedureHtmlSanitizer $sanitizer, string $table, array $columns): void
    {
        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')
            // Small chunks: version snapshots can carry multi-MB pasted images inline.
            ->chunkById(10, function ($rows) use ($sanitizer, $table, $columns) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        $clean = $sanitizer->clean($row->{$column});
                        if ($clean !== $row->{$column}) {
                            $changes[$column] = $clean;
                        }
                    }
                    if ($changes) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
