<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 60);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('short_message');
            $table->text('details')->nullable();
            $table->string('link_url')->nullable();
            $table->string('link_label')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarms');
    }
};
