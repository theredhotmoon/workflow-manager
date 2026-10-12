<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('destination');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('delegation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['delegation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegation_user');
        Schema::dropIfExists('delegations');
    }
};
