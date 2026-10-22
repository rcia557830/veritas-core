@extends('layouts.app')
@section('title','Compliance')
@section('content')
<div class="workspace-heading compliance-header">
  <div class="compliance-header-content">
    <div class="compliance-title-wrap">
      <h2>Compliance Deadlines</h2>
      <span class="compliance-open-count" title="{{ $summary['pending'] }} open requirements across accessible clients">
        {{ $summary['pending'] }} open
      </span>
    </div>
    <p class="subtext">Monitor client submission targets and official filing deadlines in one place.</p>
  </div>
  @can('create', App\Models\ComplianceRecord::class)
    <div class="compliance-header-actions">
      <a class="btn btn-primary" href="{{ route('compliance.create', request()->only('client_id')) }}">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add requirement
      </a>
    </div>
  @endcan
</div>
<p class="compliance-tab-hint form-text">Upcoming shows approaching deadlines with no overdue action. Overdue includes either submission or filing delays. Completed includes Filed and Completed records.</p>

<form method="get" class="compliance-filter-card" aria-label="Filter compliance monitoring">
  @if(request('view'))<input type="hidden" name="view" value="{{ request('view') }}">@endif
  <div class="compliance-filter-row compliance-filter-row-primary">
    <div class="filter-col filter-col-search">
      <label class="form-label" for="filter-q">Search</label>
      <input type="search" id="filter-q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Requirement, agency, client">
    </div>
    <div class="filter-col filter-col-select">
      <label class="form-label" for="filter-agency">Agency</label>
      <select id="filter-agency" name="agency" class="form-select">
        <option value="">All agencies</option>
        @foreach($agencies as $agency)
          <option value="{{ $agency }}" @selected(request('agency', request('type')) === $agency)>{{ $agency }}</option>
        @endforeach
      </select>
    </div>
    <div class="filter-col filter-col-select">
      <label class="form-label" for="filter-status">Status</label>
      <select id="filter-status" name="status" class="form-select">
        <option value="">All statuses</option>
        @foreach(['Pending','In Preparation','Awaiting Client Documents','Ready for Filing','Filed','Completed','Overdue'] as $status)
          <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
        @endforeach
      </select>
    </div>
    <div class="filter-col filter-col-select">
      <label class="form-label" for="filter-client">Client</label>
      <select id="filter-client" name="client_id" class="form-select">
        <option value="">All clients</option>
        @foreach($clients as $client)
          <option value="{{ $client->id }}" @selected(request('client_id') == $client->id)>{{ $client->business_name }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="compliance-filter-row compliance-filter-row-actions">
    <div class="filter-more-toggle">
      <details class="workspace-tools compliance-filter-details" @if(request()->hasAny(['reporting_period','assigned_to','deadline','follow_up','from','to','per_page'])) open @endif>
        <summary class="filter-toggle-btn">
          <span class="toggle-text"><i class="bi bi-sliders me-1" aria-hidden="true"></i> More filters</span>
          <i class="bi bi-chevron-down toggle-caret" aria-hidden="true"></i>
        </summary>
        <div class="compliance-filter-advanced">
          <div class="compliance-advanced-grid">
            <div class="filter-col">
              <label class="form-label" for="filter-period">Reporting period</label>
              <select id="filter-period" name="reporting_period" class="form-select">
                <option value="">All periods</option>
                @foreach($periods as $period)
                  <option value="{{ $period }}" @selected(request('reporting_period') === $period)>{{ $period }}</option>
                @endforeach
              </select>
            </div>
            @if($users->isNotEmpty())
            <div class="filter-col">
              <label class="form-label" for="filter-staff">Staff</label>
              <select id="filter-staff" name="assigned_to" class="form-select">
                <option value="">All staff</option>
                @foreach($users as $user)
                  <option value="{{ $user->id }}" @selected(request('assigned_to') == $user->id)>{{ $user->name }}</option>
                @endforeach
              </select>
            </div>
            @endif
            <div class="filter-col">
              <label class="form-label" for="filter-deadline">Deadline</label>
              <select id="filter-deadline" name="deadline" class="form-select">
                <option value="all">Any deadline</option>
                <option value="submission_overdue" @selected(request('deadline') === 'submission_overdue')>Submission overdue</option>
                <option value="submission_approaching" @selected(request('deadline') === 'submission_approaching')>Submission approaching</option>
                <option value="filing_overdue" @selected(request('deadline') === 'filing_overdue')>Filing overdue</option>
                <option value="filing_approaching" @selected(request('deadline') === 'filing_approaching')>Filing approaching</option>
              </select>
            </div>
            <div class="filter-col">
              <label class="form-label" for="filter-followup">Follow-ups</label>
              <select id="filter-followup" name="follow_up" class="form-select">
                <option value="">All records</option>
                <option value="open" @selected(request('follow_up') === 'open')>Open or in progress</option>
              </select>
            </div>
            <div class="filter-col">
              <label class="form-label" for="filter-from">Filing from</label>
              <input type="date" id="filter-from" name="from" class="form-control" value="{{ request('from') }}">
            </div>
            <div class="filter-col">
              <label class="form-label" for="filter-to">Filing to</label>
              <input type="date" id="filter-to" name="to" class="form-control" value="{{ request('to') }}">
            </div>
            <div class="filter-col">
              <x-per-page/>
            </div>
          </div>
        </div>
      </details>
    </div>
    <div class="filter-action-buttons">
      <a class="btn btn-outline-secondary" href="{{ route('compliance.index', request()->only('view')) }}">Clear</a>
      <button type="submit" class="btn btn-primary">Apply Filters</button>
    </div>
  </div>
</form>

<div class="panel table-panel compliance-table-panel">
  <div class="table-responsive" tabindex="0" role="region" aria-label="Compliance requirements and deadlines">
    <table class="table table-hover workspace-table compliance-table">
      <caption class="visually-hidden">Compliance monitoring</caption>
      <thead>
        <tr>
          <th scope="col" class="col-requirement">Requirement</th>
          <th scope="col" class="col-client">Client</th>
          <th scope="col" class="col-submission-due">Client submission due</th>
          <th scope="col" class="col-filing-due">Official filing due</th>
          <th scope="col" class="col-status-urgency">Status and urgency</th>
          <th scope="col" class="col-action">Next action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($records as $record)
        <tr>
          <td class="col-requirement">
            <a class="compliance-title-link" href="{{ route('compliance.show', $record) }}">{{ $record->requirement }}</a>
            <div class="compliance-subtext">{{ $record->agency }} · {{ $record->reporting_period ?? 'No reporting period' }}</div>
          </td>
          <td class="col-client">
            <a class="compliance-client-link" href="{{ route('clients.show', $record->client) }}">{{ $record->client->business_name }}</a>
          </td>
          <td class="col-submission-due">
            <span class="compliance-date">{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</span>
          </td>
          <td class="col-filing-due">
            <span class="compliance-date fw-semibold">{{ $record->due_date->format('M j, Y') }}</span>
          </td>
          <td class="col-status-urgency">
            <div class="compliance-status-urgency">
              <div class="workflow-status-wrap">
                <x-badge :status="$record->status" :compliance="true"/>
              </div>
              <div class="compliance-deadlines-wrap">
                <x-compliance-warnings :record="$record"/>
              </div>
            </div>
          </td>
          <td class="col-action">
            @if(\App\Services\DeadlineCalculator::isTerminal($record))
              <a class="compliance-action-link" href="{{ route('compliance.show', $record) }}">
                <span>View history</span>
                <span class="visually-hidden"> for {{ $record->requirement }}</span>
                <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
              </a>
            @else
              <a class="compliance-action-link" href="{{ route('compliance.show', $record) }}#follow-ups">
                <span>{{ $record->status === 'Ready for Filing' ? 'Review filing details' : 'View / Update' }}</span>
                <span class="visually-hidden"> for {{ $record->requirement }}</span>
                <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
              </a>
            @endif
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="6">
            <div class="empty-state">
              <div class="empty-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div>
              <h2>Nothing to monitor</h2>
              <p>No compliance requirements match these filters.</p>
              <a class="btn btn-outline-secondary" href="{{ route('compliance.index', request()->only('view')) }}">Clear filters</a>
            </div>
          </td>
        </tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
<x-pagination :records="$records"/>
@endsection
