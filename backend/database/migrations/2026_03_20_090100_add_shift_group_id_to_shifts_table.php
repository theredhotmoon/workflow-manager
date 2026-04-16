<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_group_id')->nullable()->after('id');
        });

        // Backfill all existing shifts with the default group
        $defaultGroupId = \DB::table('shift_groups')->where('is_default', true)->value('id');
        if ($defaultGroupId) {
            \DB::table('shifts')->whereNull('shift_group_id')->update(['shift_group_id' => $defaultGroupId]);
        }

        // Make NOT NULL via raw SQL (avoids Doctrine DBAL requirement)
        \DB::statement('ALTER TABLE shifts ALTER COLUMN shift_group_id SET NOT NULL');

        Schema::table('shifts', function (Blueprint $table) {
            // Add FK constraint
            $table->foreign('shift_group_id')->references('id')->on('shift_groups')->cascadeOnDelete();

            // Drop old unique index and create new one
            $table->dropUnique(['date', 'shift_number']);
            $table->unique(['date', 'shift_number', 'shift_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['date', 'shift_number', 'shift_group_id']);
            $table->dropForeign(['shift_group_id']);
            $table->dropColumn('shift_group_id');

            $table->unique(['date', 'shift_number']);
        });
    }
};
