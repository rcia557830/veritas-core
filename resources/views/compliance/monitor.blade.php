@extends('layouts.app')
@section('title','Compliance')
@section('content')
<div class="workspace-heading">
  <div><h2>Compliance deadlines</h2><p class="subtext">Client submission and official filing dates, together. {{ $summary['pending'] }} open requirements across accessible clients.</p></div>
  @can('create',App\Models\ComplianceRecord::class)<a class="btn btn-primary" href="{{ route('compliance.create',request()->only('client_id')) }}">Add requirement</a>@endcan
</div>
<p class="form-text">Upcoming shows approaching deadlines with no overdue action. Overdue includes either submission or filing delays. Completed includes Filed and Completed records.</p>
<form method="get" class="filters" aria-label="Filter compliance monitoring">
  @if(request('view'))<input type="hidden" name="view" value="{{ request('view') }}">@endif
  <label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Requirement, agency, client"></label>
  <label>Agency<select name="agency" class="form-select"><option value="">All agencies</option>@foreach($agencies as $agency)<option value="{{ $agency }}" @selected(request('agency',request('type'))===$agency)>{{ $agency }}</option>@endforeach</select></label>
  <label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach(['Pending','In Preparation','Awaiting Client Documents','Ready for Filing','Filed','Completed','Overdue'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
  <label>Client<select name="client_id" class="form-select"><option value="">All clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
  <details class="workspace-tools filter-details" @if(request()->hasAny(['reporting_period','assigned_to','deadline','follow_up','from','to','per_page'])) open @endif><summary>More filters</summary><div class="filters mt-2">
  <label>Reporting period<select name="reporting_period" class="form-select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period }}" @selected(request('reporting_period')===$period)>{{ $period }}</option>@endforeach</select></label>


  @if($users->isNotEmpty())<label>Staff<select name="assigned_to" class="form-select"><option value="">All staff</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(request('assigned_to')==$user->id)>{{ $user->name }}</option>@endforeach</select></label>@endif
  <label>Deadline<select name="deadline" class="form-select"><option value="all">Any deadline</option>
    <option value="submission_overdue" @selected(request('deadline')==='submission_overdue')>Submission overdue</option>
    <option value="submission_approaching" @selected(request('deadline')==='submission_approaching')>Submission approaching</option>
    <option value="filing_overdue" @selected(request('deadline')==='filing_overdue')>Filing overdue</option>
    <option value="filing_approaching" @selected(request('deadline')==='filing_approaching')>Filing approaching</option>
  </select></label>
  <label>Follow-ups<select name="follow_up" class="form-select"><option value="">All records</option><option value="open" @selected(request('follow_up')==='open')>Open or in progress</option></select></label><label>Filing from<input type="date" name="from" class="form-control" value="{{ request('from') }}"></label><label>Filing to<input type="date" name="to" class="form-control" value="{{ request('to') }}"></label><x-per-page/></div></details><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('compliance.index') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover workspace-table"><caption class="visually-hidden">Compliance monitoring</caption><thead><tr><th>Requirement</th><th>Client</th><th>Client submission due</th><th>Official filing due</th><th>Status and urgency</th><th>Next action</th></tr></thead><tbody>
  @forelse($records as $record)
    <tr>
      <td><a class="text-button" href="{{ route('compliance.show',$record) }}">{{ $record->requirement }}</a><div class="subtext">{{ $record->agency }} · {{ $record->reporting_period ?? 'No reporting period' }}</div></td>
      <td><a href="{{ route('clients.show',$record->client) }}">{{ $record->client->business_name }}</a></td>
      <td>{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</td>
      <td>{{ $record->due_date->format('M j, Y') }}</td>
      <td><x-badge :status="$record->status" :compliance="true"/><div class="mt-2"><x-compliance-warnings :record="$record"/></div></td>
      <td>@if(\App\Services\DeadlineCalculator::isTerminal($record))<a href="{{ route('compliance.show',$record) }}">View history</a>
      @else<a href="{{ route('compliance.show',$record) }}#follow-ups">{{ $record->status==='Ready for Filing' ? 'Review filing details' : 'Follow up / update' }}</a>@endif</td>
    </tr>
  @empty
    <tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div><h2>Nothing to monitor</h2><p>No compliance requirements match these filters.</p><a class="btn btn-outline-secondary" href="{{ route('compliance.index') }}">Clear filters</a></div></td></tr>
  @endforelse
  </tbody></table></div>
</div>
<x-pagination :records="$records"/>
@endsection
