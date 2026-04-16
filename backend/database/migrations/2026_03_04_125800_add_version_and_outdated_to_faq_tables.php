<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('procedure_faq_items', function (Blueprint $table) {
            $table->unsignedInteger('for_version')->nullable()->after('question');
            $table->boolean('is_outdated')->default(false)->after('for_version');
        });

        Schema::table('procedure_faq_replies', function (Blueprint $table) {
            $table->unsignedInteger('for_version')->nullable()->after('body');
            $table->boolean('is_outdated')->default(false)->after('for_version');
        });
    }

    public function down(): void
    {
        Schema::table('procedure_faq_items', function (Blueprint $table) {
            $table->dropColumn(['for_version', 'is_outdated']);
        });
        Schema::table('procedure_faq_replies', function (Blueprint $table) {
            $table->dropColumn(['for_version', 'is_outdated']);
        });
    }
};
