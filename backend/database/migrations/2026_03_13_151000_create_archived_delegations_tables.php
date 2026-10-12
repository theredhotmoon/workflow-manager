<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id')->nullable();
            $table->string('name');
            $table->string('destination');
            $table->text('description')->nullable();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->boolean('registration_open')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('archived_at')->useCurrent();
            $table->foreignId('archived_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('archived_delegation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archived_delegation_id')->constrained('archived_delegations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('custom_start')->nullable();
            $table->date('custom_end')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });

        Schema::create('archived_delegation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archived_delegation_id')->constrained('archived_delegations')->cascadeOnDelete();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_delegation_logs');
        Schema::dropIfExists('archived_delegation_user');
        Schema::dropIfExists('archived_delegations');
    }
};
