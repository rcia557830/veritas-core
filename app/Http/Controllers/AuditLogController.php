<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('security.view');
        $request->validate(['module' => 'nullable|in:auth,users,workspace']);
        $logs = AuditLog::with('user')->whereIn('module', ['auth', 'users', 'workspace'])->when(Records::searchText($request) !== '', fn ($q) => $q->where('description', 'like', '%'.Records::searchText($request).'%'))->when($request->filled('module'), fn ($q) => $q->where('module', $request->query('module')))->latest()->paginate(Records::pageSize($request))->withQueryString();

        return view('admin.audit', compact('logs'));
    }
}
