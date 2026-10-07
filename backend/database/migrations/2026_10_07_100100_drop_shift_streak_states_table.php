<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Streak state is replayed from assignments (StreakTimeline); the counter table is obsolete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shift_streak_states');
    }

    public function down(): void
    {
        Schema::create('shift_streak_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_streak_rule_id')->constrained('shift_streak_rules')->cascadeOnDelete();
            $table->unsignedInteger('current_count')->default(0);
            $table->date('resting_until')->nullable();
            $table->date('last_processed_date')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shift_streak_rule_id']);
        });
    }
};
