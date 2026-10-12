<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift groups and procedures are archived instead of deleted, so their history (shifts, hours,
 * on-call duties; versions, FAQ, reads, access log) stays. See ShiftGroupController::destroy and
 * ProcedureController::destroy.
 */
return new class extends Migration
{
    private const TABLES = ['shift_groups', 'procedures'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
