<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('shift_number');
            $table->timestamps();

            $table->unique(['date', 'shift_number']);
            $table->index('date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('shifts');
    }
};
