@extends('layouts.app')
@section('title','Client profile')
@section('content')
<div class="toolbar">
  <a class="btn btn-outline-secondary" href="{{ route('clients.index') }}">← Clients</a>
  <div class="actions">
    @can('update',$record)<a data-modal class="btn btn-primary" href="{{ route('clients.edit',$record) }}">Edit client</a>@endcan
    @can($record->status==='Archived'?'restore':'archive',$record)
    <form method="post" action="{{ route('clients.archive',$record) }}" data-confirm="{{ $record->status==='Archived'?'Restore this client?':'Archive this client? Related records will be retained.' }}">@csrf<button class="btn btn-outline-danger">{{ $record->status==='Archived'?'Restore':'Archive' }}</button></form>
    @endcan
  </div>
</div>

<section class="panel panel-pad mb-4">
  <div class="toolbar">
    <div>
      <h2 class="modal-title mb-0">{{ $record->business_name }}</h2>
      <span class="subtext">{{ $record->client_code }} · {{ $record->business_type }}</span>
    </div>
    <div class="actions">
      <x-badge :status="$record->status"/>
    </div>
  </div>
  <dl class="detail-grid mt-3">
    @foreach($config['fields'] as $key=>$field)
      <div><dt>{{ $field[0] }}</dt><dd>{{ \App\Support\Display::value($record,$key) }}</dd></div>
    @endforeach
    <div><dt>Assigned employee</dt><dd>{{ $record->assignee?->name??'Unassigned' }}</dd></div>
  </dl>
</section>

@php($profile = \App\Services\OnboardingReadiness::profileProgress($record))
@php($readiness = \App\Services\OnboardingReadiness::calculate($record))
@php($cblReq = $readiness['requirements']->first(fn($r) => \App\Services\OnboardingReadiness::isCbl($r)))
@php($corReq = $readiness['requirements']->first(fn($r) => \App\Services\OnboardingReadiness::isCor($r)))
@php($accountsCount = \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Account::class) ? \App\Models\Account::where('client_id', $record->id)->where('is_active', true)->count() : 0)
@php($accountingYearsCount = $record->accountingYears()->count())
@php($openPeriodsCount = \App\Models\AccountingPeriod::where('client_id', $record->id)->where('status', 'Open')->count())

