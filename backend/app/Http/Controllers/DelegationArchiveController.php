<?php

namespace App\Http\Controllers;

use App\Models\ArchivedDelegation;
use App\Models\ArchivedDelegationLog;
use App\Models\Delegation;
use App\Models\DelegationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DelegationArchiveController extends Controller
{
    /**
     * List archived delegations with optional filters.
     */
    public function index(Request $request)
    {
        $query = ArchivedDelegation::with(['users:id,name,email', 'creator:id,name', 'archivedByUser:id,name']);

        // Filter by date range (start)
        if ($from = $request->input('start_from')) {
            $query->where('start_datetime', '>=', $from);
        }
        if ($to = $request->input('start_to')) {
            $query->where('start_datetime', '<=', $to . ' 23:59:59');
        }

        // Filter by date range (end)
        if ($from = $request->input('end_from')) {
            $query->where('end_datetime', '>=', $from);
        }
        if ($to = $request->input('end_to')) {
            $query->where('end_datetime', '<=', $to . ' 23:59:59');
        }

        // Filter by destination
        if ($dest = $request->input('destination')) {
            $query->where('destination', 'like', "%{$dest}%");
        }

        // Filter by employee
        if ($empId = $request->input('employee_id')) {
            $query->whereHas('users', function ($q) use ($empId) {
                $q->where('user_id', $empId);
            });
        }

        $delegations = $query->orderByDesc('archived_at')->get();
        return response()->json($delegations);
    }

    /**
     * Get logs for an archived delegation.
     */
    public function logs(ArchivedDelegation $archivedDelegation)
    {
        $logs = $archivedDelegation->logs()->with('performer:id,name')->get();
        return response()->json($logs);
    }

    /**
     * Archive a delegation — move data to archive tables.
     */
    public function archive(Request $request, Delegation $delegation)
    {
        return DB::transaction(function () use ($request, $delegation) {
            // Create archived copy. forceFill (not create) so created_at /
            // updated_at are copied verbatim: they are not mass-assignable, and
            // Eloquent only stamps "now" on timestamp columns that are not dirty.
            $archived = (new ArchivedDelegation())->forceFill([
                'original_id' => $delegation->id,
                'name' => $delegation->name,
                'destination' => $delegation->destination,
                'description' => $delegation->description,
                'start_datetime' => $delegation->start_datetime,
                'end_datetime' => $delegation->end_datetime,
                'registration_open' => $delegation->registration_open,
                'created_by' => $delegation->created_by,
                'archived_by' => $request->user()->id,
                'created_at' => $delegation->getRawOriginal('created_at'),
                'updated_at' => $delegation->getRawOriginal('updated_at'),
            ]);
            $archived->save();

            // Copy users with pivot data (pivot timestamps included)
            foreach ($delegation->users as $user) {
                $archived->users()->attach($user->id, self::pivotCopy($user->pivot));
            }

            // Copy logs with their original created_at
            $this->copyLogs(
                DelegationLog::where('delegation_id', $delegation->id)->orderBy('id')->get(),
                ArchivedDelegationLog::class,
                ['archived_delegation_id' => $archived->id],
            );

            // Add archive log entry
            ArchivedDelegationLog::create([
                'archived_delegation_id' => $archived->id,
                'performed_by' => $request->user()->id,
                'action' => 'archived',
                'details' => "Archived by {$request->user()->name}",
            ]);

            // Delete original
            $delegation->delete();

            $archived->load(['users:id,name,email', 'creator:id,name']);
            return response()->json($archived);
        });
    }

    /**
     * Unarchive — move data back to active tables.
     */
    public function unarchive(Request $request, ArchivedDelegation $archivedDelegation)
    {
        return DB::transaction(function () use ($request, $archivedDelegation) {
            // Create active delegation, keeping its original timestamps (see archive()).
            $delegation = (new Delegation())->forceFill([
                'name' => $archivedDelegation->name,
                'destination' => $archivedDelegation->destination,
                'description' => $archivedDelegation->description,
                'start_datetime' => $archivedDelegation->start_datetime,
                'end_datetime' => $archivedDelegation->end_datetime,
                'registration_open' => $archivedDelegation->registration_open,
                'created_by' => $archivedDelegation->created_by,
                'created_at' => $archivedDelegation->getRawOriginal('created_at'),
                'updated_at' => $archivedDelegation->getRawOriginal('updated_at'),
            ]);
            $delegation->save();

            // Copy users
            foreach ($archivedDelegation->users as $user) {
                $delegation->users()->attach($user->id, self::pivotCopy($user->pivot));
            }

            // Copy logs back with their original created_at
            $this->copyLogs(
                ArchivedDelegationLog::where('archived_delegation_id', $archivedDelegation->id)->orderBy('id')->get(),
                DelegationLog::class,
                ['delegation_id' => $delegation->id],
            );

            // Add unarchive log
            DelegationLog::create([
                'delegation_id' => $delegation->id,
                'performed_by' => $request->user()->id,
                'action' => 'unarchived',
                'details' => "Unarchived by {$request->user()->name}",
            ]);

            // Delete archived copy
            $archivedDelegation->delete();

            $delegation->load(['users:id,name,email', 'creator:id,name']);
            return response()->json($delegation);
        });
    }

    /**
     * Copy log rows into the other log table with one bulk insert. A raw insert
     * (rather than Model::create) writes created_at verbatim: the log models
     * have no Eloquent timestamps and created_at is not fillable, so create()
     * silently dropped it and the column default stamped "now" on every row.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $logs
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $targetModel
     * @param  array<string, int>  $parentKey
     */
    private function copyLogs($logs, string $targetModel, array $parentKey): void
    {
        if ($logs->isEmpty()) {
            return;
        }

        $targetModel::query()->insert($logs->map(fn ($log) => $parentKey + [
            'performed_by' => $log->performed_by,
            'action' => $log->action,
            'details' => $log->details,
            'created_at' => $log->getRawOriginal('created_at'),
        ])->all());
    }

    /**
     * Pivot columns to carry over between delegation_user and
     * archived_delegation_user, including the pivot's own timestamps.
     *
     * @return array<string, mixed>
     */
    private static function pivotCopy($pivot): array
    {
        return [
            'custom_start' => $pivot->custom_start,
            'custom_end' => $pivot->custom_end,
            'status' => $pivot->status,
            'created_at' => $pivot->getRawOriginal('created_at'),
            'updated_at' => $pivot->getRawOriginal('updated_at'),
        ];
    }

    /**
     * Get unique destinations for filter dropdown.
     */
    public function destinations()
    {
        $destinations = ArchivedDelegation::distinct()->pluck('destination');
        return response()->json($destinations);
    }
}
