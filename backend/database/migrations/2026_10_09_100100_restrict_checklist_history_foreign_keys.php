<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety net: these used to cascade, so one stray delete of a template, group or item silently
 * wiped every past handover response. A hard delete of anything with history now fails instead.
 */
return new class extends Migration {
    /** [table, column, referenced table] */
    private const FOREIGN_KEYS = [
        ['checklist_responses', 'checklist_item_id', 'checklist_items'],
        ['checklist_items', 'checklist_group_id', 'checklist_groups'],
        ['checklist_groups', 'checklist_template_id', 'checklist_templates'],
        ['checklist_submissions', 'checklist_template_id', 'checklist_templates'],
    ];

    public function up(): void
    {
        $this->recreate(fn ($foreign) => $foreign->restrictOnDelete());
    }

    public function down(): void
    {
        $this->recreate(fn ($foreign) => $foreign->cascadeOnDelete());
    }

    private function recreate(callable $onDelete): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column, $references]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete) {
                $blueprint->dropForeign([$column]);
                $onDelete($blueprint->foreign($column)->references('id')->on($references));
            });
        }
    }
};
