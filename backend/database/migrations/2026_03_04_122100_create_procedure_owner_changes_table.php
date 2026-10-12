<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_owner_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('changed_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_owner_changes');
    }
};