<section class="panel panel-pad mb-4">
  <div class="toolbar mb-3">
    <div>
      <h3 class="section-title mb-0">Onboarding & operational setup</h3>
      <span class="subtext">Core prerequisites for client activation and operational bookkeeping</span>
    </div>
    <div class="actions">
      <x-badge :status="\App\Services\OnboardingReadiness::label($readiness['state'])"/>
      @if(!$readiness['onboarded'] && $readiness['state'] === \App\Services\OnboardingReadiness::STATE_READY)
        @can('activate',$record)
        <form method="post" action="{{ route('clients.onboarding.complete',$record) }}" data-confirm="Complete onboarding for {{ $record->business_name }}?">
          @csrf<button class="btn btn-sm btn-primary"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Complete onboarding</button>
        </form>
        @endcan
      @endif
      @can('viewAny',\App\Models\Client::class)<a class="btn btn-sm btn-outline-secondary" href="{{ route('clients.onboarding.checklist',$record) }}">Onboarding checklist</a>@endcan
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card p-3 h-100 bg-light border">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong class="small text-uppercase text-muted">1. Profile Data</strong>
          <span class="badge {{ $profile['complete'] === $profile['total'] ? 'bg-success' : 'bg-secondary' }}">{{ $profile['complete'] }}/{{ $profile['total'] }} fields</span>
        </div>
        <div class="subtext mb-2">Registration and contact details.</div>
        @if($profile['missing'])
          <p class="subtext text-warning-emphasis mb-0"><i class="bi bi-exclamation-triangle me-1"></i> Missing: {{ implode(', ', array_map(fn ($f) => ucwords(str_replace('_', ' ', $f)), $profile['missing'])) }}</p>
        @else
          <p class="subtext text-success mb-0"><i class="bi bi-check2 me-1"></i> All registration fields completed.</p>
        @endif
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card p-3 h-100 bg-light border">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong class="small text-uppercase text-muted">2. Statutory Documents</strong>
          <span class="badge {{ $readiness['counts']['verified'] === $readiness['total'] && $readiness['total'] > 0 ? 'bg-success' : 'bg-secondary' }}">
            {{ $readiness['not_configured'] ? 'Not configured' : $readiness['counts']['verified'].'/'.$readiness['total'].' verified' }}
          </span>
        </div>
        <div class="subtext mb-1">
          <strong>CBL:</strong> {{ $cblReq ? \App\Services\DocumentCompleteness::label(\App\Services\DocumentCompleteness::stateOf($cblReq)) : 'Not configured' }} ·
          <strong>COR:</strong> {{ $corReq ? \App\Services\DocumentCompleteness::label(\App\Services\DocumentCompleteness::stateOf($corReq)) : 'Not configured' }}
        </div>
        @if($readiness['not_configured'])
          <p class="subtext text-muted mb-0">No onboarding documents configured.</p>
        @elseif($readiness['counts']['missing'] > 0)
          <p class="subtext text-danger mb-0"><i class="bi bi-x-circle me-1"></i> {{ $readiness['counts']['missing'] }} required document(s) missing.</p>
        @elseif($readiness['counts']['incomplete'] > 0 || $readiness['counts']['clarification'] > 0)
          @if($readiness['counts']['incomplete'] > 0)
            <p class="subtext text-danger mb-0">{{ $readiness['counts']['incomplete'] }} required document(s) incomplete.</p>
          @endif
          @if($readiness['counts']['clarification'] > 0)
            <p class="subtext text-warning-emphasis mb-0">{{ $readiness['counts']['clarification'] }} required document(s) need clarification.</p>
          @endif
        @elseif($readiness['counts']['awaiting'] > 0)
          <p class="subtext text-warning-emphasis mb-0"><i class="bi bi-hourglass-split me-1"></i> Awaiting document verification.</p>
        @else
          <p class="subtext text-success mb-0"><i class="bi bi-check2 me-1"></i> Statutory documents verified.</p>
        @endif
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card p-3 h-100 bg-light border">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong class="small text-uppercase text-muted">3. Accounting Setup</strong>
          <span class="badge {{ $accountsCount > 0 && $accountingYearsCount > 0 && $openPeriodsCount > 0 ? 'bg-success' : 'bg-secondary' }}">
            {{ $accountsCount > 0 && $accountingYearsCount > 0 && $openPeriodsCount > 0 ? 'Configured' : 'Needs setup' }}
          </span>
        </div>
        <div class="subtext mb-2">
          <ul class="list-unstyled mb-0">
            <li>{{ $accountsCount > 0 ? '✓' : '!' }} Active accounts: {{ $accountsCount }}</li>
            <li>{{ $accountingYearsCount > 0 ? '✓' : '!' }} Accounting years: {{ $accountingYearsCount }}</li>
            <li>{{ $openPeriodsCount > 0 ? '✓' : '!' }} Open periods: {{ $openPeriodsCount }}</li>
          </ul>
          <p class="mb-0 mt-2">The voucher form checks whether the transaction date is covered.</p>
        </div>
        <div class="actions">
          @can('viewAny', \App\Models\Account::class)<a class="btn btn-sm btn-outline-secondary" href="{{ route('accounts.index',['client_id'=>$record->id]) }}">Accounts</a>@endcan
          @can('viewAny', \App\Models\Account::class)<a class="btn btn-sm btn-outline-secondary" href="{{ route('accounting-periods.index', ['client_id' => $record->id]) }}">Periods</a>@endcan
        </div>
      </div>
    </div>
  </div>
</section>

