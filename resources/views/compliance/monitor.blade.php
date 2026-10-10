@extends('layouts.app')
@section('title','Compliance Monitoring')
@section('content')
<div class="toolbar">
  <p class="toolbar-copy">{{ $summary['pending'] }} open requirements across accessible clients</p>
  <div class="actions"><a class="btn btn-outline-secondary" href="{{ route('compliance.index') }}">All compliance records</a></div>
</div>

<div class="row g-3 mb-4">
  <x-stat label="Pending requirements" :value="$summary['pending']" icon="calendar2-check"/>
  <x-stat label="Submission overdue" :value="$summary['submission_overdue']" icon="exclamation-circle" :tone="$summary['submission_overdue']?'danger':'success'"/>
  <x-stat label="Submission approaching" :value="$summary['submission_approaching']" icon="hourglass-split" tone="warning"/>
  <x-stat label="Filing overdue" :value="$summary['filing_overdue']" icon="calendar2-x" :tone="$summary['filing_overdue']?'danger':'success'"/>
  <x-stat label="Filing approaching" :value="$summary['filing_approaching']" icon="calendar2-week" tone="warning"/>
  <x-stat label="Filed" :value="$summary['filed']" icon="check2-circle" tone="success"/>
  <x-stat label="Completed" :value="$summary['completed']" icon="check2-all" tone="success"/>
</div>

<form method="get" class="filters" aria-label="Filter compliance monitoring">
  <label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Requirement, agency, client"></label>
  <label>Agency<select name="agency" class="form-select"><option value="">All agencies</option>@foreach($agencies as $agency)<option value="{{ $agency }}" @selected(request('agency')===$agency)>{{ $agency }}</option>@endforeach</select></label>
  <label>Reporting period<select name="reporting_period" class="form-select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period }}" @selected(request('reporting_period')===$period)>{{ $period }}</option>@endforeach</select></label>
  <label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach(['Pending','In Preparation','Awaiting Client Documents','Ready for Filing','Filed','Completed'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
  <label>Client<select name="client_id" class="form-select"><option value="">All clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
  @if($users->isNotEmpty())<label>Staff<select name="assigned_to" class="form-select"><option value="">All staff</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(request('assigned_to')==$user->id)>{{ $user->name }}</option>@endforeach</select></label>@endif
  <label>Deadline<select name="deadline" class="form-select"><option value="all">Any deadline</option>
    <option value="submission_overdue" @selected(request('deadline')==='submission_overdue')>Submission overdue</option>
    <option value="submission_approaching" @selected(request('deadline')==='submission_approaching')>Submission approaching</option>
    <option value="filing_overdue" @selected(request('deadline')==='filing_overdue')>Filing overdue</option>
    <option value="filing_approaching" @selected(request('deadline')==='filing_approaching')>Filing approaching</option>
  </select></label>
  <button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('compliance.monitoring') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">Compliance monitoring</caption><thead><tr><th>Requirement</th><th>Client</th><th>Agency</th><th>Period</th><th>Submission due</th><th>Filing due</th><th>Status</th><th>Urgency</th></tr></thead><tbody>
  @forelse($records as $record)
    <tr>
      <td><a class="text-button" href="{{ route('compliance.show',$record) }}">{{ $record->requirement }}</a></td>
      <td><a href="{{ route('clients.show',$record->client) }}">{{ $record->client->business_name }}</a></td>
      <td>{{ $record->agency }}</td>
      <td>{{ $record->reporting_period ?? '—' }}</td>
      <td>{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</td>
      <td>{{ $record->due_date->format('M j, Y') }}</td>
      <td><x-badge :status="$record->status"/></td>
      <td><x-badge :status="$record->urgency"/></td>
    </tr>
  @empty
    <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div><h2>Nothing to monitor</h2><p>No compliance requirements match these filters.</p><a class="btn btn-outline-secondary" href="{{ route('compliance.monitoring') }}">Clear filters</a></div></td></tr>
  @endforelse
  </tbody></table></div>
</div>
<x-pagination :records="$records"/>
@endsection
