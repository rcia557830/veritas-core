@extends('layouts.app')
@section('title',$config['title'])
@section('content')<div class="toolbar"><a href="{{ route($module.'.index') }}" class="btn btn-outline-secondary">← Back to {{ strtolower($config['title']) }}</a><div class="actions">@can('update',$record)<a data-modal class="btn btn-primary" href="{{ route($module.'.edit',$record) }}">Edit {{ $config['singular'] }}</a>@endcan
@can('delete',$record)<form method="post" action="{{ route($module.'.destroy',$record) }}" data-confirm="{{ $module==='ledger'?'Delete this draft transaction?':'Archive this record? It will leave the active list.' }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">{{ $module==='ledger'?'Delete draft':'Archive' }}</button></form>@endcan</div></div>
<section class="panel panel-pad"><div class="toolbar"><h2 class="modal-title">{{ $record->{$config['label']} }}</h2><x-badge :status="$record->display_status??$record->status"/></div><dl class="detail-grid">@if($record->client_id)<div><dt>Client</dt><dd><a href="{{ route('clients.show',$record->client) }}">{{ $record->client->business_name }}</a></dd></div>@endif
@foreach($config['fields'] as $key=>$field)<div><dt>{{ $field[0] }}</dt><dd class="{{ $field[1]==='textarea'?'prose':'' }}">{{ \App\Support\Display::value($record,$key) }}</dd></div>@endforeach</dl>
 @if($module==='compliance')<dl class="detail-grid mt-3"><div><dt>Internal submission deadline</dt><dd>{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}@if($record->submission_deadline_is_provisional) <span class="subtext">(calculated)</span>@endif</dd></div><div><dt>Urgency</dt><dd><x-badge :status="$record->urgency"/></dd></div>@if($record->submission_deadline_override_reason)<div class="col-12"><dt>Submission deadline override reason</dt><dd>{{ $record->submission_deadline_override_reason }}</dd></div>@endif</dl><div class="actions mt-3"><a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.checklist',$record->client) }}">Client compliance checklist</a></div>@endif

@if($module==='documents')@include('documents.validation')@if($record->file_path)@can('download',$record)<a class="btn btn-primary" href="{{ route('documents.download',$record) }}"><i class="bi bi-download" aria-hidden="true"></i>Download {{ $record->original_file_name }}</a>@endcan
@else<p class="subtext">No attachment has been added. Edit this record to upload a file.</p>@endif @endif
@if($module==='ledger')@include('ledger.detail')@endif
@if($module==='billing')@include('billing.detail')@endif
</section>@endsection
