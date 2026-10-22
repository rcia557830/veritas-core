@if($record->status === 'Draft')
    <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <div><strong>Draft invoice</strong> — This invoice has not been issued to the client yet. Verify line items below and click <strong>Issue invoice</strong> to finalize and begin collection.</div>
    </div>
@elseif($record->status === 'Cancelled')
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-slash-circle-fill" aria-hidden="true"></i>
        <div><strong>Cancelled invoice</strong> — This invoice was voided and is no longer collectible.</div>
    </div>
@elseif($record->display_status === 'Paid')
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <div><strong>Paid in full</strong> — All billed charges on this invoice have been completely settled.</div>
    </div>
@elseif($record->display_status === 'Overdue')
    <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div><strong>Payment overdue</strong> — Due date was {{ $record->due_date->format('M j, Y') }}.</div>
        </div>
        <div>Outstanding balance: <strong>{{ \App\Support\Money::format($record->balance) }}</strong></div>
    </div>
@else
    <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <div><strong>Payment due:</strong> {{ $record->due_date->format('M j, Y') }} · Status: <strong>{{ $record->display_status }}</strong></div>
        </div>
        <div>Remaining balance: <strong>{{ \App\Support\Money::format($record->balance) }}</strong></div>
    </div>
@endif

<h3 class="section-title mb-2">Invoice line items</h3>
@include('billing.items')

<div class="mini-stats my-3">
    <div class="mini-stat">
        <strong>{{ \App\Support\Money::format($record->total_amount) }}</strong>
        <span>Total invoice</span>
    </div>
    <div class="mini-stat">
        <strong>{{ \App\Support\Money::format($record->amount_paid) }}</strong>
        <span>Paid</span>
    </div>
    <div class="mini-stat {{ $record->balance_cents > 0 ? 'border-warning' : 'border-success' }}">
        <strong class="{{ $record->balance_cents > 0 ? 'text-warning-emphasis' : 'text-success' }}">{{ \App\Support\Money::format($record->balance) }}</strong>
        <span>Balance</span>
    </div>
</div>

<div class="actions mb-4">
    <a target="_blank" rel="noopener" class="btn btn-outline-secondary" href="{{ route('billing.print',$record) }}"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print invoice</a>
    <a class="btn btn-outline-secondary" href="{{ route('billing.pdf',$record) }}"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF</a>
    @can('issue',$record)
        <form method="post" action="{{ route('billing.transition',$record) }}">
            @csrf
            <input type="hidden" name="action" value="issue">
            <button class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Issue invoice</button>
        </form>
    @endcan
    @can('cancel',$record)
        @if($record->status!=='Cancelled' && !$record->payments()->exists())
            <form method="post" action="{{ route('billing.transition',$record) }}" data-confirm="Cancel this invoice? This action cannot be reversed.">
                @csrf
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel invoice</button>
            </form>
        @endif
    @endcan
</div>

@can('recordPayment',$record)
    @if($record->status==='Open' && $record->balance_cents>0)
        <div class="panel panel-pad bg-light border mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h3 class="section-title mb-0"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Record client payment</h3>
                <span class="badge bg-secondary text-white">Remaining balance: {{ \App\Support\Money::format($record->balance) }}</span>
            </div>
            <form method="post" action="{{ route('billing.payments.store',$record) }}">
                @csrf
                <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="row g-3">
                    <div class="col-md-4">
                        <x-field name="payment_date" label="Payment date" type="date" :value="today()->toDateString()" :required="true"/>
                    </div>
                    <div class="col-md-4">
                        <x-field name="amount" label="Amount (PHP)" type="number" :required="true"/>
                        <div class="form-text mt-1">Maximum payable: {{ \App\Support\Money::format($record->balance) }}</div>
                    </div>
                    <div class="col-md-4">
                        <x-field name="payment_method" label="Method" type="select" :options="array_combine(['Cash','Bank Transfer','Cheque','GCash','Other'],['Cash','Bank Transfer','Cheque','GCash','Other'])" :required="true"/>
                    </div>
                    <div class="col-md-6">
                        <x-field name="reference_number" label="Payment reference"/>
                    </div>
                    <div class="col-md-6">
                        <x-field name="notes" label="Notes"/>
                    </div>
                </div>
                <button class="btn btn-primary mt-3"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Record payment</button>
            </form>
        </div>
    @endif
@endcan

<h3 class="section-title">Payment history</h3>
<div class="table-responsive mb-4">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Recorded by</th>
                <th class="numeric">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($record->payments()->with('recorder')->latest()->get() as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('M j, Y') }}</td>
                    <td><span class="badge bg-light text-dark border">{{ $payment->payment_method }}</span></td>
                    <td>{{ $payment->reference_number ?: '—' }}</td>
                    <td>{{ $payment->recorder?->name ?? 'System' }}</td>
                    <td class="numeric"><strong>{{ \App\Support\Money::format($payment->amount) }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted py-3 text-center">No payments recorded for this invoice yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
