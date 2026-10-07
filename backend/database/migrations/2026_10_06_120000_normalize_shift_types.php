<?php

use App\Support\ShiftType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shift and streak-rule types are now normalised on write (trim + lower-case). Bring existing
 * rows in line. Two rules in one group that only differ by case would collide on
 * (shift_group_id, shift_type); abort instead of deleting either, so an admin can merge them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('shift_streak_rules')->orderBy('id')->get(['id', 'shift_group_id', 'shift_type']);

        $seen = [];
        foreach ($rules as $rule) {
            $normalized = ShiftType::normalize($rule->shift_type);
            if ($normalized === null) {
                throw new RuntimeException("Cannot normalise streak rule {$rule->id} in group {$rule->shift_group_id}: its shift_type is blank.");
            }
            $key = "{$rule->shift_group_id}|{$normalized}";
            if (isset($seen[$key])) {
                throw new RuntimeException(
                    "Cannot normalise streak rule types: group {$rule->shift_group_id} has rules '{$seen[$key]}' and '{$rule->shift_type}' "
                    ."that both normalise to '{$normalized}'. Merge them manually, then re-run the migration."
                );
            }
            $seen[$key] = $rule->shift_type;
        }

        DB::transaction(function () use ($rules) {
            foreach ($rules as $rule) {
                $normalized = ShiftType::normalize($rule->shift_type);
                if ($normalized !== $rule->shift_type) {
                    DB::table('shift_streak_rules')->where('id', $rule->id)->update(['shift_type' => $normalized]);
                }
            }

            DB::table('shifts')->whereNotNull('shift_type')->chunkById(1000, function ($shifts) {
                foreach ($shifts as $shift) {
                    $normalized = ShiftType::normalize($shift->shift_type);
                    if ($normalized !== $shift->shift_type) {
                        DB::table('shifts')->where('id', $shift->id)->update(['shift_type' => $normalized]);
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // Normalisation is lossy (original casing is not kept); nothing to restore.
    }
};
