<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 20)->unique();
            $table->string('title');
            $table->text('purpose')->nullable();
            $table->text('requirements')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('procedure_tag', function (Blueprint $table) {
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('procedure_tags')->cascadeOnDelete();
            $table->primary(['procedure_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_tag');
        Schema::dropIfExists('procedures');
    }
};
