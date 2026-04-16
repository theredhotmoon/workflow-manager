<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('procedure_access_logs', function (Blueprint $table) {
            $table->unsignedInteger('version_read')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('procedure_access_logs', function (Blueprint $table) {
            $table->dropColumn('version_read');
        });
    }
};
