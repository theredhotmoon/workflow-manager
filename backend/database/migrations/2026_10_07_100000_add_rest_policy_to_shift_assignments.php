<?php
// backend/database/migrations/2026_10_07_100000_add_rest_policy_to_shift_assignments.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streak state is now replayed from assignments (see StreakTimeline). A manager who assigns
 * someone on a rest day chooses what that assignment means; null ≡ not_counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->string('rest_policy', 20)->nullable();
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['shift_group_id', 'date'], 'shifts_group_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_group_date_index');
        });
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('rest_policy');
        });
    }
};
