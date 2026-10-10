@extends('layouts.app')
@section('title','Requirement Templates')
@section('content')
<div class="toolbar">
  <p class="toolbar-copy">{{ $templates->count() }} templates · Reusable expected-document definitions</p>
  <div class="actions"><a class="btn btn-outline-secondary" href="{{ route('requirements.index') }}">Back to requirements</a></div>
</div>

@can('create',App\Models\DocumentRequirementTemplate::class)
<section class="panel panel-pad mb-4">
  <h2 class="section-title">New template</h2>
  <form action="{{ route('requirements.templates.store') }}" method="post">@csrf
    <div class="row">
      <div class="col-md-4 mb-3"><label class="form-label" for="new_name">Name *</label><input id="new_name" name="name" class="form-control" required></div>
      <div class="col-md-4 mb-3"><label class="form-label" for="new_type">Document type *</label><select id="new_type" name="type" class="form-select" required><option value="">Choose a type</option>@foreach($types as $type)<option>{{ $type }}</option>@endforeach</select></div>
      <div class="col-md-2 mb-3"><label class="form-label" for="new_days">Default due days</label><input id="new_days" name="default_due_days" type="number" min="0" max="3650" class="form-control"></div>
      <div class="col-md-2 mb-3"><label class="form-label">&nbsp;</label><div class="form-check form-switch"><input type="hidden" name="is_required" value="0"><input class="form-check-input" type="checkbox" id="new_required" name="is_required" value="1" checked><label class="form-check-label" for="new_required">Required</label></div></div>
      <div class="col-12 mb-3"><label class="form-label" for="new_desc">Description</label><textarea id="new_desc" name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <button class="btn btn-primary">Save template</button>
  </form>
</section>
@endcan

<div class="row g-3">
@forelse($templates as $template)
  <div class="col-md-6 col-xl-4">
    <article class="panel panel-pad">
      <div class="toolbar mb-2"><h2 class="modal-title">{{ $template->name }}</h2><x-badge :status="$template->is_required ? 'Required' : 'Optional'"/></div>
      <p class="subtext">{{ $template->type }}@if($template->default_due_days !== null) · due in {{ $template->default_due_days }} days @endif</p>
      @if($template->description)<p class="section-description">{{ $template->description }}</p>@endif
      @can('update',$template)
      <form action="{{ route('requirements.templates.update',$template) }}" method="post" class="mt-2">@csrf @method('PUT')
        <div class="mb-2"><input name="name" class="form-control form-control-sm" value="{{ $template->name }}"></div>
        <div class="mb-2"><select name="type" class="form-select form-select-sm">@foreach($types as $type)<option @selected($template->type===$type)>{{ $type }}</option>@endforeach</select></div>
        <div class="mb-2"><input name="default_due_days" type="number" min="0" max="3650" class="form-control form-control-sm" placeholder="Default due days" value="{{ $template->default_due_days }}"></div>
        <button class="btn btn-sm btn-primary">Save</button>
      </form>
      @endcan
      @can('apply',$template)
      <form action="{{ route('requirements.templates.apply',$template) }}" method="post" class="mt-2">@csrf
        <label class="form-label">Initialize for client</label>
        <div class="d-flex gap-2">
          <select name="client_id" class="form-select form-select-sm"><option value="">Choose a client</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->business_name }}</option>@endforeach</select>
          <button class="btn btn-sm btn-outline-secondary text-nowrap">Apply</button>
        </div>
      </form>
      @endcan
      @can('delete',$template)<form method="post" action="{{ route('requirements.templates.destroy',$template) }}" data-confirm="Archive this template? Existing requirements are unaffected." class="mt-2">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Archive</button></form>@endcan
    </article>
  </div>
@empty
  <div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-journals" aria-hidden="true"></i></div><h2>No templates yet</h2><p>Create a reusable expected-document definition above.</p></div></div>
@endforelse
</div>
@endsection
