<?php

// backend/tests/Unit/Support/LikePatternTest.php

use App\Support\LikePattern;

it('wraps the term in wildcards', function () {
    expect(LikePattern::contains('abc'))->toBe('%abc%');
    expect(LikePattern::contains('John Doe'))->toBe('%John Doe%');
    expect(LikePattern::contains(''))->toBe('%%');
});

it('escapes the LIKE metacharacters so the term matches literally', function (string $term, string $pattern) {
    expect(LikePattern::contains($term))->toBe($pattern);
})->with([
    'percent' => ['50%', '%50\%%'],
    'underscore' => ['a_b', '%a\_b%'],
    'backslash' => ['a\b', '%a\\\\b%'],
    'all three' => ['%_\\', '%\%\_\\\\%'],
    'repeated' => ['100%%', '%100\%\%%'],
]);

it('leaves every other character alone, including SQL quotes and non-ASCII', function () {
    expect(LikePattern::contains("O'Brien"))->toBe("%O'Brien%");
    expect(LikePattern::contains('Żółć'))->toBe('%Żółć%');
    expect(LikePattern::contains('a*b?c[d]'))->toBe('%a*b?c[d]%');
});
