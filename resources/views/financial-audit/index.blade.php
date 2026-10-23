@extends('layouts.app')
@section('title','Financial Audit Trail')
@section('content')
@php($hasFilters = collect($filters)->except(['page', 'per_page'])->contains(fn ($value) => $value !== null && $value !== ''))
<div class="financial-audit-page">
    <header class="audit-heading">
        <div>
            <h2>Trace every journal entry</h2>
            <p class="mb-1">Review account movements, supporting documents, and who prepared, reviewed, or posted each transaction.</p>
            <p class="subtext mb-0"><i class="bi bi-shield-check text-primary me-1" aria-hidden="true"></i> Financial audit trail is restricted to double-entry ledger journals and voucher workflows. Administrative and system activity logs are isolated under System Activity Logs.</p>
        </div>
        @can('report.export')<a class="btn btn-outline-secondary" href="{{ route('financial-audit.export',request()->except('page')) }}"><i class="bi bi-download" aria-hidden="true"></i> Export filtered CSV</a>@endcan
    </header>

    <div class="audit-summary" aria-label="Totals for all matching journal lines">
        @foreach($totals as $group => $amounts)
        <section class="panel audit-total-card audit-totals" data-group="{{ $group }}" aria-labelledby="audit-{{ $group }}-title">
            <div class="audit-card-heading"><h3 id="audit-{{ $group }}-title">{{ ucfirst($group) }} movements</h3><span class="audit-ledger-note">{{ $group === 'posted' ? 'Included in General Ledger' : 'Not included in General Ledger' }}</span></div>
            <dl class="audit-amounts">
                <div><dt>Debit</dt><dd>{{ \App\Support\Money::formatExact(\App\Support\Money::decimal($amounts['debit'])) }}</dd></div>
                <div><dt>Credit</dt><dd>{{ \App\Support\Money::formatExact(\App\Support\Money::decimal($amounts['credit'])) }}</dd></div>
            </dl>
        </section>
        @endforeach
    </div>
    <p class="audit-scope-note">Totals include all matching lines across every page. Filtering by account may show unequal debit and credit totals.</p>

    <section class="panel audit-filter-panel" aria-labelledby="audit-filter-title">
        <div class="audit-section-heading"><h3 id="audit-filter-title">Filter transactions</h3>@if($hasFilters)<a href="{{ route('financial-audit.index') }}">Clear filters</a>@endif</div>
        <form method="get" action="{{ route('financial-audit.index') }}" class="audit-filter-grid">
            <label class="audit-search">Transaction or reference<input class="form-control" name="q" maxlength="150" placeholder="Search reference or description" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Client<select class="form-select" name="client_id"><option value="">All authorized clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(($filters['client_id']??'')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
            <label>Account<select class="form-select" name="account_id"><option value="">All accounts</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($filters['account_id']??'')==$account->id)>{{ $account->code }} - {{ $account->name }}</option>@endforeach</select></label>
            <label>From date<input class="form-control" type="date" name="from" value="{{ $filters['from']??'' }}"></label>
            <label>To date<input class="form-control" type="date" name="to" value="{{ $filters['to']??'' }}"></label>
            <label>Status<select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Services\Accounting\FinancialAuditTrail::STATUSES as $status)<option @selected(($filters['status']??'')===$status)>{{ $status }}</option>@endforeach</select></label>
            <label>Rows per page<select name="per_page" class="form-select">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 25) === $size)>{{ $size }} per page</option>@endforeach</select></label>
            <div class="audit-filter-actions"><button class="btn btn-primary">Apply filters</button></div>
        </form>
    </section>

    <section class="panel audit-results" aria-labelledby="audit-results-title">
        <div class="audit-section-heading"><div><h3 id="audit-results-title">Journal activity <span class="audit-count">{{ number_format($lines->total()) }} {{ $lines->total() === 1 ? 'line' : 'lines' }}</span></h3><p>Unmapped legacy entries are available in the General Journal and are excluded here.</p></div></div>
        @if($lines->isNotEmpty())
        <p class="audit-table-hint">Scroll horizontally to view all transaction details.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Financial journal lines">
            <table class="table audit-table"><thead><tr><th scope="col">Date / reference</th><th scope="col">Client</th><th scope="col">Voucher</th><th scope="col">Account</th><th scope="col" class="audit-money">Debit</th><th scope="col" class="audit-money">Credit</th><th scope="col">Description</th><th scope="col">People</th><th scope="col">Status</th><th scope="col">Evidence / details</th></tr></thead><tbody>
            @foreach($lines as $line)@php($entry = $line->entry)
                <tr>
                    <td><a class="audit-reference" href="{{ route('financial-audit.show',$entry) }}">{{ $entry->reference_number }}</a><span class="audit-cell-secondary">{{ $entry->transaction_date->format('M j, Y') }}</span></td>
                    <td>{{ $entry->client?->business_name }}</td>
                    <td>{{ $entry->voucher?->type ?? 'Journal' }}<span class="audit-cell-secondary">{{ $entry->voucher?->reference ?? 'No voucher associated' }}</span></td>
                    <td><strong>{{ $line->account?->code }}</strong><span class="audit-cell-secondary">{{ $line->account?->name }}</span></td>
                    <td class="audit-money">{{ \App\Support\Money::formatExact($line->debit) }}</td><td class="audit-money">{{ \App\Support\Money::formatExact($line->credit) }}</td>
                    <td class="audit-description">{{ \App\Support\AuditHistory::description(\App\Support\DemoLabels::display($entry->description)) }}</td>
                    <td class="audit-people"><span><small>Prepared</small> {{ $entry->creator?->name ?? 'Not recorded' }}</span><span><small>Reviewed</small> {{ $entry->reviewer?->name ?? 'Not recorded' }}</span><span><small>Posted</small> {{ $entry->poster?->name ?? 'Not posted' }}</span></td>
                    <td><x-badge :status="$entry->status"/>@if($entry->status !== 'Posted')<span class="audit-cell-secondary">Excluded from GL</span>@endif</td>
                    <td><a href="{{ route('financial-audit.show',$entry) }}">View transaction<span class="visually-hidden"> {{ $entry->reference_number }}</span></a></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @else
        <div class="empty-state audit-empty">
            <i class="bi bi-journal-text" aria-hidden="true"></i>
            <h3>{{ $hasFilters ? 'No transactions match your filters' : 'No journal activity to show' }}</h3>
            <p>{{ $hasFilters ? 'Try a wider date range, another account, or clear the filters to see all authorized records.' : 'Journal lines with assigned accounts appear here. Open the General Journal to view existing entries and their account assignments.' }}</p>
            @if($hasFilters)<a class="btn btn-outline-secondary" href="{{ route('financial-audit.index') }}">Clear filters</a>@else<a class="btn btn-outline-secondary" href="{{ route('ledger.index', array_filter(['client_id' => $filters['client_id'] ?? null])) }}">Open General Journal</a>@endif
        </div>
        @endif
    </section>
    @if($lines->total() > 0)<x-pagination :records="$lines"/>@endif
</div>
@endsection
