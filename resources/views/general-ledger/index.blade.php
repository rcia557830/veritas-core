@extends('layouts.app')
@section('title','General Ledger')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Read-only account history and balances from Posted journals. Dr = debit balance; Cr = credit balance.</p>
@if($result)@can('report.print')<a class="btn btn-outline-secondary" href="{{ route('general-ledger.print', $filters) }}" target="_blank" rel="noopener">Print this page</a>@endcan @endif</div>
<form method="get" class="filters" data-ledger-client>
<label>Client<select class="form-select" name="client_id" required><option value="">Select a client</option>@foreach($clients as $option)<option value="{{ $option->id }}" @selected($client?->id === $option->id)>{{ $option->business_name }}</option>@endforeach</select></label>
<input type="hidden" name="start_date" value="{{ $filters['start_date'] }}"><input type="hidden" name="end_date" value="{{ $filters['end_date'] }}">
<button class="btn btn-primary">Select client</button></form>
@if($client)
<form method="get" class="filters" data-ledger-filters>
<input type="hidden" name="client_id" value="{{ $client->id }}">
<label>Account<select class="form-select" name="account_id" required><option value="">Select an account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($filters['account_id'] ?? null) == $account->id)>{{ $account->code }} · {{ $account->name }}{{ $account->is_active ? '' : ' (Inactive)' }}</option>@endforeach</select></label>
<label>From<input class="form-control" type="date" name="start_date" min="1000-01-01" max="9999-12-31" value="{{ $filters['start_date'] }}" required></label>
<label>Through<input class="form-control" type="date" name="end_date" min="1000-01-01" max="9999-12-31" value="{{ $filters['end_date'] }}" required></label>
<label>Rows per page<select class="form-select" name="per_page">@foreach([10,25,50,100] as $size)<option @selected($filters['per_page'] == $size)>{{ $size }}</option>@endforeach</select></label>
<button class="btn btn-primary">View ledger</button></form>
@endif
@if($result)
<section class="panel panel-pad general-ledger-detail">
@include('general-ledger.summary')
@include('general-ledger.movements')
</section>
<x-pagination :records="$movements"/>
@else
<section class="panel"><div class="empty-state"><h2>{{ !$client ? 'Select a client' : ($accounts->isEmpty() ? 'No accounts configured' : 'Select an account') }}</h2><p>{{ !$client ? 'Choose an authorized client to view their General Ledger.' : ($accounts->isEmpty() ? 'This client does not yet have a Chart of Accounts.' : 'Choose an account and date range to view balances. Inactive accounts retain their posted history.') }}</p></div></section>
@endif
@endsection
