<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->enum('settlement_mode', ['weekly', 'monthly', 'quarterly', 'custom'])->nullable()->after('sort_order');
            $table->date('settlement_anchor_date')->nullable()->after('settlement_mode');
            $table->unsignedInteger('settlement_custom_length_days')->nullable()->after('settlement_anchor_date');
            $table->decimal('weekly_hour_cap', 5, 2)->nullable()->after('settlement_custom_length_days');
        });

        DB::statement(
            "UPDATE shift_groups SET settlement_mode = ?, weekly_hour_cap = ?, settlement_anchor_date = DATE(created_at) WHERE settlement_mode IS NULL",
            ['monthly', 48]
        );
    }

    public function down(): void
    {
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->dropColumn(['settlement_mode', 'settlement_anchor_date', 'settlement_custom_length_days', 'weekly_hour_cap']);
        });
    }
};
