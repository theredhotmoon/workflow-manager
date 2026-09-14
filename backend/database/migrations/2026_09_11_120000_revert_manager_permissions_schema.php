<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses the schema added for the manager-permissions feature
 * (2026_09_07_120000/120100/120200). Those migrations already shipped to
 * shared environments via origin/main, so they're kept in place rather than
 * deleted — this migration undoes them going forward instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('shift_type');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('shift_type');
        });

        Schema::dropIfExists('user_group_managers');
    }

    public function down(): void
    {
        Schema::create('user_group_managers', function (Blueprint $table) {
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_group_id', 'user_id']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->string('shift_type', 50)->nullable()->after('shift_number');
        });

        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->string('shift_type', 50)->nullable()->after('type');
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }
};
