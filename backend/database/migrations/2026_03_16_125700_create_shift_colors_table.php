<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('hex', 20);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed default colors
        $defaults = [
            ['name' => 'Night', 'hex' => '#3b82f6', 'sort_order' => 1],
            ['name' => 'Day', 'hex' => '#eab308', 'sort_order' => 2],
            ['name' => 'Extra', 'hex' => '#22c55e', 'sort_order' => 3],
            ['name' => 'Urgent', 'hex' => '#ef4444', 'sort_order' => 4],
            ['name' => 'Special', 'hex' => '#a855f7', 'sort_order' => 5],
            ['name' => 'Overtime', 'hex' => '#f97316', 'sort_order' => 6],
            ['name' => 'Training', 'hex' => '#06b6d4', 'sort_order' => 7],
            ['name' => 'Custom', 'hex' => '#ec4899', 'sort_order' => 8],
        ];

        $now = now();
        foreach ($defaults as $color) {
            DB::table('shift_colors')->insert(array_merge($color, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_colors');
    }
};
