@extends('layouts.app')
@section('title','Overview')
@section('content')
<div data-dashboard-role="{{ $experience }}">
<header class="overview-heading"><div><h2>{{ $role }} overview</h2><p>{{ $description }}</p></div><time datetime="{{ today()->toDateString() }}">{{ now()->format('M j, Y') }}</time></header>
@if($cards)<x-overview-cards :cards="$cards"/>@else<p class="panel panel-pad">No dashboard queues are available with your current permissions.</p>@endif
@if($shortcuts)<nav class="actions my-4" aria-label="Dashboard actions">@foreach($shortcuts as $shortcut)<a class="btn btn-outline-secondary" href="{{ $shortcut['url'] }}">{{ $shortcut['label'] }}</a>@endforeach</nav>@endif

@if(!empty($actionItems))
<section class="panel panel-pad my-4 action-hub-panel" aria-labelledby="action-hub-title">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 id="action-hub-title" class="section-title mb-0 d-flex align-items-center gap-2">
                <i class="bi bi-lightning-charge text-warning" aria-hidden="true"></i> Priority Action Items
            </h2>
            <p class="section-description mb-0">High-priority operational queues requiring prompt attention</p>
        </div>
        <span class="subtext">Direct links to active work queues</span>
    </div>
    <div class="row g-3">
        @foreach($actionItems as $item)
        <div class="col-sm-6 col-xl-3">
            <div class="action-highlight-card h-100 p-3 rounded border d-flex flex-column justify-content-between tone-{{ $item['tone'] }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="action-card-icon fs-5 text-{{ $item['tone'] === 'danger' ? 'danger' : ($item['tone'] === 'warning' ? 'warning' : 'primary') }}"><i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i></span>
                        <span class="badge {{ $item['count'] > 0 ? ($item['tone'] === 'danger' ? 'bg-danger' : ($item['tone'] === 'warning' ? 'bg-warning text-dark' : 'bg-primary')) : 'bg-secondary' }}">
                            {{ isset($item['amount']) && $item['count'] > 0 ? $item['amount'] : ($item['count'].' '.($item['count'] === 1 ? 'item' : 'items')) }}
                        </span>
                    </div>
                    <h3 class="action-card-title h6 fw-bold mb-1">{{ $item['title'] }}</h3>
                    <p class="subtext mb-3">{{ $item['description'] }}</p>
                </div>
                <a class="action-card-link btn btn-sm btn-outline-secondary w-100 d-flex align-items-center justify-content-between" href="{{ $item['url'] }}">
                    <span>{{ $item['action_label'] }}</span>
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
        @endforeach
    </div>
</section>
@endif

<div class="dashboard-columns">
@if($experience === 'owner') @can('viewAny',\App\Models\ComplianceRecord::class)
<div class="dashboard-main">
    <section class="panel table-panel mb-4"><div class="panel-heading"><div><h2 class="section-title">Upcoming and overdue filings</h2><p class="section-description">Nearest unresolved deadlines</p></div><a href="{{ route('compliance.monitoring') }}">View all</a></div><div class="table-responsive" tabindex="0" aria-label="Unresolved filings"><table class="table"><thead><tr><th>Requirement</th><th>Client</th><th>Filing due</th><th>Status</th></tr></thead><tbody>
