@extends('layouts.app')
@section('title','Onboarding checklist')
@section('content')
@php($stateLabel = \App\Services\OnboardingReadiness::label($readiness['state']))
<div class="toolbar">
  <a class="btn btn-outline-secondary" href="{{ route('clients.show',$client) }}">← {{ $client->business_name }}</a>
  <div class="actions">
    <a class="btn btn-outline-secondary" href="{{ route('clients.onboarding') }}">All onboarding</a>
    <a class="btn btn-outline-secondary" href="{{ route('requirements.checklist',$client) }}">Document checklist</a>
  </div>
</div>

@if(session('onboarding_blockers'))
  <div class="alert alert-warning"><strong>Onboarding cannot be completed yet.</strong>
    <ul class="mb-0">@foreach(session('onboarding_blockers') as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
  </div>
@endif

<section class="panel panel-pad mb-4">
  <div class="toolbar">
    <div>
      <h2 class="modal-title">{{ $client->business_name }}</h2>
      <div class="subtext">{{ $client->client_code }} · {{ $client->business_type }} · Assigned: {{ $client->assignee?->name ?? 'Unassigned' }}</div>
    </div>
    <div class="actions">
      <x-badge :status="$client->status"/>
      <x-badge :status="$stateLabel"/>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-8">
      @if($readiness['not_configured'])
        <p class="subtext">No required onboarding documents have been configured for this client.</p>
      @else
        <progress class="metric-progress" aria-label="Onboarding completeness" value="{{ $readiness['percentage'] }}" max="100"></progress>
        <p class="subtext mt-2">{{ $readiness['counts']['verified'] }} of {{ $readiness['total'] }} required onboarding documents verified · {{ $readiness['counts']['missing'] }} missing · {{ $readiness['counts']['awaiting'] }} awaiting verification · {{ $readiness['counts']['incomplete'] }} incomplete · {{ $readiness['counts']['clarification'] }} need clarification</p>
      @endif
    </div>
    <div class="col-md-4">
      <p class="subtext mb-1"><strong>Registration progress</strong> — {{ $profile['complete'] }} of {{ $profile['total'] }} profile fields complete.</p>
      @if($profile['missing'])
        <p class="subtext">Missing: {{ implode(', ', array_map(fn ($f) => ucwords(str_replace('_', ' ', $f)), $profile['missing'])) }}.</p>
      @endif
    </div>
  </div>

  @can('activate',$client)
  <div class="mt-3 border-top pt-3">
    @if($readiness['onboarded'])
      <p class="subtext mb-1">Onboarded on {{ $client->onboarded_at?->format('M j, Y g:i A') }} by {{ $client->onboardedBy?->name ?? 'staff' }}.</p>
    @elseif($readiness['state'] === \App\Services\OnboardingReadiness::STATE_READY)
      <form method="post" action="{{ route('clients.onboarding.complete',$client) }}" data-confirm="Mark this client's onboarding complete?">@csrf<button class="btn btn-primary">Complete onboarding</button></form>
    @elseif($readiness['state'] === \App\Services\OnboardingReadiness::STATE_NOT_CONFIGURED)
      <form method="post" action="{{ route('clients.onboarding.complete',$client) }}" class="mt-2">@csrf
        <p class="form-text">This client has no configured onboarding requirements. To complete onboarding anyway, provide a justification (recorded in the audit trail).</p>
        <div class="row g-2 align-items-end">
          <div class="col-md-8"><x-field name="exemption_reason" label="Exemption justification" type="textarea"/></div>
          <div class="col-md-4"><button class="btn btn-primary">Complete with exemption</button></div>
        </div>
      </form>
    @else
      <p class="subtext">Onboarding can be completed once all required onboarding documents are verified.</p>
    @endif
  </div>
  @endcan
</section>

<div class="row g-3">
@forelse($requirements as $requirement)
  @php($state = \App\Services\DocumentCompleteness::stateOf($requirement))
  @php($representative = \App\Services\DocumentCompleteness::representativeDocument($requirement))
  @php($verification = \App\Services\OnboardingReadiness::verificationInfo($requirement))
  <div class="col-12">
    <article class="panel panel-pad {{ $requirement->is_active?'':'opacity-50' }}">
      <div class="toolbar mb-2">
        <div>
          <h2 class="modal-title mb-1">{{ $requirement->name }}</h2>
          <div class="subtext">{{ $requirement->type }}</div>
        </div>
        <div class="actions">
          @if(\App\Services\OnboardingReadiness::isCbl($requirement))<x-badge status="CBL"/>@endif
          @if(\App\Services\OnboardingReadiness::isCor($requirement))<x-badge status="COR"/>@endif
          <x-badge :status="$requirement->is_required ? 'Required' : 'Optional'"/>
          <x-badge :status="\App\Services\DocumentCompleteness::label($state)"/>
        </div>
      </div>

      <dl class="detail-grid">
        <div><dt>Submission</dt><dd>{{ $representative ? 'Submitted' : 'Not submitted' }}</dd></div>
        <div><dt>Verification</dt><dd>{{ \App\Services\DocumentCompleteness::label($state) }}</dd></div>
        @if($verification['reviewer'])<div><dt>Reviewed by</dt><dd>{{ $verification['reviewer'] }}@if($verification['verified_at']) · {{ $verification['verified_at']->format('M j, Y') }}@endif</dd></div>@endif
      </dl>

      @if($requirement->remarks)<p class="subtext"><strong>Staff remarks:</strong> {{ $requirement->remarks }}</p>@endif

      <div class="mt-2">
        <strong class="subtext">Associated documents</strong>
        <ul class="timeline mb-0">
          @forelse($requirement->documents as $document)
            <li>
              <div class="timeline-content">
                <a href="{{ route('documents.show',$document) }}">{{ $document->document_number }} — {{ $document->title }}</a>
                <div class="subtext">Received {{ $document->received_date->format('M j, Y') }}</div>
              </div>
              <x-badge :status="$document->status"/>
            </li>
          @empty
            <li class="subtext">No document linked yet. Link an uploaded document from the document checklist.</li>
          @endforelse
        </ul>
      </div>
    </article>
  </div>
@empty
  <div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div><h2>No onboarding requirements</h2><p>Configure CBL, COR, or other registration documents to track this client's onboarding.</p>@can('create',App\Models\DocumentRequirement::class)<a class="btn btn-primary" href="{{ route('requirements.create',['client_id'=>$client->id]) }}">Add requirement</a>@endcan</div></div>
@endforelse
</div>
@endsection
