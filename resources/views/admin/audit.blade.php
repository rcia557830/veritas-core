@extends('layouts.app')
@section('title','System Activity Logs')
@section('content')
<div class="alert alert-info py-2 px-3 mb-3 small d-flex align-items-center gap-2">
    <i class="bi bi-shield-lock text-primary fs-5" aria-hidden="true"></i>
    <span><strong>System Activity Logs:</strong> Administrative and authentication activity. Client double-entry accounting records and vouchers are isolated under the Financial Audit Trail.</span>
</div>
<form method="get" class="filters"><label>Search<input name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Module<select class="form-select" name="module"><option value="">All modules</option>@foreach(['auth','users','workspace'] as $module)<option @selected(request('module')===$module)>{{ $module }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form><div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Time</th><th>Employee</th><th>Action</th><th>Module</th><th>Description</th><th>IP address</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j, Y g:i:s A') }}</td><td>{{ $log->user?->name??'System' }}</td><td>{{ $log->action }}</td><td>{{ $log->module }}</td><td>{{ \App\Support\AuditHistory::description($log->description) }}</td><td>{{ $log->ip_address }}</td></tr>@empty<tr><td colspan="6">No matching activity. Try a different search.</td></tr>@endforelse</tbody></table></div><x-pagination :records="$logs"/>@endsection
