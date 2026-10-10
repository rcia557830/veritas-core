@extends('layouts.app')
@section('title','Missing Documents')
@section('content')
<div class="toolbar">
  <p class="toolbar-copy">{{ $summary['total'] }} active requirements across accessible clients</p>
  <div class="actions"><a class="btn btn-outline-secondary" href="{{ route('requirements.index') }}">All requirements</a></div>
</div>

<div class="row g-3 mb-4">
  <x-stat label="Missing" :value="$summary['missing']" icon="exclamation-circle" tone="danger"/>
  <x-stat label="Awaiting verification" :value="$summary['awaiting']" icon="hourglass-split" tone="warning"/>
  <x-stat label="Incomplete" :value="$summary['incomplete']" icon="file-earmark-x" tone="danger"/>
  <x-stat label="Needs clarification" :value="$summary['clarification']" icon="question-circle" tone="warning"/>
</div>

<form method="get" class="filters" aria-label="Filter missing documents">
  <label class="search-filter">Search client<input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Client name or code"></label>
  <label>Period<select name="accounting_period_id" class="form-select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('accounting_period_id')==$period->id)>{{ $period->client->business_name }} — {{ $period->label }}</option>@endforeach</select></label>
  <label>Type<select name="type" class="form-select"><option value="">All types</option>@foreach($types as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>
  <label>State<select name="state" class="form-select"><option value="">All states</option>
    <option value="missing" @selected(request('state')==='missing')>Missing only</option>
    <option value="awaiting" @selected(request('state')==='awaiting')>Awaiting verification</option>
    <option value="incomplete" @selected(request('state')==='incomplete')>Incomplete</option>
    <option value="clarification" @selected(request('state')==='clarification')>Needs clarification</option>
    <option value="verified" @selected(request('state')==='verified')>Verified</option>
  </select></label>
  <label>Due<select name="due" class="form-select"><option value="all">Any due date</option>
    <option value="overdue" @selected(request('due')==='overdue')>Overdue</option>
    <option value="today" @selected(request('due')==='today')>Due today</option>
    <option value="soon" @selected(request('due')==='soon')>Due within 10 days</option>
  </select></label>
  <button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('requirements.monitoring') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">Missing document monitoring</caption><thead><tr><th>Client</th><th>Requirement</th><th>Type</th><th>Period</th><th>Due</th><th>State</th><th>Actions</th></tr></thead><tbody>
  @forelse($records as $requirement)
    @php($state=\App\Services\DocumentCompleteness::stateOf($requirement))
    <tr>
      <td><a href="{{ route('clients.show',$requirement->client) }}">{{ $requirement->client->business_name }}</a></td>
      <td><a class="text-button" href="{{ route('requirements.checklist',$requirement->client_id) }}">{{ $requirement->name }}</a></td>
      <td>{{ $requirement->type }}</td>
      <td>{{ $requirement->accountingPeriod?->label ?? '—' }}</td>
      <td>{{ $requirement->due_date?->format('M j, Y') ?? '—' }}</td>
      <td><x-badge :status="\App\Services\DocumentCompleteness::label($state)"/></td>
      <td><div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.checklist',$requirement->client_id) }}">Client checklist</a>@can('update',$requirement)<a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.edit',$requirement) }}">Edit</a>@endcan</div></td>
    </tr>
  @empty
    <tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div><h2>Nothing outstanding</h2><p>No requirements match these filters.</p><a class="btn btn-outline-secondary" href="{{ route('requirements.monitoring') }}">Clear filters</a></div></td></tr>
  @endforelse
  </tbody></table></div>
</div>
<x-pagination :records="$records"/>
@endsection