@php($checklist = \App\Services\DocumentCompleteness::forClient($record))
@php($complianceRecords = \App\Services\Access::query(\App\Models\ComplianceRecord::class)->where('client_id',$record->id)->get())
@php($invoices = \App\Services\Access::query(\App\Models\Invoice::class)->where('client_id',$record->id)->with('payments')->get())

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <section class="panel panel-pad h-100">
      <div class="toolbar mb-2">
        <h4 class="section-title mb-0">Documents</h4>
        @if($checklist['not_configured'])<x-badge status="Not configured"/>@else<span class="badge-status tone-success">{{ $checklist['percentage'] }}%</span>@endif
      </div>
      @if(!$checklist['not_configured'])
        <progress class="metric-progress" aria-label="Document completeness" value="{{ $checklist['percentage'] }}" max="100"></progress>
        <p class="subtext mt-2 mb-2">{{ $checklist['verified'] }} verified · {{ $checklist['missing'] }} missing · {{ $checklist['awaiting'] }} awaiting</p>
      @else
        <p class="subtext mb-2">No required documents configured.</p>
      @endif
      <div class="actions mt-auto">
        @can('viewAny',\App\Models\DocumentRequirement::class)<a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.checklist',$record) }}">Checklist</a>@endcan
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.index',['client_id'=>$record->id]) }}">Documents</a>
      </div>
    </section>
  </div>

  <div class="col-md-4">
    <section class="panel panel-pad h-100">
      <div class="toolbar mb-2">
        <h4 class="section-title mb-0">Compliance</h4>
        <span class="badge {{ $complianceRecords->whereNotIn('status',['Filed','Completed'])->count() > 0 ? 'bg-warning text-dark' : 'bg-success' }}">
          {{ $complianceRecords->whereNotIn('status',['Filed','Completed'])->count() }} outstanding
        </span>
      </div>
      @if($complianceRecords->isEmpty())
        <p class="subtext mb-2">No compliance requirements configured.</p>
      @else
        <p class="subtext mb-2">{{ $complianceRecords->where('status','Filed')->count() }} filed · {{ $complianceRecords->where('status','Completed')->count() }} completed · {{ $complianceRecords->whereNotIn('status',['Filed','Completed'])->count() }} due</p>
      @endif
      <div class="actions mt-auto">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.checklist',$record) }}">Compliance checklist</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.index',['client_id'=>$record->id]) }}">Deadlines</a>
      </div>
    </section>
  </div>

  <div class="col-md-4">
    <section class="panel panel-pad h-100">
      <div class="toolbar mb-2">
        <h4 class="section-title mb-0">Billing</h4>
        @php($unpaidBalanceCents = $invoices->sum(fn($inv) => $inv->balance_cents))
        <span class="badge {{ $unpaidBalanceCents > 0 ? 'bg-warning text-dark' : 'bg-success' }}">
          {{ $unpaidBalanceCents > 0 ? \App\Support\Money::format($unpaidBalanceCents / 100).' balance' : 'Settled' }}
        </span>
      </div>
      <p class="subtext mb-2">{{ $invoices->count() }} invoice(s) recorded · {{ \App\Support\Money::format($invoices->sum(fn($i) => $i->paid_cents) / 100) }} collected</p>
      <div class="actions mt-auto">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('billing.index',['client_id'=>$record->id]) }}">View invoices</a>
      </div>
    </section>
  </div>
</div>

<div class="row g-4">@foreach($related as $label=>$items)@php($key=['Documents'=>'documents','General Journal'=>'ledger','Ledger Review'=>'ledger','Compliance'=>'compliance','Billing'=>'billing'][$label])<div class="col-lg-6"><section class="panel panel-pad"><div class="toolbar"><h2 class="section-title">{{ $label }}</h2><a href="{{ route($key.'.index',['client_id'=>$record->id]) }}" class="btn btn-sm btn-outline-secondary">View all</a></div><ul class="timeline">@forelse($items as $item)<li><div class="timeline-content"><a href="{{ route($key.'.show',$item) }}">{{ $item->{\App\Support\Modules::get($key)['label']} }}</a></div><x-badge :status="$item->display_status??$item->status"/></li>@empty<li class="subtext">No related records yet. @can('create',\App\Support\Modules::get($key)['model'])<a data-modal href="{{ route($key.'.create',['client_id'=>$record->id]) }}">Add one</a>@endcan</li>@endforelse</ul></section></div>@endforeach</div>

<section class="panel panel-pad mt-4"><h2 class="section-title">Client activity</h2><ul class="timeline">@forelse($activity as $entry)<li><div class="timeline-content">{{ \App\Support\AuditHistory::description($entry->description) }}<div class="subtext">{{ $entry->user?->name }} · {{ $entry->created_at->format('M j, Y g:i A') }}</div></div></li>@empty<li>No activity recorded yet.</li>@endforelse</ul></section>
@endsection
