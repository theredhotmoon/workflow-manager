<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_streak_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_group_id')->constrained()->cascadeOnDelete();
            $table->string('shift_type', 50);
            $table->unsignedInteger('streak_length');
            $table->unsignedInteger('rest_days');
            $table->timestamps();

            $table->unique(['shift_group_id', 'shift_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_streak_rules');
    }
};
