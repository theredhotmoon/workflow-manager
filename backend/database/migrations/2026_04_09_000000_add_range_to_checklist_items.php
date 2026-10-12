<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->decimal('min_value', 12, 4)->nullable()->after('type');
            $table->decimal('max_value', 12, 4)->nullable()->after('min_value');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn(['min_value', 'max_value']);
        });
    }
};
