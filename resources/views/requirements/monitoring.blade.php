@extends('layouts.app')
@section('title',request('follow_up')==='open'?'Document Follow-Ups':(request('state')==='missing'?'Missing Requirements':'Document Requirements'))
@section('content')
@include('documents.workspace-tools')
<div class="toolbar">
  <p class="toolbar-copy">{{ $summary['total'] }} active requirements across accessible clients</p>
</div>

<div class="row g-3 mb-4">
  <x-stat label="Missing" :value="$summary['missing']" icon="exclamation-circle" tone="danger"/>
  <x-stat label="Awaiting verification" :value="$summary['awaiting']" icon="hourglass-split" tone="warning"/>
  <x-stat label="Incomplete" :value="$summary['incomplete']" icon="file-earmark-x" tone="danger"/>
  <x-stat label="Needs clarification" :value="$summary['clarification']" icon="question-circle" tone="warning"/>
</div>

<form method="get" class="filters" aria-label="Filter missing documents">
  <label>Client<select name="client_id" class="form-select"><option value="">All accessible clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
  <label class="search-filter">Search client<input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Client name or code"></label>
  <label>State<select name="state" class="form-select"><option value="">All states</option>
    <option value="missing" @selected(request('state')==='missing')>Missing only</option>
    <option value="awaiting" @selected(request('state')==='awaiting')>Awaiting verification</option>
    <option value="incomplete" @selected(request('state')==='incomplete')>Incomplete</option>
    <option value="clarification" @selected(request('state')==='clarification')>Needs clarification</option>
    <option value="verified" @selected(request('state')==='verified')>Verified</option>
  </select></label>
  <details class="workspace-tools filter-details" @if(request()->hasAny(['accounting_period_id','type','due','follow_up','per_page'])) open @endif><summary>More filters</summary><div class="filters mt-2">
  <label>Period<select name="accounting_period_id" class="form-select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('accounting_period_id')==$period->id)>{{ $period->client->business_name }} — {{ $period->label }}</option>@endforeach</select></label>
  <label>Type<select name="type" class="form-select"><option value="">All types</option>@foreach($types as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>

  <label>Due<select name="due" class="form-select"><option value="all">Any due date</option>
    <option value="overdue" @selected(request('due')==='overdue')>Overdue</option>
    <option value="today" @selected(request('due')==='today')>Due today</option>
    <option value="soon" @selected(request('due')==='soon')>Due within 10 days</option>
  </select></label>
  <label>Follow-ups<select name="follow_up" class="form-select"><option value="">All records</option><option value="open" @selected(request('follow_up')==='open')>Open or in progress</option></select></label><x-per-page/></div></details><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('requirements.monitoring') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">Missing document monitoring</caption><thead><tr><th>Client</th><th>Requirement</th><th>Type</th><th>Period</th><th>Due</th><th>State</th><th>Follow-up</th><th>Actions</th></tr></thead><tbody>
  @forelse($records as $requirement)
    @php($state=\App\Services\DocumentCompleteness::stateOf($requirement))
    <tr>
      <td><a href="{{ route('clients.show',$requirement->client) }}">{{ $requirement->client->business_name }}</a></td>
      <td><a class="text-button" href="{{ route('requirements.checklist',$requirement->client_id) }}">{{ $requirement->name }}</a></td>
      <td>{{ $requirement->type }}</td>
      <td>{{ $requirement->accountingPeriod?->label ?? '—' }}</td>
      <td>{{ $requirement->due_date?->format('M j, Y') ?? '—' }}</td>
      <td><x-badge :status="\App\Services\DocumentCompleteness::label($state)"/></td>
      <td>
        @php($latestFollowUp = $requirement->followUps->first())
        @if($latestFollowUp)
          <x-badge :status="$latestFollowUp->status"/>
          <div class="subtext">{{ $latestFollowUp->assignee?->name ?? 'Unassigned' }}@if($latestFollowUp->follow_up_date) · {{ $latestFollowUp->follow_up_date->format('M j') }}@endif</div>
        @else
          <span class="subtext">None</span>
        @endif
      </td>
      <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.checklist',$requirement->client_id) }}#requirement-{{ $requirement->id }}">{{ request('follow_up')==='open' || ($latestFollowUp && $latestFollowUp->status !== 'Resolved') ? 'Update follow-up' : 'Open requirement' }}</a></td>
    </tr>
  @empty
    <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div><h2>Nothing outstanding</h2><p>No requirements match these filters.</p><a class="btn btn-outline-secondary" href="{{ route('requirements.monitoring') }}">Clear filters</a></div></td></tr>
  @endforelse
  </tbody></table></div>
</div>
<x-pagination :records="$records"/>
@endsection
