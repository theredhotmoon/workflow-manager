<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_submission_id')->constrained('checklist_submissions')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->boolean('confirmed')->default(false);
            $table->text('group_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('checklist_responses');
    }
};
