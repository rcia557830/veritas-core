@extends('layouts.app')
@section('title','Compliance checklist')
@section('content')
<div class="toolbar">
  <a class="btn btn-outline-secondary" href="{{ route('clients.show',$client) }}">← {{ $client->business_name }}</a>
  <div class="actions">
    @can('create',App\Models\ComplianceRecord::class)<a class="btn btn-primary" href="{{ route('compliance.create',['client_id'=>$client->id]) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add requirement</a>@endcan
  </div>
</div>

<section class="panel panel-pad mb-4">
  <div class="toolbar"><h2 class="modal-title">Document completeness</h2>
    @if($docStats['not_configured'])<x-badge status="Not configured"/>@else<span class="badge-status tone-success">{{ $docStats['percentage'] }}%</span>@endif
  </div>
  @if($docStats['not_configured'])
    <p class="subtext">No required documents have been configured for this client.</p>
  @else
    <p class="subtext mt-2">{{ $docStats['verified'] }} of {{ $docStats['total_required'] }} required documents verified · {{ $docStats['missing'] }} missing · {{ $docStats['awaiting'] }} awaiting verification</p>
  @endif
</section>

<div class="row g-3">
@forelse($records as $record)
  <div class="col-12">
    <article class="panel panel-pad">
      <div class="toolbar mb-2">
        <div>
          <h2 class="modal-title mb-1">{{ $record->requirement }}</h2>
          <div class="subtext">{{ $record->agency }}@if($record->reporting_period) · {{ $record->reporting_period }}@endif</div>
        </div>
        <div class="actions"><x-badge :status="$record->status"/><x-badge :status="$record->urgency"/></div>
      </div>
      <dl class="detail-grid">
        <div><dt>Official filing deadline</dt><dd>{{ $record->due_date->format('M j, Y') }}</dd></div>
        <div><dt>Internal submission deadline</dt><dd>{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}@if($record->submission_deadline_is_provisional)<span class="subtext"> (calculated)</span>@endif</dd></div>
        @if($record->reference_number)<div><dt>Filing reference</dt><dd>{{ $record->reference_number }}</dd></div>@endif
        @if($record->filed_date)<div><dt>Filed date</dt><dd>{{ $record->filed_date->format('M j, Y') }}</dd></div>@endif
        <div><dt>Assigned staff</dt><dd>{{ $record->assignee?->name ?? 'Unassigned' }}</dd></div>
      </dl>
      @if($record->submission_deadline_override_reason)<p class="subtext"><strong>Override reason:</strong> {{ $record->submission_deadline_override_reason }}</p>@endif
      @if($record->notes)<p class="section-description">{{ $record->notes }}</p>@endif

      @include('compliance._followups', ['record' => $record, 'users' => $users])

      <div class="actions mt-3">
        @can('update',$record)<a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.edit',$record) }}">Edit</a>@endcan
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.show',$record) }}">View</a>
      </div>
    </article>
  </div>
@empty
  <div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div><h2>No compliance requirements</h2><p>Configure this client's government obligations to track deadlines.</p>@can('create',App\Models\ComplianceRecord::class)<a class="btn btn-primary" href="{{ route('compliance.create',['client_id'=>$client->id]) }}">Add requirement</a>@endcan</div></div>
@endforelse
</div>
@endsection
