@extends('layouts.app')
@section('title','Financial Reports')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Financial reports from Posted journals. Unposted and unmapped records are excluded.</p>
@if($result)@can('report.print')<a class="btn btn-outline-secondary" data-report-print href="{{ route('financial-reports.print', $filters) }}" target="_blank" rel="noopener">Print full report</a>@endcan @endif</div>
<form method="get" class="filters" data-report-client><label>Client<select class="form-select" name="client_id" required><option value="">Select a client</option>@foreach($clients as $option)<option value="{{ $option->id }}" @selected($client?->id === $option->id)>{{ $option->business_name }}</option>@endforeach</select></label><button class="btn btn-primary">Select client</button></form>
@if($client)
<form method="get" class="filters" data-report-filters><input type="hidden" name="client_id" value="{{ $client->id }}">
<label>Report<select class="form-select" name="type">@foreach($types as $key=>$title)<option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $title }}</option>@endforeach</select></label>
<label>Accounting period<select class="form-select" name="period_id"><option value="">Custom dates</option>@foreach($periods as $option)<option value="{{ $option->id }}" @selected(($filters['period_id'] ?? '') == $option->id)>{{ $option->label }} · {{ substr($option->starts_on,0,10) }} – {{ substr($option->ends_on,0,10) }}</option>@endforeach</select></label>
<label>From<input type="date" class="form-control" name="start_date" value="{{ $filters['start_date'] }}" min="1000-01-01" max="9999-12-31" required></label>
<label>Through / as of<input type="date" class="form-control" name="end_date" value="{{ $filters['end_date'] }}" min="1000-01-01" max="9999-12-31" required></label>
<label>Account (Accounts Ledger)<select class="form-select" name="account_id"><option value="">Select an account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($filters['account_id'] ?? '') == $account->id)>{{ $account->code }} · {{ $account->name }}{{ $account->is_active ? '' : ' (Inactive)' }}</option>@endforeach</select></label>
<button class="btn btn-primary">Generate report</button></form>
<p class="form-text">A selected accounting period supplies both dates. Choose Custom dates to use the date fields. Trial Balance and Balance Sheet include all Posted history through the reporting date; the Income Statement uses the selected period's movements.</p>
@endif
@if($result)<section class="panel panel-pad financial-report">@include('financial-reports.content')</section>
@else<section class="panel"><div class="empty-state"><h2>{{ $client ? 'Select an account' : 'Select a client' }}</h2><p>{{ $client ? 'Choose an account to generate its complete Accounts Ledger report.' : 'Choose an authorized client to generate financial reports.' }}</p></div></section>@endif
@endsection
