<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually filed a handover: the outgoing user, or an admin filing on their behalf. Handovers
 * filed before this column existed keep null (the filer was not recorded).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->foreignId('submitted_by_user_id')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
        });
    }
};
