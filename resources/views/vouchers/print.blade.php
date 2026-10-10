<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $voucher->reference }} · VERITAS CORE</title><style>body{font:12px Arial,sans-serif;color:#18283e;margin:24px}h1{border-bottom:3px solid #c9a55f;padding-bottom:12px}.detail-grid{display:flex;flex-wrap:wrap;gap:24px}dt{font-weight:bold}dd{margin:8px 0;overflow-wrap:anywhere}p{overflow-wrap:anywhere}table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #dfe4ea;text-align:left;overflow-wrap:anywhere}.numeric{text-align:right;white-space:nowrap}a{color:inherit}thead{display:table-header-group}tr{break-inside:avoid}@media print{.print-actions{display:none}body{margin:0}}@page{margin:15mm}</style></head><body>
<div class="print-actions"><button onclick="window.print()">Print voucher</button> <a href="{{ route('vouchers.show',$voucher) }}">Back to voucher</a></div>
<h1>VERITAS CORE · Accounting Voucher</h1>
@include('vouchers.metadata')
<p><strong>{{ $record->description }}</strong></p>@if($record->notes)<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $record->notes }}</p>@endif<p>Accounting period: {{ $record->accountingPeriod->label }}</p>
@include('ledger.lines')
<p>Prepared by {{ $record->creator?->name }}. Reviewed by {{ $record->reviewer?->name ?? 'Not reviewed' }}. Posted by {{ $record->poster?->name ?? 'Not posted' }} {{ $record->posted_at?->format('Y-m-d H:i') }}.</p>
<h3>Supporting evidence</h3>
@forelse($record->evidence()->whereNull('detached_at')->get()->filter(fn($e)=>$e->document && \Illuminate\Support\Facades\Gate::allows('view',$e->document)) as $evidence)<p>{{ $evidence->document_number }} · {{ $evidence->title }}</p>@empty<p>No accessible supporting evidence.</p>@endforelse
<p>{{ $record->status === 'Posted' ? 'Posted journal: financial movements are included once in the General Ledger.' : 'UNPOSTED: this voucher does not yet contribute to the General Ledger or financial statements.' }}</p>
</body></html>
