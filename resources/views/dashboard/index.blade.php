@extends('layouts.app')
@section('title','Overview')
@section('content')
<div data-dashboard-role="{{ $experience }}">
<header class="overview-heading"><div><h2>{{ $role }} overview</h2><p>{{ $description }}</p></div><time datetime="{{ today()->toDateString() }}">{{ now()->format('M j, Y') }}</time></header>
@if($cards)<x-overview-cards :cards="$cards"/>@else<p class="panel panel-pad">No dashboard queues are available with your current permissions.</p>@endif
@if($shortcuts)<nav class="actions my-4" aria-label="Dashboard actions">@foreach($shortcuts as $shortcut)<a class="btn btn-outline-secondary" href="{{ $shortcut['url'] }}">{{ $shortcut['label'] }}</a>@endforeach</nav>@endif
<div class="row g-4 mt-1">
@if($experience === 'owner') @can('viewAny',\App\Models\ComplianceRecord::class)
<div class="col-xl-8"><section class="panel table-panel"><div class="panel-heading"><div><h2 class="section-title">Upcoming and overdue filings</h2><p class="section-description">Nearest unresolved deadlines</p></div><a href="{{ route('compliance.monitoring') }}">View all</a></div><div class="table-responsive" tabindex="0" aria-label="Unresolved filings"><table class="table"><thead><tr><th>Requirement</th><th>Client</th><th>Filing due</th><th>Status</th></tr></thead><tbody>
@forelse($deadlines as $item)<tr><td><a href="{{ route('compliance.show',$item) }}">{{ $item->requirement }}</a></td><td>{{ $item->client->business_name }}</td><td>{{ $item->due_date->format('M j, Y') }}</td><td><x-badge :status="$item->urgency"/></td></tr>
@empty<tr><td colspan="4"><div class="empty-state"><h3>No open deadlines</h3><p>Your filing queue is clear.</p></div></td></tr>@endforelse
</tbody></table></div></section></div>@endcan @endif

@if($experience === 'office-manager')
@can('viewAny',\App\Models\Document::class) @can('document.validate')
@include('dashboard.work-queue', ['items'=>$documents, 'module'=>'documents', 'heading'=>'Documents to validate', 'empty'=>'No documents awaiting validation'])
@endcan @endcan
@endif
@if($experience === 'bookkeeper')
@can('viewAny',\App\Models\LedgerEntry::class)
@include('dashboard.work-queue', ['items'=>$journals, 'module'=>'ledger', 'heading'=>'Your journal preparation', 'empty'=>'No journals awaiting preparation'])
@endcan
@endif
@if($tasks)<div class="col-xl-4"><section class="panel panel-pad" id="pending-tasks"><h2 class="section-title">{{ $experience === 'bookkeeper' ? 'Assigned deadlines' : 'Pending tasks' }}</h2><ul class="task-list">
@foreach($tasks as $task)<li><a href="{{ $task['url'] }}">{{ $task['label'] }} <strong>{{ $task['value'] }}</strong></a></li>@endforeach
</ul><p class="form-text mb-0">Use the notification bell for your latest updates.</p></section></div>@endif
</div></div>
@endsection
