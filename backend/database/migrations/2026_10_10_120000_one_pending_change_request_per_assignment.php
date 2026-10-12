<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most one pending change request per assignment (L8), enforced by a partial unique index so
 * two concurrent requests can't both be filed. Requests whose assignment was removed (link
 * nulled) and settled ones (acknowledged) don't count.
 *
 * Existing duplicates don't fail the deploy: for each assignment the newest pending request stays
 * pending and the older ones are settled (acknowledged, as removing an assignment settles its
 * requests). Nothing is deleted; how many were settled is printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settled = DB::update(<<<'SQL'
            UPDATE shift_change_requests SET status = 'acknowledged', updated_at = NOW()
            WHERE status = 'pending' AND shift_assignment_id IS NOT NULL
              AND id NOT IN (
                SELECT MAX(id) FROM shift_change_requests
                WHERE status = 'pending' AND shift_assignment_id IS NOT NULL
                GROUP BY shift_assignment_id
              )
            SQL);
        if ($settled > 0 && defined('STDERR')) {
            fwrite(STDERR, "  one_pending_change_request_per_assignment: settled {$settled} older duplicate pending request(s) (status acknowledged).\n");
        }

        DB::statement(
            'CREATE UNIQUE INDEX shift_change_requests_one_pending_per_assignment ON shift_change_requests (shift_assignment_id) '
            ."WHERE status = 'pending' AND shift_assignment_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shift_change_requests_one_pending_per_assignment');
    }
};
