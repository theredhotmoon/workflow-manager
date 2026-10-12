<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegation_user', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('custom_end');
            // status: 'pending', 'approved', 'rejected'
        });

        Schema::create('delegation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->string('action'); // e.g. 'created', 'updated', 'user_added', 'user_removed', 'user_approved', 'user_rejected', 'self_registered', 'self_unregistered', 'registration_opened', 'registration_closed'
            $table->text('details')->nullable(); // JSON or text description
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegation_logs');

        Schema::table('delegation_user', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
