<?php

// backend/tests/Unit/Support/ShiftTypeTest.php

use App\Models\Shift;
use App\Models\ShiftStreakRule;
use App\Support\ShiftType;

it('trims and lowercases a shift type', function (string $input, string $expected) {
    expect(ShiftType::normalize($input))->toBe($expected);
})->with([
    'already normal' => ['night', 'night'],
    'capitalised' => ['Night', 'night'],
    'shouting' => ['NIGHT', 'night'],
    'surrounding spaces' => ['  night  ', 'night'],
    'tabs and newlines' => ["\tnight\n", 'night'],
    'inner spaces kept' => ['Late  Night', 'late  night'],
    'non-ASCII letters' => ['ŻÓŁTY DYŻUR', 'żółty dyżur'],
    'German sharp s and umlaut' => ['FRÜH', 'früh'],
    'digits untouched' => ['Shift 2', 'shift 2'],
]);

it('turns an empty or blank value into null', function (?string $input) {
    expect(ShiftType::normalize($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'whitespace' => ["\t\n "],
]);

it('is applied by the models when the attribute is set', function () {
    expect((new Shift(['shift_type' => ' Night ']))->shift_type)->toBe('night');
    expect((new Shift(['shift_type' => '  ']))->shift_type)->toBeNull();
    expect((new ShiftStreakRule(['shift_type' => 'DAY']))->shift_type)->toBe('day');
    expect((new ShiftStreakRule(['shift_type' => null]))->shift_type)->toBeNull();
});
