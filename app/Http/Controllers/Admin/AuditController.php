<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail (FR-M12-05).
 *
 * Read-only by construction: the model refuses updates and deletes, and there
 * is deliberately no write path here. An audit log with an edit button is not
 * an audit log.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $events = DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->when($request->filled('action'), fn ($q) => $q->where('audit_events.action', 'like', $request->query('action').'%'))
            ->when($request->filled('subject'), fn ($q) => $q->where('audit_events.subject_type', $request->query('subject')))
            ->when($request->filled('q'), fn ($q) => $q->where('users.name', 'like', '%'.$request->query('q').'%'))
            ->orderByDesc('audit_events.id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($row) => $row);

        return view('admin.audit', [
            'events'  => $events,
            'actions' => DB::table('audit_events')
                ->selectRaw("SUBSTRING_INDEX(action, '.', 1) AS prefix, COUNT(*) c")
                ->groupBy('prefix')->orderByDesc('c')->get(),
        ]);
    }
}
