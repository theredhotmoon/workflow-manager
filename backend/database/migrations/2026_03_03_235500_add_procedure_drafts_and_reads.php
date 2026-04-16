<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Draft fields on procedures
        Schema::table('procedures', function (Blueprint $table) {
            $table->boolean('has_draft')->default(false)->after('version');
            $table->string('draft_title')->nullable()->after('has_draft');
            $table->text('draft_purpose')->nullable()->after('draft_title');
            $table->text('draft_requirements')->nullable()->after('draft_purpose');
            $table->longText('draft_content')->nullable()->after('draft_requirements');
        });

        // Feedback version tracking + owner resolution
        Schema::table('procedure_feedbacks', function (Blueprint $table) {
            $table->unsignedInteger('for_version')->nullable()->after('status');
            $table->string('resolution')->nullable()->after('for_version'); // accepted, rejected, deferred
            $table->text('owner_comment')->nullable()->after('resolution');
        });

        // User reads tracking
        Schema::create('procedure_user_reads', function (Blueprint $table) {
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_read');
            $table->timestamp('read_at');
            $table->primary(['procedure_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_user_reads');

        Schema::table('procedure_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['for_version', 'resolution', 'owner_comment']);
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn(['has_draft', 'draft_title', 'draft_purpose', 'draft_requirements', 'draft_content']);
        });
    }
};
