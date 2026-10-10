@extends('layouts.app')
@section('title','Document Requirements')
@section('content')
<div class="toolbar">
  <p class="toolbar-copy">{{ $records->total() }} requirements · Expected documents for each client, independent of uploads</p>
  <div class="actions">
    <a class="btn btn-outline-secondary" href="{{ route('requirements.monitoring') }}"><i class="bi bi-clipboard-check" aria-hidden="true"></i>Missing documents</a>
    <a class="btn btn-outline-secondary" href="{{ route('requirements.templates.index') }}">Templates</a>
    @can('create', App\Models\DocumentRequirement::class)<a class="btn btn-primary" href="{{ route('requirements.create') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>New requirement</a>@endcan
  </div>
</div>

<form method="get" class="filters" aria-label="Filter requirements">
  <label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Requirement or client"></label>
  <label>Client<select name="client_id" class="form-select"><option value="">All assigned clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>
  <label>Type<select name="type" class="form-select"><option value="">All types</option>@foreach($types as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>
  <label>Period<select name="accounting_period_id" class="form-select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('accounting_period_id')==$period->id)>{{ $period->client->business_name }} — {{ $period->label }}</option>@endforeach</select></label>
  <label>Scope<select name="scope" class="form-select"><option value="all">All</option><option value="required" @selected(request('scope')==='required')>Required</option><option value="optional" @selected(request('scope')==='optional')>Optional</option><option value="active" @selected(request('scope')==='active')>Active</option><option value="inactive" @selected(request('scope')==='inactive')>Inactive</option></select></label>
  <button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('requirements.index') }}">Clear</a>
</form>

<div class="panel table-panel">
  <div class="table-responsive"><table class="table table-hover"><caption class="visually-hidden">Document requirements</caption><thead><tr><th>Client</th><th>Requirement</th><th>Type</th><th>Period</th><th>Due</th><th>Classification</th><th>Status</th><th>State</th><th>Actions</th></tr></thead><tbody>
  @forelse($records as $requirement)
    <tr>
      <td><a href="{{ route('clients.show',$requirement->client) }}">{{ $requirement->client->business_name }}</a></td>
      <td><a class="text-button" href="{{ route('requirements.checklist',$requirement->client_id) }}">{{ $requirement->name }}</a>@if($requirement->remarks)<div class="subtext">{{ \Illuminate\Support\Str::limit($requirement->remarks,60) }}</div>@endif</td>
      <td>{{ $requirement->type }}</td>
      <td>{{ $requirement->accountingPeriod?->label ?? '—' }}</td>
      <td>{{ $requirement->due_date?->format('M j, Y') ?? '—' }}</td>
      <td><x-badge :status="$requirement->is_required ? 'Required' : 'Optional'"/></td>
      <td><x-badge :status="$requirement->is_active ? 'Active' : 'Inactive'"/></td>
      <td><x-badge :status="\App\Services\DocumentCompleteness::label(\App\Services\DocumentCompleteness::stateOf($requirement))"/></td>
      <td><div class="actions">
        @can('update',$requirement)<a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.edit',$requirement) }}">Edit</a>@endcan
        @can('activate',$requirement)<form method="post" action="{{ route('requirements.toggle',$requirement) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $requirement->is_active ? 'Deactivate' : 'Activate' }}</button></form>@endcan
        @can('delete',$requirement)<form method="post" action="{{ route('requirements.destroy',$requirement) }}" data-confirm="Archive this requirement? Linked documents remain in the client file.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Archive</button></form>@endcan
      </div></td>
    </tr>
  @empty
    <tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div><h2>No requirements configured</h2><p>Define expected documents for a client to start tracking completeness.</p>@can('create',App\Models\DocumentRequirement::class)<a class="btn btn-primary" href="{{ route('requirements.create') }}">New requirement</a>@endcan</div></td></tr>
  @endforelse
  </tbody></table></div>
</div>
<x-pagination :records="$records"/>
@endsection
