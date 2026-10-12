<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7: a pending handover whose incoming user was deactivated can be handed to another user by an
 * admin. The handover keeps who it was handed over from, who did it and when. Users are
 * deactivated, not deleted, so both references restrict deletes like the other authored-by ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->foreignId('previous_incoming_user_id')->nullable()->after('incoming_user_id')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('incoming_reassigned_by_id')->nullable()->after('previous_incoming_user_id')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('incoming_reassigned_at')->nullable()->after('incoming_reassigned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_incoming_user_id');
            $table->dropConstrainedForeignId('incoming_reassigned_by_id');
            $table->dropColumn('incoming_reassigned_at');
        });
    }
};
