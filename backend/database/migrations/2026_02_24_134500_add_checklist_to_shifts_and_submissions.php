<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('checklist_required')->default(false)->after('shift_number');
            $table->foreignId('checklist_template_id')->nullable()->after('checklist_required')
                ->constrained('checklist_templates')->nullOnDelete();
        });

        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('checklist_template_id')
                ->constrained('shifts')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_template_id');
            $table->dropColumn('checklist_required');
        });
    }
};
