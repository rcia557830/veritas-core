<h2 class="section-title mb-4">{{ $record->exists?'Edit':'New' }} {{ $config['singular'] }}</h2>
<form action="{{ $record->exists?route($module.'.update',$record):route($module.'.store') }}" method="post" enctype="multipart/form-data">@csrf @if($record->exists)@method('PUT')@endif
<p class="form-text">Fields marked * are required.</p><div class="row">
@if(!in_array($module,['clients','knowledge']) && !($module==='compliance' && auth()->user()->hasRole('bookkeeper')))<div class="col-md-6"><x-field name="client_id" label="Client" type="select" :required="true" :value="$record->client_id??request('client_id')" :options="[''=>'Choose a client']+$clients->pluck('business_name','id')->all()"/></div>@endif
@foreach($config['fields'] as $name=>$field)

@php($value=$record->$name)
@php($value=$value instanceof \DateTimeInterface?$value->format('Y-m-d'):(is_array($value)?implode(', ',$value):($value??($field[1]==='date' && ($field[2]??false)?today()->toDateString():($field[1]==='number'?'0':'')))))
<div class="{{ $field[1]==='textarea'?'col-12':'col-md-6' }}"><x-field :name="$name" :label="$field[0]" :type="is_array($field[1])?'select':$field[1]" :options="is_array($field[1])?array_combine($field[1],$field[1]):[]" :required="$field[2]??false" :value="$value"/></div>
@endforeach
@if(($module==='clients' && auth()->user()->hasPermission('client.assign')) || ($module==='compliance' && auth()->user()->hasPermission('compliance.assign')))<div class="col-md-6"><x-field name="assigned_to" label="Assigned employee" type="select" :value="$record->assigned_to" :options="[''=>'Unassigned']+$users->pluck('name','id')->all()"/></div>@endif
@if($module==='documents' && auth()->user()->hasPermission('document.upload'))<div class="col-12"><x-field name="file" label="Attachment" type="file"/><p class="form-text">PDF, Word, Excel, JPG or PNG. Maximum 20 MB. Downloads require authorized access. @if($record->file_path)Current file: {{ $record->original_file_name }}. Uploading replaces it.@endif</p></div>@endif
</div>
@if(in_array($module,['ledger','billing']))@include('records.lines')@endif
<div class="actions mt-4"><a href="{{ $record->exists?route($module.'.show',$record):route($module.'.index') }}" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save {{ $config['singular'] }}</button></div></form>
