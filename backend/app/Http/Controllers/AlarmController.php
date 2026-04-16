<?php

namespace App\Http\Controllers;

use App\Models\Alarm;
use Illuminate\Http\Request;

class AlarmController extends Controller
{
    /**
     * Paginated list of alarms for the current user, filterable.
     */
    public function index(Request $request)
    {
        $query = Alarm::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'read') {
                $query->whereNotNull('read_at');
            } elseif ($request->status === 'unread') {
                $query->whereNull('read_at');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Latest 10 alarms for the bell dropdown.
     */
    public function latest()
    {
        $alarms = Alarm::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json($alarms);
    }

    /**
     * Count of unread alarms.
     */
    public function unreadCount()
    {
        $count = Alarm::where('user_id', auth()->id())
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a single alarm as read.
     */
    public function markRead($id)
    {
        $alarm = Alarm::where('user_id', auth()->id())->findOrFail($id);
        $alarm->markAsRead();
        return response()->json($alarm);
    }

    /**
     * Mark all alarms as read.
     */
    public function markAllRead()
    {
        Alarm::where('user_id', auth()->id())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All alarms marked as read.']);
    }
}
