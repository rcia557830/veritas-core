@extends('layouts.app')
@section('title','Client profile')
@section('content')<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('clients.index') }}">← Clients</a><div class="actions"><a data-modal class="btn btn-primary" href="{{ route('clients.edit',$record) }}">Edit client</a>@can($record->status==='Archived'?'restore':'archive',$record)<form method="post" action="{{ route('clients.archive',$record) }}" data-confirm="{{ $record->status==='Archived'?'Restore this client?':'Archive this client? Related records will be retained.' }}">@csrf<button class="btn btn-outline-danger">{{ $record->status==='Archived'?'Restore':'Archive' }}</button></form>@endcan</div></div>
<section class="panel panel-pad mb-4"><div class="toolbar"><h2 class="modal-title">{{ $record->business_name }}</h2><x-badge :status="$record->status"/></div><dl class="detail-grid">@foreach($config['fields'] as $key=>$field)<div><dt>{{ $field[0] }}</dt><dd>{{ \App\Support\Display::value($record,$key) }}</dd></div>@endforeach<div><dt>Assigned employee</dt><dd>{{ $record->assignee?->name??'Unassigned' }}</dd></div></dl></section>
@php($checklist = \App\Services\DocumentCompleteness::forClient($record))
<section class="panel panel-pad mb-4">
  <div class="toolbar">
    <h2 class="section-title">Document checklist</h2>
    @if($checklist['not_configured'])<x-badge status="Not configured"/>@else<span class="badge-status tone-success">{{ $checklist['percentage'] }}%</span>@endif
  </div>
  @if(!$checklist['not_configured'])
    <progress class="metric-progress" aria-label="Document completeness" value="{{ $checklist['percentage'] }}" max="100"></progress>
    <p class="subtext mt-2">{{ $checklist['verified'] }} of {{ $checklist['total_required'] }} required documents verified · {{ $checklist['missing'] }} missing · {{ $checklist['awaiting'] }} awaiting verification · {{ $checklist['incomplete'] }} incomplete · {{ $checklist['clarification'] }} need clarification</p>
  @else
    <p class="subtext">No required documents have been configured for this client.</p>
  @endif
  @can('viewAny',\App\Models\DocumentRequirement::class)<a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.checklist',$record) }}">Open checklist</a>@endcan
</section>
@php($readiness = \App\Services\OnboardingReadiness::calculate($record))
@php($profile = \App\Services\OnboardingReadiness::profileProgress($record))
@php($cblReq = $readiness['requirements']->first(fn($r) => \App\Services\OnboardingReadiness::isCbl($r)))
@php($corReq = $readiness['requirements']->first(fn($r) => \App\Services\OnboardingReadiness::isCor($r)))
<section class="panel panel-pad mb-4">
  <div class="toolbar">
    <h2 class="section-title">Onboarding</h2>
    <div class="actions">
      <x-badge :status="\App\Services\OnboardingReadiness::label($readiness['state'])"/>
      <a class="btn btn-sm btn-outline-secondary" href="{{ route('clients.onboarding.checklist',$record) }}">Open checklist</a>
    </div>
  </div>
  @if(!$readiness['not_configured'])
    <progress class="metric-progress" aria-label="Onboarding progress" value="{{ $readiness['percentage'] }}" max="100"></progress>
    <p class="subtext mt-2">{{ $readiness['counts']['verified'] }} of {{ $readiness['total'] }} required documents verified · {{ $readiness['counts']['missing'] }} missing</p>
  @else
    <p class="subtext">No required onboarding documents configured.</p>
  @endif
  <p class="subtext">Registration: {{ $profile['complete'] }}/{{ $profile['total'] }} profile fields complete.@if($cblReq || $corReq) · CBL: {{ $cblReq ? \App\Services\DocumentCompleteness::label(\App\Services\DocumentCompleteness::stateOf($cblReq)) : 'Not configured' }} · COR: {{ $corReq ? \App\Services\DocumentCompleteness::label(\App\Services\DocumentCompleteness::stateOf($corReq)) : 'Not configured' }}@endif</p>
</section>
@can('viewAny',\App\Models\ComplianceRecord::class)@php($complianceRecords = \App\Services\Access::query(\App\Models\ComplianceRecord::class)->where('client_id',$record->id)->get())<section class="panel panel-pad mb-4"><div class="toolbar"><h2 class="section-title">Compliance checklist</h2><a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.checklist',$record) }}">Open checklist</a></div>@if($complianceRecords->isEmpty())<p class="subtext">No compliance requirements configured for this client.</p>@else<p class="subtext">{{ $complianceRecords->where('status','Filed')->count() }} filed · {{ $complianceRecords->where('status','Completed')->count() }} completed · {{ $complianceRecords->whereNotIn('status',['Filed','Completed'])->count() }} outstanding</p>@endif</section>@endcan

<div class="row g-4">@foreach($related as $label=>$items)@php($key=['Documents'=>'documents','Ledger Review'=>'ledger','Compliance'=>'compliance','Billing'=>'billing'][$label])<div class="col-lg-6"><section class="panel panel-pad"><div class="toolbar"><h2 class="section-title">{{ $label }}</h2><a href="{{ route($key.'.index',['client_id'=>$record->id]) }}" class="btn btn-sm btn-outline-secondary">View all</a></div><ul class="timeline">@forelse($items as $item)<li><div class="timeline-content"><a href="{{ route($key.'.show',$item) }}">{{ $item->{\App\Support\Modules::get($key)['label']} }}</a></div><x-badge :status="$item->display_status??$item->status"/></li>@empty<li class="subtext">No related records yet. @can('create',\App\Support\Modules::get($key)['model'])<a data-modal href="{{ route($key.'.create',['client_id'=>$record->id]) }}">Add one</a>@endcan</li>@endforelse</ul></section></div>@endforeach</div>
<section class="panel panel-pad mt-4"><h2 class="section-title">Client activity</h2><ul class="timeline">@forelse($activity as $entry)<li><div class="timeline-content">{{ $entry->description }}<div class="subtext">{{ $entry->user?->name }} · {{ $entry->created_at->format('M j, Y g:i A') }}</div></div></li>@empty<li>No activity recorded yet.</li>@endforelse</ul></section>@endsection