@forelse($deadlines as $item)<tr><td><a href="{{ route('compliance.show',$item) }}">{{ $item->requirement }}</a></td><td>{{ $item->client->business_name }}</td><td>{{ $item->due_date->format('M j, Y') }}</td><td><x-compliance-warnings :record="$item"/></td></tr>
@empty<tr><td colspan="4"><div class="empty-state"><h3>No open deadlines</h3><p>Your filing queue is clear.</p></div></td></tr>@endforelse
    </tbody></table></div></section>

    @if($reviewableJournals->isNotEmpty())
    <section class="panel table-panel mb-4">
        <div class="panel-heading">
            <div><h2 class="section-title">Journals awaiting review</h2><p class="section-description">Submitted entries awaiting independent verification</p></div>
            <a href="{{ route('ledger.index', ['work' => 'reviewable']) }}">View reviewable <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Journals awaiting review">
            <table class="table">
                <thead><tr><th>Reference</th><th>Client</th><th>Prepared by</th><th>Debit total</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($reviewableJournals as $entry)
                <tr>
                    <td><a href="{{ route('ledger.show', $entry) }}">{{ $entry->reference_number }}</a></td>
                    <td>{{ $entry->client->business_name }}</td>
                    <td>{{ $entry->creator?->name ?? 'Staff' }}</td>
                    <td>{{ \App\Support\Money::formatExact($entry->total_debit) }}</td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('ledger.show', $entry) }}">Review entry</a></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    @if($unpaidInvoices->isNotEmpty())
    <section class="panel table-panel mb-4">
        <div class="panel-heading">
            <div><h2 class="section-title">Unpaid client invoices</h2><p class="section-description">Issued billing awaiting payment settlement</p></div>
            <a href="{{ route('billing.index', ['queue' => 'outstanding']) }}">View all unpaid <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Unpaid client invoices">
            <table class="table">
                <thead><tr><th>Invoice #</th><th>Client</th><th>Due date</th><th>Outstanding</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($unpaidInvoices as $inv)
                <tr>
                    <td><a href="{{ route('billing.show', $inv) }}">{{ $inv->invoice_number }}</a></td>
                    <td>{{ $inv->client->business_name }}</td>
                    <td>{{ $inv->due_date?->format('M j, Y') ?? '—' }}</td>
                    <td><strong>{{ \App\Support\Money::format($inv->balance_cents / 100) }}</strong></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('billing.show', $inv) }}">View invoice</a></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>
@endcan @endif

@if($experience === 'office-manager')
<div class="dashboard-main">
    @can('viewAny',\App\Models\Document::class) @can('document.validate')
    @include('dashboard.work-queue', ['items'=>$documents, 'module'=>'documents', 'heading'=>'Documents to validate', 'empty'=>'No documents awaiting validation'])
    @endcan @endcan

    @if($reviewableJournals->isNotEmpty())
    <section class="panel table-panel mt-4">
        <div class="panel-heading">
            <div><h2 class="section-title">Journals awaiting review</h2><p class="section-description">Submitted entries awaiting independent verification</p></div>
            <a href="{{ route('ledger.index', ['work' => 'reviewable']) }}">View reviewable <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Journals awaiting review">
            <table class="table">
                <thead><tr><th>Reference</th><th>Client</th><th>Prepared by</th><th>Debit total</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($reviewableJournals as $entry)
                <tr>
                    <td><a href="{{ route('ledger.show', $entry) }}">{{ $entry->reference_number }}</a></td>
                    <td>{{ $entry->client->business_name }}</td>
                    <td>{{ $entry->creator?->name ?? 'Staff' }}</td>
                    <td>{{ \App\Support\Money::formatExact($entry->total_debit) }}</td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('ledger.show', $entry) }}">Review entry</a></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>
@endif

@if($experience === 'bookkeeper')
@can('viewAny',\App\Models\LedgerEntry::class)
@include('dashboard.work-queue', ['items'=>$journals, 'module'=>'ledger', 'heading'=>'Your journal preparation', 'empty'=>'No journals awaiting preparation'])
@endcan
@endif

@if($tasks)<div class="dashboard-aside"><section class="panel panel-pad" id="pending-tasks"><div class="eyebrow">YOUR WORK QUEUE</div><h2 class="section-title">{{ $experience === 'bookkeeper' ? 'Assigned deadlines' : 'Pending tasks' }}</h2><ul class="task-list">
@foreach($tasks as $task)<li><a href="{{ $task['url'] }}"><span>{{ $task['label'] }}</span><strong>{{ $task['value'] }}</strong><span class="task-arrow" aria-hidden="true">&rarr;</span></a></li>@endforeach
</ul><p class="form-text mb-0">Use the notification bell for your latest updates.</p></section></div>@endif
</div></div>
@endsection
