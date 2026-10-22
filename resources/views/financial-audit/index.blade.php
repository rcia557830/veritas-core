@extends('layouts.app')
@section('title','Financial Audit Trail')
@section('content')
<p>Journal lines from the accounting records. Posted amounts affect the General Ledger; draft and pending amounts do not. Unmapped legacy entries remain in the General Journal and are excluded here, consistent with financial reports.</p>
<form method="get" class="filters">
<label>Transaction / reference<input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}"></label>
<label>Client<select class="form-select" name="client_id"><option value="">All authorized clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(($filters['client_id']??'')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
<label>Account<select class="form-select" name="account_id"><option value="">All accounts</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($filters['account_id']??'')==$account->id)>{{ $account->code }} - {{ $account->name }}</option>@endforeach</select></label>
<label>From<input class="form-control" type="date" name="from" value="{{ $filters['from']??'' }}"></label><label>To<input class="form-control" type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>Status<select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Services\Accounting\FinancialAuditTrail::STATUSES as $status)<option @selected(($filters['status']??'')===$status)>{{ $status }}</option>@endforeach</select></label>
<x-per-page/><button class="btn btn-primary">Apply</button>
</form>
@can('report.export')<p><a class="btn btn-outline-secondary" href="{{ route('financial-audit.export',request()->except('page')) }}">Export filtered CSV</a></p>@endcan
<p>Totals for all matching lines (not only this page). Account filters show selected account movements, which may not balance.</p>
@foreach($totals as $group=>$amounts)<p class="audit-totals" data-group="{{ $group }}">{{ ucfirst($group) }} debit: {{ \App\Support\Money::formatExact(\App\Support\Money::decimal($amounts['debit'])) }} - credit: {{ \App\Support\Money::formatExact(\App\Support\Money::decimal($amounts['credit'])) }}</p>@endforeach
<p class="form-text">Scroll the table horizontally to see all transaction fields.</p>
<div class="panel table-responsive" tabindex="0" role="region" aria-label="Financial journal lines"><table class="table"><thead><tr><th>Date / reference</th><th>Client</th><th>Voucher</th><th>Account</th><th>Debit</th><th>Credit</th><th>Description</th><th>Prepared / reviewed / posted by</th><th>Status</th><th>Evidence / details</th></tr></thead><tbody>
@forelse($lines as $line)@php($entry=$line->entry)<tr><td>{{ $entry->transaction_date->format('Y-m-d') }}<br>{{ $entry->reference_number }}</td><td>{{ $entry->client?->business_name }}</td><td>{{ $entry->voucher?->type ?? 'Journal' }}<br>{{ $entry->voucher?->reference ?? 'No voucher associated' }}</td><td>{{ $line->account?->code }} - {{ $line->account?->name }}</td><td>{{ \App\Support\Money::formatExact($line->debit) }}</td><td>{{ \App\Support\Money::formatExact($line->credit) }}</td><td>{{ \App\Support\DemoLabels::display($entry->description) }}</td><td>{{ $entry->creator?->name ?? 'Not recorded' }} / {{ $entry->reviewer?->name ?? 'Not recorded' }} / {{ $entry->poster?->name ?? 'Not posted' }}</td><td>{{ $entry->status }}{{ $entry->status==='Posted'?'':' - excluded from GL' }}</td><td><a href="{{ route('financial-audit.show',$entry) }}">Transaction details and documents</a></td></tr>
@empty<tr><td colspan="10">No matching financial records.</td></tr>@endforelse
</tbody></table></div><x-pagination :records="$lines"/>
@endsection
