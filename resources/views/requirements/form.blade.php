@extends('layouts.app')
@section('title',($requirement->exists?'Edit ':'New ').'requirement')
@section('content')
<section class="panel panel-pad">
  <h2 class="section-title mb-4">{{ $requirement->exists?'Edit':'New' }} document requirement</h2>
  <form action="{{ $requirement->exists?route('requirements.update',$requirement):route('requirements.store') }}" method="post">@csrf @if($requirement->exists)@method('PUT')@endif
    <p class="form-text">Fields marked * are required. Requirements exist independently of any uploaded document.</p>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_client_id">Client <span class="required-mark">*</span></label>
        <select id="field_client_id" name="client_id" class="form-select @error('client_id')is-invalid @enderror" required>
          <option value="">Choose a client</option>
          @foreach($clients as $client)<option value="{{ $client->id }}" @selected((string)old('client_id',$requirement->client_id??$preselectedClient)===(string)$client->id)>{{ $client->business_name }}</option>@endforeach
        </select>
        @error('client_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_template_id">From template</label>
        <select id="field_template_id" name="template_id" class="form-select">
          <option value="">No template</option>
          @foreach($templates as $template)
            <option value="{{ $template->id }}" @selected((string)old('template_id',$requirement->template_id)===(string)$template->id)
              data-name="{{ $template->name }}" data-type="{{ $template->type }}" data-description="{{ $template->description }}" data-due-days="{{ $template->default_due_days }}">{{ $template->name }}</option>
          @endforeach
        </select>
        <div class="form-text">Choosing a template pre-fills the fields below.</div>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_name">Requirement <span class="required-mark">*</span></label>
        <input id="field_name" name="name" type="text" class="form-control @error('name')is-invalid @enderror" value="{{ old('name',$requirement->name) }}" required>
        @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_type">Document type <span class="required-mark">*</span></label>
        <select id="field_type" name="type" class="form-select @error('type')is-invalid @enderror" required>
          <option value="">Choose a type</option>
          @foreach($types as $type)<option value="{{ $type }}" @selected(old('type',$requirement->type)===$type)>{{ $type }}</option>@endforeach
        </select>
        @error('type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_accounting_period_id">Accounting period</label>
        <select id="field_accounting_period_id" name="accounting_period_id" class="form-select @error('accounting_period_id')is-invalid @enderror">
          <option value="">No specific period</option>
          @foreach($periods as $id => $label)<option value="{{ $id }}" @selected((string)old('accounting_period_id',$requirement->accounting_period_id)===(string)$id)>{{ $label }}</option>@endforeach
        </select>
        @error('accounting_period_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_scope">Scope</label>
        <select id="field_scope" name="scope" class="form-select @error('scope')is-invalid @enderror">
          <option value="onboarding" @selected(old('scope',$requirement->scope ?? 'onboarding')==='onboarding')>Onboarding (registration / activation documents)</option>
          <option value="periodic" @selected(old('scope',$requirement->scope)==='periodic')>Periodic (accounting-period documents)</option>
        </select>
        <div class="form-text">Onboarding documents count toward client readiness; periodic documents are excluded.</div>
        @error('scope')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label" for="field_due_date">Due date</label>
        <input id="field_due_date" name="due_date" type="date" class="form-control @error('due_date')is-invalid @enderror" value="{{ old('due_date',$requirement->due_date?->format('Y-m-d')) }}">
        @error('due_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
      </div>
      <div class="col-12 mb-3">
        <label class="form-label" for="field_description">Description</label>
        <textarea id="field_description" name="description" class="form-control" rows="3">{{ old('description',$requirement->description) }}</textarea>
      </div>
      <div class="col-12 mb-3">
        <label class="form-label" for="field_remarks">Staff remarks</label>
      <div class="col-md-6 mb-3">
        <div class="form-check form-switch">
          <input type="hidden" name="is_required" value="0">
          <input class="form-check-input" type="checkbox" id="field_is_required" name="is_required" value="1" @checked(old('is_required',$requirement->exists ? $requirement->is_required : true))>
          <label class="form-check-label" for="field_is_required">Required (counts toward completeness)</label>
        </div>
      </div>
      <div class="col-md-6 mb-3">
        <div class="form-check form-switch">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" id="field_is_active" name="is_active" value="1" @checked(old('is_active',$requirement->exists ? $requirement->is_active : true))>
          <label class="form-check-label" for="field_is_active">Active</label>
        </div>
      </div>
    </div>
    <div class="actions mt-4">
      <a href="{{ $requirement->exists?route('requirements.checklist',$requirement->client_id):route('requirements.index') }}" class="btn btn-outline-secondary">Cancel</a>
      <button class="btn btn-primary" type="submit">Save requirement</button>
    </div>
  </form>
</section>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const template = document.getElementById('field_template_id');
    template.addEventListener('change', function () {
      const option = this.options[this.selectedIndex];
      if (!option || !option.dataset.name) return;
      document.getElementById('field_name').value = option.dataset.name;
      document.getElementById('field_type').value = option.dataset.type;
      document.getElementById('field_description').value = option.dataset.description || '';
      const days = parseInt(option.dataset.dueDays, 10);
      if (!isNaN(days)) {
        const due = new Date();
        due.setDate(due.getDate() + days);
        document.getElementById('field_due_date').value = due.toISOString().slice(0, 10);
      }
    });
  });
</script>
@endsection

        <textarea id="field_remarks" name="remarks" class="form-control" rows="2">{{ old('remarks',$requirement->remarks) }}</textarea>
      </div>
