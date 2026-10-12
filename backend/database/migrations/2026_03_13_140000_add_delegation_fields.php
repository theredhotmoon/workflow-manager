<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('destination');
            $table->boolean('registration_open')->default(true)->after('end_datetime');
        });

        Schema::table('delegation_user', function (Blueprint $table) {
            $table->date('custom_start')->nullable()->after('user_id');
            $table->date('custom_end')->nullable()->after('custom_start');
        });
    }

    public function down(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropColumn(['description', 'registration_open']);
        });

        Schema::table('delegation_user', function (Blueprint $table) {
            $table->dropColumn(['custom_start', 'custom_end']);
        });
    }
};
