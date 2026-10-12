<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety net: deleting a user used to cascade through everything they authored, owned or took part
 * in. Users are deactivated now; a hard delete of a user with any of this history fails instead.
 *
 * Left as they were on purpose: per-user personal data that may go with the user (alarms,
 * procedure_user_reads, user_availabilities, user_group_user, shift_change_requests,
 * newbie_periods.user_id — cascade) and the references that already null out instead of deleting
 * rows (checklist_submissions.incoming_user_id, procedure_owner_changes.old_owner_id,
 * procedure_versions.published_by — set null).
 */
return new class extends Migration
{
    /** [table, column] — all reference users.id and cascaded on delete before this migration. */
    private const FOREIGN_KEYS = [
        // Authored / owned
        ['procedures', 'owner_id'],
        ['delegations', 'created_by'],
        ['archived_delegations', 'created_by'],
        ['archived_delegations', 'archived_by'],
        ['delegation_logs', 'performed_by'],
        ['archived_delegation_logs', 'performed_by'],
        ['office_closures', 'created_by'],
        ['newbie_periods', 'created_by'],
        ['checklist_submissions', 'outgoing_user_id'],
        ['procedure_faq_items', 'created_by'],
        ['procedure_faq_replies', 'created_by'],
        ['procedure_owner_changes', 'new_owner_id'],
        ['procedure_owner_changes', 'changed_by_id'],
        ['procedure_feedbacks', 'user_id'],
        // Work history and audit trail
        ['shift_assignments', 'user_id'],
        ['on_call_assignments', 'user_id'],
        ['delegation_user', 'user_id'],
        ['archived_delegation_user', 'user_id'],
        ['procedure_access_logs', 'user_id'],
    ];

    public function up(): void
    {
        $this->recreate(fn ($foreign) => $foreign->restrictOnDelete());
    }

    public function down(): void
    {
        $this->recreate(fn ($foreign) => $foreign->cascadeOnDelete());
    }

    private function recreate(callable $onDelete): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $onDelete) {
                $blueprint->dropForeign([$column]);
                $onDelete($blueprint->foreign($column)->references('id')->on('users'));
            });
        }
    }
};
