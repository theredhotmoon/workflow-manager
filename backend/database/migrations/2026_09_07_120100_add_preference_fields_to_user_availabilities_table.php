<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->string('shift_type', 50)->nullable()->after('type');
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        // Every existing row was self-created — backfill so old entries don't
        // read as manager-set once the "set by" badge ships (Task 8).
        DB::table('user_availabilities')->whereNull('created_by_user_id')->update([
            'created_by_user_id' => DB::raw('user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('shift_type');
        });
    }
};
