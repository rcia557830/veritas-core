@extends('layouts.app')
@section('title','Financial Transaction Details')
@section('content')
<p><a href="{{ route('financial-audit.index') }}">Financial Audit Trail</a></p>
<h2>{{ $entry->reference_number }}</h2><p>{{ $entry->client?->business_name }} - {{ $entry->transaction_date->format('Y-m-d') }} - {{ $entry->status }}</p><p>{{ \App\Support\DemoLabels::display($entry->description) }}</p>
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
<div class="alert alert-info py-2 px-3 mb-4 d-flex align-items-center gap-2">
    <i class="bi bi-shield-check text-primary fs-5" aria-hidden="true"></i>
    <span class="small"><strong>Financial Audit Trail:</strong> Strictly isolated to double-entry ledger entries, voucher details, and financial approvals. Technical authentication and employee access logs are maintained separately under System Activity Logs.</span>
</div>

<h3>Financial event history</h3>
@forelse(\App\Services\Audit::visible()->with('user')->where('module','ledger')->where('record_id',$entry->id)->orderBy('id')->get() as $event)
<div class="panel panel-pad mb-3 audit-event-card">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-secondary">{{ \App\Support\AuditHistory::label($event->action) }}</span>
            <strong>{{ $event->action }}</strong>
            <span class="subtext">· by <strong>{{ $event->user?->name ?? 'System' }}</strong></span>
        </div>
        <span class="subtext">{{ $event->created_at->format('M j, Y g:i:s A') }}</span>
    </div>
    <p class="mb-2 text-secondary">{{ \App\Support\AuditHistory::description($event->description) }}</p>
    @if($event->evidence)
    <details class="audit-diff-details mt-2">
        <summary class="fw-semibold text-primary" style="cursor: pointer;">Recorded financial changes</summary>
        @php($diff = \App\Support\AuditHistory::diff($event->evidence['before'] ?? null, $event->evidence['after'] ?? null))
        @if(!empty($diff))
        <div class="audit-diff-card p-3 my-2 rounded bg-light border">
            <h5 class="small text-uppercase text-muted fw-bold mb-2">Summary of differences (Previous → New)</h5>
            <div class="row g-2">
                @foreach($diff as $change)
                <div class="col-md-6">
                    <div class="p-2 bg-white rounded border small">
                        <strong>{{ $change['label'] }}:</strong>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="text-danger text-decoration-line-through">{{ $change['old'] }}</span>
                            <span class="text-muted">→</span>
                            <span class="text-success fw-bold">{{ $change['new'] }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        @foreach(['before','after'] as $side)
        <h4 class="mt-3">{{ ucfirst($side) }}</h4>
        @php($state=$event->evidence[$side]??null)
        @if($state)
        <p class="subtext mb-2">{{ $state['reference_number']??'' }} | {{ $state['status']??'' }} | {{ $state['transaction_date']??'' }}</p>
        @if(!empty($state['voucher']))
            <p class="subtext mb-2">Voucher: {{ $state['voucher']['type'] ?? '' }} {{ $state['voucher']['reference'] ?? '' }} | Payee: {{ $state['voucher']['party'] ?? 'None' }} | Amount: {{ isset($state['voucher']['amount']) ? \App\Support\Money::formatExact($state['voucher']['amount']) : '—' }}</p>
        @endif
        <div class="table-responsive"><table class="table"><thead><tr><th>Recorded account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>
        @foreach($state['lines']??[] as $saved)<tr><td>{{ $saved['account_name']??$saved['account_id'] }}</td><td>{{ $saved['debit'] }}</td><td>{{ $saved['credit'] }}</td></tr>@endforeach
        </tbody></table></div>
        @else<p>No prior state captured for this event.</p>@endif
        @endforeach
    </details>
    @endif
</div>
@empty
<p>No financial event history recorded yet.</p>
@endforelse
<p>Explicit adjustment/reversal relationships are not supported by the current accounting model. No relationship is inferred from reference text.</p>
@endsection
