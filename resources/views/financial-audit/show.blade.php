@extends('layouts.app')
@section('title','Financial Transaction Details')
@section('content')
<p><a href="{{ route('financial-audit.index') }}">Financial Audit Trail</a></p>
<h2>{{ $entry->reference_number }}</h2><p>{{ $entry->client?->business_name }} - {{ $entry->transaction_date->format('Y-m-d') }} - {{ $entry->status }}</p><p>{{ $entry->description }}</p>
<p>Voucher: {{ $entry->voucher?->type ?? 'No voucher associated' }} {{ $entry->voucher?->reference }}</p>
<p>Prepared by {{ $entry->creator?->name ?? 'Not recorded' }} - Reviewed by {{ $entry->reviewer?->name ?? 'Not recorded' }} {{ $entry->reviewed_at?->format('Y-m-d H:i:s') }} - Posted by {{ $entry->poster?->name ?? 'Not posted' }} {{ $entry->posted_at?->format('Y-m-d H:i:s') }}</p>
<p>{{ $entry->status==='Posted'?'Posted - read-only financial record.':'Unposted - excluded from General Ledger and financial reports.' }}</p>
<div class="panel table-responsive"><table class="table"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>@foreach($entry->items as $line)<tr><td>{{ $line->account?->code }} - {{ $line->account?->name ?? $line->account_name }}</td><td>{{ \App\Support\Money::formatExact($line->debit) }}</td><td>{{ \App\Support\Money::formatExact($line->credit) }}</td></tr>@endforeach</tbody></table></div>
<p>Journal debit: {{ \App\Support\Money::formatExact($entry->total_debit) }} - credit: {{ \App\Support\Money::formatExact($entry->total_credit) }}</p>
<h3>Supporting documents</h3>
@forelse($entry->evidence->filter(fn($e)=>$e->client_id===$entry->client_id && $e->document && \Illuminate\Support\Facades\Gate::allows('view',$e->document)) as $evidence)
<p>{{ $evidence->document_number }} - {{ $evidence->title }} - {{ $evidence->detached_at?'Historical association':'Current association' }}
@if($evidence->file_path)@can('download',$evidence->document)<a href="{{ route('ledger.evidence',[$entry,$evidence]) }}">Download retained {{ $evidence->original_file_name }}</a>@endcan
@endif</p>
@empty<p>No supporting documents are associated or accessible.</p>@endforelse
<h3>Financial event history</h3>
@foreach(\App\Services\Audit::visible()->with('user')->where('module','ledger')->where('record_id',$entry->id)->orderBy('id')->get() as $event)<p>{{ $event->created_at->format('Y-m-d H:i:s') }} - {{ $event->user?->name ?? 'System' }} - {{ $event->action }} - {{ $event->description }}</p>
@if($event->evidence)<details><summary>Recorded financial changes</summary>
@foreach(['before','after'] as $side)<h4>{{ ucfirst($side) }}</h4>
@php($state=$event->evidence[$side]??null)
@if($state)<p>{{ $state['reference_number']??'' }} | {{ $state['status']??'' }} | {{ $state['transaction_date']??'' }}</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Recorded account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>
@foreach($state['lines']??[] as $saved)<tr><td>{{ $saved['account_name']??$saved['account_id'] }}</td><td>{{ $saved['debit'] }}</td><td>{{ $saved['credit'] }}</td></tr>@endforeach
</tbody></table></div>
@else<p>No prior state captured for this event.</p>@endif
@endforeach</details>@endif
@endforeach
<p>Explicit adjustment/reversal relationships are not supported by the current accounting model. No relationship is inferred from reference text.</p>
@endsection
