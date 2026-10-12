<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who acknowledged a change request, and when. Both nullable: requests acknowledged before this
 * migration, and requests settled by removing their assignment or shift, have no acknowledger on
 * record (the screen shows them as acknowledged, without a name). Adding nullable columns needs no
 * backfill and leaves every existing row valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            // The manager screen lists pending requests first, newest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('acknowledged_by_id');
            $table->dropColumn('acknowledged_at');
        });
    }
};
