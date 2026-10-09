<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** Who changed what: filter by person, kind of change, or search the name it was about. */
    public function index(Request $request)
    {
        $filters = array_filter($request->only(['actor', 'action', 'search']), 'is_scalar');

        $entries = AuditLog::query()
            ->when($filters['actor'] ?? null, fn ($q, $actor) => $q->where('actor_name', $actor))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('subject_name', 'like', '%'.$search.'%'))
            ->with('actor:id,username')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit.index', [
            'entries' => $entries,
            // Users that still exist get a link; one query for the whole page
            'existingUsers' => User::whereIn('id', $entries->where('subject_type', 'user')->pluck('subject_id'))->pluck('id')->all(),
            'filters' => $filters,
            'actors' => AuditLog::query()->distinct()->orderBy('actor_name')->pluck('actor_name'),
            'actions' => AuditLog::labels(),
        ]);
    }
}
