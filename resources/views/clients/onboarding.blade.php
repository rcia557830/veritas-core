@extends('layouts.app')
@section('title','Client Onboarding')
@section('content')
<div class="toolbar">
  <p class="toolbar-copy">{{ $summary['total'] }} accessible clients · onboarding readiness across the workspace</p>
  <div class="actions"><a class="btn btn-outline-secondary" href="{{ route('clients.index') }}">All clients</a></div>
</div>

<div class="row g-3 mb-4">
  <x-stat label="Pending onboarding" :value="$summary['pending']" icon="hourglass-split" :tone="$summary['pending']?'warning':'success'"/>
  <x-stat label="Awaiting verification" :value="$summary['awaiting_verification']" icon="file-earmark-check" tone="warning"/>
  <x-stat label="Ready for activation" :value="$summary['ready']" icon="check2-circle" :tone="$summary['ready']?'success':'info'"/>
  <x-stat label="Onboarded" :value="$summary['onboarded']" icon="check2-all" tone="success"/>
  <x-stat label="Not configured" :value="$summary['not_configured']" icon="gear" tone="info"/>
  <x-stat label="Missing documents" :value="$summary['clients_missing']" icon="clipboard-x" :tone="$summary['clients_missing']?'danger':'success'"/>
</div>

<form method="get" class="filters" aria-label="Filter onboarding">
  <label>Readiness<select name="state" class="form-select">
    <option value="">All states</option>
    <option value="pending" @selected(request('state')==='pending')>Pending onboarding</option>
    <option value="not_started" @selected(request('state')==='not_started')>Not started</option>
    <option value="in_progress" @selected(request('state')==='in_progress')>In progress</option>
    <option value="awaiting_verification" @selected(request('state')==='awaiting_verification')>Awaiting verification</option>
    <option value="needs_clarification" @selected(request('state')==='needs_clarification')>Needs clarification</option>
    <option value="ready_for_activation" @selected(request('state')==='ready_for_activation')>Ready for activation</option>
    <option value="onboarded" @selected(request('state')==='onboarded')>Onboarded</option>
    <option value="not_configured" @selected(request('state')==='not_configured')>Not configured</option>
    <option value="missing" @selected(request('state')==='missing')>Missing documents</option>
  </select></label>
  <button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('clients.onboarding') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">Client onboarding</caption>
    <thead><tr><th>Client</th><th>Assigned staff</th><th>Readiness</th><th>Progress</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse($records as $row)
      <tr>
        <td><a class="text-button" href="{{ route('clients.onboarding.checklist',$row->client) }}">{{ $row->client->business_name }}</a><div class="subtext">{{ $row->client->client_code }}</div></td>
        <td>{{ $row->client->assignee?->name ?? 'Unassigned' }}</td>
        <td><x-badge :status="\App\Services\OnboardingReadiness::label($row->state)"/></td>
        <td class="w-25">
          @if($row->percentage !== null)
            <progress class="metric-progress" aria-label="Onboarding progress" value="{{ $row->percentage }}" max="100"></progress>
            <span class="subtext">{{ $row->verified }} of {{ $row->total }} verified</span>
          @else
            <span class="subtext">No required onboarding documents configured</span>
          @endif
        </td>
        <td><div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('clients.onboarding.checklist',$row->client) }}">Open checklist</a></div></td>
      </tr>
    @empty
      <tr><td colspan="5"><div class="empty-state"><div class="empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div><h2>No clients match</h2><p>Adjust the readiness filter to see more clients.</p><a class="btn btn-outline-secondary" href="{{ route('clients.onboarding') }}">Clear filters</a></div></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>
<x-pagination :records="$records"/>
@endsection
