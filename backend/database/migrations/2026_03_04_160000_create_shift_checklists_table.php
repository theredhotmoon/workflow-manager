<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1. Create the new pivot table
        Schema::create('shift_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('deadline_minutes')->nullable();
            $table->string('deadline_reference', 20)->default('shift_start'); // shift_start or shift_end
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 2. Add submitted_on_time to checklist_submissions
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->boolean('submitted_on_time')->nullable()->after('confirmed_at');
        });

        // 3. Migrate existing data from shifts.checklist_template_id into shift_checklists
        if (Schema::hasColumn('shifts', 'checklist_template_id')) {
            $shifts = DB::table('shifts')
                ->whereNotNull('checklist_template_id')
                ->get(['id', 'checklist_template_id', 'checklist_required']);

            foreach ($shifts as $shift) {
                DB::table('shift_checklists')->insert([
                    'shift_id' => $shift->id,
                    'checklist_template_id' => $shift->checklist_template_id,
                    'is_required' => $shift->checklist_required ?? false,
                    'deadline_minutes' => null,
                    'deadline_reference' => 'shift_start',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 4. Drop old columns from shifts
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropForeign(['checklist_template_id']);
                $table->dropColumn(['checklist_template_id', 'checklist_required']);
            });
        }
    }

    public function down(): void
    {
        // Re-add old columns
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('checklist_template_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('checklist_required')->default(false);
        });

        // Migrate data back (first entry per shift)
        $pivotData = DB::table('shift_checklists')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('shift_id');

        foreach ($pivotData as $shiftId => $entries) {
            $first = $entries->first();
            DB::table('shifts')->where('id', $shiftId)->update([
                'checklist_template_id' => $first->checklist_template_id,
                'checklist_required' => $first->is_required,
            ]);
        }

        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_on_time');
        });

        Schema::dropIfExists('shift_checklists');
    }
};
