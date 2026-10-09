<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates are archived and their groups/items retired instead of deleted, so past handovers
 * keep every response (see ChecklistTemplateController::update/destroy).
 */
return new class extends Migration {
    private const TABLES = ['checklist_templates', 'checklist_groups', 'checklist_items'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
