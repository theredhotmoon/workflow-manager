<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A change request used to be deleted with its assignment (unassign, move, deactivation) or its
 * shift (day-count reduction, clear month). Both links now null out instead, and the request keeps
 * the shift's date and slot number so it still says which shift it was about.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->date('shift_date')->nullable();
            $table->unsignedSmallInteger('shift_number')->nullable();
        });

        DB::table('shift_change_requests')
            ->join('shifts', 'shifts.id', '=', 'shift_change_requests.shift_id')
            ->select('shift_change_requests.id', 'shifts.date', 'shifts.shift_number')
            ->orderBy('shift_change_requests.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('shift_change_requests')->where('id', $row->id)->update([
                        'shift_date' => $row->date,
                        'shift_number' => $row->shift_number,
                    ]);
                }
            });

        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->dropForeign(['shift_assignment_id']);
            $table->dropForeign(['shift_id']);
        });
        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_assignment_id')->nullable()->change();
            $table->unsignedBigInteger('shift_id')->nullable()->change();
            $table->foreign('shift_assignment_id')->references('id')->on('shift_assignments')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->index('shift_date');
        });
    }

    public function down(): void
    {
        // Requests whose assignment or shift is gone can't satisfy the old NOT NULL columns.
        DB::table('shift_change_requests')
            ->whereNull('shift_assignment_id')
            ->orWhereNull('shift_id')
            ->delete();

        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->dropForeign(['shift_assignment_id']);
            $table->dropForeign(['shift_id']);
            $table->dropIndex(['shift_date']);
        });
        Schema::table('shift_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_assignment_id')->nullable(false)->change();
            $table->unsignedBigInteger('shift_id')->nullable(false)->change();
            $table->foreign('shift_assignment_id')->references('id')->on('shift_assignments')->cascadeOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
            $table->dropColumn(['shift_date', 'shift_number']);
        });
    }
};
