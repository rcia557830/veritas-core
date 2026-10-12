@extends('layouts.app')
@section('title','Overview')
@section('content')
<header class="overview-heading"><div><h2>{{ $role }} overview</h2><p>Welcome back, {{ auth()->user()->name }}. Here is what needs attention.</p></div><time datetime="{{ today()->toDateString() }}">{{ now()->format('M j, Y') }}</time></header>
<x-overview-cards :cards="$cards"/>
<div class="row g-4 mt-1">
@can('viewAny',\App\Models\ComplianceRecord::class)
<div class="col-xl-8"><section class="panel table-panel"><div class="panel-heading"><div><h2 class="section-title">Upcoming and overdue filings</h2><p class="section-description">Nearest unresolved deadlines</p></div><a href="{{ route('compliance.monitoring') }}">View all</a></div><div class="table-responsive" tabindex="0" aria-label="Unresolved filings"><table class="table"><thead><tr><th>Requirement</th><th>Client</th><th>Filing due</th><th>Status</th></tr></thead><tbody>
@forelse($deadlines as $item)<tr><td><a href="{{ route('compliance.show',$item) }}">{{ $item->requirement }}</a></td><td>{{ $item->client->business_name }}</td><td>{{ $item->due_date->format('M j, Y') }}</td><td><x-badge :status="$item->urgency"/></td></tr>
@empty<tr><td colspan="4"><div class="empty-state"><h3>No open deadlines</h3><p>Your filing queue is clear.</p></div></td></tr>@endforelse
</tbody></table></div></section></div>@endcan
<div class="col-xl-4"><section class="panel panel-pad" id="pending-tasks"><h2 class="section-title">Pending tasks</h2><ul class="task-list">
@can('viewAny',\App\Models\Client::class)
<li><a href="{{ route('clients.onboarding',['state'=>'pending']) }}">Pending onboarding <strong>{{ $stats['onboarding']['pending'] }}</strong></a></li>
<li><a href="{{ route('clients.onboarding',['state'=>'ready_for_activation']) }}">Ready for activation <strong>{{ $stats['onboarding']['ready_for_activation'] }}</strong></a></li>@endcan
@can('bookkeeping.post')<li><a href="{{ route('ledger.index',['status'=>'Reviewed']) }}">Reviewed awaiting posting <strong>{{ $stats['ledger_reviewed'] }}</strong></a></li>@endcan
@can('viewAny',\App\Models\ComplianceRecord::class)<li><a href="{{ route('compliance.monitoring',['deadline'=>'filing_overdue']) }}">Overdue filings <strong>{{ $stats['overdue'] }}</strong></a></li>@endcan
</ul><p class="form-text mb-0">Use the notification bell for your latest updates.</p></section></div></div>
@endsection
