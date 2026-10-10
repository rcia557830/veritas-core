@extends('layouts.app')
@section('title','Document checklist')
@section('content')
<div class="toolbar">
  <a class="btn btn-outline-secondary" href="{{ route('clients.show',$client) }}">← {{ $client->business_name }}</a>
  <div class="actions">
    @can('create',App\Models\DocumentRequirement::class)<a class="btn btn-primary" href="{{ route('requirements.create',['client_id'=>$client->id]) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add requirement</a>@endcan
  </div>
</div>

<section class="panel panel-pad mb-4">
  <div class="toolbar"><h2 class="modal-title">Document completeness</h2>
    @if($stats['not_configured'])<x-badge status="Not configured"/>@else<span class="badge-status tone-success">{{ $stats['percentage'] }}%</span>@endif
  </div>
  @if($stats['not_configured'])
    <p class="subtext">No required documents have been configured for this client.</p>
  @else
    <progress class="metric-progress" aria-label="Completeness" value="{{ $stats['percentage'] }}" max="100"></progress>
    <p class="subtext mt-2">{{ $stats['verified'] }} of {{ $stats['total_required'] }} required documents verified · {{ $stats['missing'] }} missing · {{ $stats['awaiting'] }} awaiting verification · {{ $stats['incomplete'] }} incomplete · {{ $stats['clarification'] }} need clarification</p>
  @endif
</section>

<div class="row g-3">
@forelse($requirements as $requirement)
  @php($state=\App\Services\DocumentCompleteness::stateOf($requirement))
  @php($representative=\App\Services\DocumentCompleteness::representativeDocument($requirement))
  <div class="col-12">
    <article class="panel panel-pad {{ $requirement->is_active?'':'opacity-50' }}">
      <div class="toolbar mb-2">
        <div>
          <h2 class="modal-title mb-1">{{ $requirement->name }}</h2>
          <div class="subtext">{{ $requirement->type }}@if($requirement->accountingPeriod) · {{ $requirement->accountingPeriod->label }}@endif</div>
        </div>
        <div class="actions">
          <x-badge :status="$requirement->is_required ? 'Required' : 'Optional'"/>
          <x-badge :status="$requirement->is_active ? 'Active' : 'Inactive'"/>
          <x-badge :status="\App\Services\DocumentCompleteness::label($state)"/>
        </div>
      </div>

      <dl class="detail-grid">
        @if($requirement->due_date)<div><dt>Due date</dt><dd>{{ $requirement->due_date->format('M j, Y') }}</dd></div>@endif
        <div><dt>Submission</dt><dd>{{ $representative ? 'Submitted' : 'Not submitted' }}</dd></div>
        <div><dt>Verification</dt><dd>{{ \App\Services\DocumentCompleteness::label($state) }}</dd></div>
      </dl>
      @if($requirement->description)<p class="section-description">{{ $requirement->description }}</p>@endif
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
              @can('link',$requirement)<form method="post" action="{{ route('requirements.unlink',[$requirement,$document]) }}" data-confirm="Unlink this document from the requirement?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-secondary">Unlink</button></form>@endcan
            </li>
          @empty
            <li class="subtext">No document linked yet.</li>
          @endforelse
        </ul>
      </div>

      @can('link',$requirement)
      <form method="post" action="{{ route('requirements.link',$requirement) }}" class="mt-2">@csrf
        <div class="row g-2 align-items-end">
          <div class="col-md-8">
            <label class="form-label" for="link_doc_{{ $requirement->id }}">Link an uploaded document</label>
            <select id="link_doc_{{ $requirement->id }}" name="document_id" class="form-select">
              <option value="">Choose a document</option>
              @foreach($documents as $document)<option value="{{ $document->id }}">{{ $document->document_number }} — {{ $document->title }} ({{ $document->status }})</option>@endforeach
            </select>
          </div>
          <div class="col-md-4"><button class="btn btn-outline-secondary">Link</button></div>
        </div>
      </form>
      @endcan

      @include('requirements._followups', ['requirement' => $requirement, 'users' => $users])

      @if($requirement->is_active)
      <div class="actions mt-3">
        @can('update',$requirement)<a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.edit',$requirement) }}">Edit</a>@endcan
        @can('activate',$requirement)<form method="post" action="{{ route('requirements.toggle',$requirement) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Deactivate</button></form>@endcan
      </div>
      @else
      <div class="actions mt-3">
        @can('activate',$requirement)<form method="post" action="{{ route('requirements.toggle',$requirement) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Reactivate</button></form>@endcan
      </div>
      @endif
    </article>
  </div>
@empty
  <div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div><h2>No requirements configured</h2><p>Add expected documents for this client to track completeness.</p>@can('create',App\Models\DocumentRequirement::class)<a class="btn btn-primary" href="{{ route('requirements.create',['client_id'=>$client->id]) }}">Add requirement</a>@endcan</div></div>
@endforelse
</div>
@endsection
