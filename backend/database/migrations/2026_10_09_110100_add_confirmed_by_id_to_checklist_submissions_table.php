<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who confirmed a handover (only its incoming user may). Users are deactivated, not deleted, so like
 * the other authored-by references this one restricts deletes instead of losing the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->foreignId('confirmed_by_id')->nullable()->after('confirmed_at')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by_id');
        });
    }
};
