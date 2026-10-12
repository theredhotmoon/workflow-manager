<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift streaks identifies "identical shifts" by shifts.shift_type, a column
 * originally added by the (since-reverted) manager-permissions feature and
 * dropped again by 2026_09_11_120000_revert_manager_permissions_schema.
 * Shift streaks now owns this column independently of that reverted feature,
 * so it must exist regardless of migration history order: present already on
 * a database that never had the revert applied, absent on one that did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'shift_type')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->string('shift_type', 50)->nullable()->after('shift_number');
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: shift streaks depends on this column, and an
        // unrelated migration's down() should not drop state another feature
        // is actively using. Dropping shift_type is handled explicitly by
        // whichever migration is reverting shift streaks itself, if ever.
    }
};
