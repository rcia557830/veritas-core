<h2 class="section-title mb-4">{{ $record->exists?'Edit':'New' }} {{ $config['singular'] }}</h2>
<form action="{{ $record->exists?route($module.'.update',$record):route($module.'.store') }}" method="post" enctype="multipart/form-data">@csrf @if($record->exists)@method('PUT')@endif
<p class="form-text">Fields marked * are required.</p><div class="row">
@if(!in_array($module,['clients','knowledge']) && !($module==='compliance' && auth()->user()->hasRole('bookkeeper')))<div class="col-md-6"><x-field name="client_id" label="Client" type="select" :required="true" :value="$record->client_id??request('client_id')" :options="[''=>'Choose a client']+$clients->pluck('business_name','id')->all()"/></div>@endif
@foreach($config['fields'] as $name=>$field)

@php($value=$record->$name)
@php($value=$value instanceof \DateTimeInterface?$value->format('Y-m-d'):(is_array($value)?implode(', ',$value):($value??($field[1]==='date' && ($field[2]??false)?today()->toDateString():($field[1]==='number'?'0':'')))))
<div class="{{ $field[1]==='textarea'?'col-12':'col-md-6' }}"><x-field :name="$name" :label="$field[0]" :type="is_array($field[1])?'select':$field[1]" :options="is_array($field[1])?array_combine($field[1],$field[1]):[]" :required="$field[2]??false" :value="$value"/></div>
@endforeach
@if($module==='compliance' && !auth()->user()->hasRole('bookkeeper'))@php($submissionValue = ($record->exists && !$record->submission_deadline_is_provisional) ? ($record->getRawOriginal('submission_deadline') ?? '') : '')<div class="col-md-6"><x-field name="submission_deadline" label="Internal submission deadline (optional)" type="date" :value="$submissionValue"/><p class="form-text">Leave blank to calculate automatically as the filing deadline minus {{ config('compliance.submission_lead_days', 10) }} {{ config('compliance.submission_lead_basis','calendar')==='calendar'?'calendar':'working' }} days.</p></div><div class="col-12"><x-field name="submission_deadline_override_reason" label="Override reason" type="textarea" :value="$record->submission_deadline_override_reason"/><p class="form-text">Required only when you enter a submission deadline that differs from the automatic calculation.</p></div>@endif

@if(($module==='clients' && auth()->user()->hasPermission('client.assign')) || ($module==='compliance' && auth()->user()->hasPermission('compliance.assign')))<div class="col-md-6"><x-field name="assigned_to" label="Assigned employee" type="select" :value="$record->assigned_to" :options="[''=>'Unassigned']+$users->pluck('name','id')->all()"/></div>@endif
@if($module==='documents' && auth()->user()->hasPermission('document.upload'))<div class="col-12">
@if(in_array($record->status,['Reviewed','Approved']))
<p class="form-text">The attachment is locked. Use Validate document on the document page to reopen review before uploading a replacement.</p>
@else
<x-field name="file" label="Attachment" type="file"/><p class="form-text">PDF, Word, Excel, JPG or PNG. Maximum 20 MB. Downloads require authorized access. @if($record->file_path)Current file: {{ $record->original_file_name }}. The original is retained privately when replaced. Save a replacement before verifying it in a separate action.@endif</p>
@endif
</div>@endif
</div>
@if(in_array($module,['ledger','billing']))@include('records.lines')@endif
<div class="actions mt-4"><a href="{{ $record->exists?route($module.'.show',$record):route($module.'.index') }}" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save {{ $config['singular'] }}</button></div></form>
