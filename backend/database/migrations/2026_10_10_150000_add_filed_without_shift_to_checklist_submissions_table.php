<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F8: whether a handover was filed without a shift (the form's shift is optional). Only such a
 * handover clears that day's alert for its template by date; one whose shift was removed later
 * (shift_id nulled) no longer covers another slot of the day.
 *
 * Existing rows without a shift can't be told apart (filed without one, or their shift was removed
 * since), so they are marked as filed without one: they keep clearing alerts as they did.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->boolean('filed_without_shift')->default(false)->after('shift_id');
        });
        DB::table('checklist_submissions')->whereNull('shift_id')->update(['filed_without_shift' => true]);
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropColumn('filed_without_shift');
        });
    }
};
