<?php

// backend/tests/Unit/Support/AdvisoryLockTest.php

use App\Support\AdvisoryLock;

it('derives the lock key as the CRC32 of the name', function () {
    expect(AdvisoryLock::key('procedure-identifier'))->toBe(crc32('procedure-identifier'));
    expect(AdvisoryLock::key('auto-fill:3:2026-10'))->toBe(crc32('auto-fill:3:2026-10'));
});

it('is deterministic and tells names apart', function () {
    expect(AdvisoryLock::key('a'))->toBe(AdvisoryLock::key('a'));
    expect(AdvisoryLock::key('a'))->not->toBe(AdvisoryLock::key('b'));
    expect(AdvisoryLock::key('auto-fill:3:2026-10'))->not->toBe(AdvisoryLock::key('auto-fill:3:2026-11'));
    expect(AdvisoryLock::key(''))->toBe(0);
});

it('fits a PostgreSQL bigint and is never negative', function () {
    foreach (['procedure-identifier', 'auto-fill:1:2029-01', str_repeat('x', 500), 'żółć'] as $name) {
        $key = AdvisoryLock::key($name);
        expect($key)->toBeInt();
        expect($key)->toBeGreaterThanOrEqual(0);
        expect($key)->toBeLessThanOrEqual(0xFFFFFFFF);
    }
});
