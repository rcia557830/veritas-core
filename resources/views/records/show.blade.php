@extends('layouts.app')
@section('title',$config['title'])
@section('content')<div class="toolbar"><a href="{{ route($module.'.index') }}" class="btn btn-outline-secondary">← Back to {{ strtolower($config['title']) }}</a><div class="actions">@can('update',$record)<a data-modal class="btn btn-primary" href="{{ route($module.'.edit',$record) }}">Edit {{ $module === 'knowledge' ? ucfirst($record->content_type ?? 'article') : $config['singular'] }}</a>@endcan
@can('delete',$record)<form method="post" action="{{ route($module.'.destroy',$record) }}" data-confirm="{{ $module==='ledger'?'Delete this draft transaction?':'Archive this record? It will leave the active list.' }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">{{ $module==='ledger'?'Delete draft':'Archive' }}</button></form>@endcan</div></div>
<section class="panel panel-pad">
@if($module === 'knowledge')
    <article class="article-reader">
        <div class="toolbar mb-2">
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="tag"><i class="bi {{ match($record->content_type) { 'procedure' => 'bi-journal-text', 'template' => 'bi-file-earmark-text', 'guideline' => 'bi-compass', default => 'bi-file-text' } }} me-1" aria-hidden="true"></i>{{ ucfirst($record->content_type ?? 'article') }}</span>
                <span class="tag"><i class="bi bi-tag me-1" aria-hidden="true"></i>{{ $record->category }}</span>
            </div>
            <x-badge :status="$record->status"/>
        </div>
        <h2 class="modal-title mb-2">{{ $record->title }}</h2>
        <div class="subtext mb-3">
            <span>Created {{ $record->created_at?->format('F j, Y') }}</span>
            @if($record->updated_at && $record->updated_at->gt($record->created_at))
                <span> · Updated {{ $record->updated_at->format('F j, Y') }}</span>
            @endif
            @if($record->author)
                <span> · Author: <strong>{{ $record->author->name }}</strong></span>
            @endif
        </div>
        @if(!empty($record->tags))
            <div class="actions mb-3">
                @foreach($record->tags as $tag)
                    <span class="tag">#{{ $tag }}</span>
                @endforeach
            </div>
        @endif
        <div class="article-content-prose bg-light p-3 p-md-4 rounded border mt-3 mb-2" style="white-space: pre-wrap; font-size: 15px; line-height: 1.7; color: #1e293b;">{{ $record->content }}</div>
    </article>
@else
    <div class="toolbar"><h2 class="modal-title">{{ \App\Support\Display::value($record, $config['label']) }}</h2><x-badge :status="$record->display_status??$record->status" :compliance="$module==='compliance'"/></div><dl class="detail-grid">@if($record->client_id)<div><dt>Client</dt><dd><a href="{{ route('clients.show',$record->client) }}">{{ $record->client->business_name }}</a></dd></div>@endif
    @foreach($config['fields'] as $key=>$field)<div><dt>{{ $field[0] }}</dt><dd class="{{ $field[1]==='textarea'?'prose':'' }}">{{ \App\Support\Display::value($record,$key) }}</dd></div>@endforeach</dl>
@endif
 @if($module==='compliance')<dl class="detail-grid mt-3"><div><dt>Internal submission deadline</dt><dd>{{ $record->submission_deadline?->format('M j, Y') ?? '—' }}@if($record->submission_deadline_is_provisional) <span class="subtext">(calculated)</span>@endif</dd></div><div><dt>Urgency</dt><dd><x-compliance-warnings :record="$record"/></dd></div>@if($record->submission_deadline_override_reason)<div class="col-12"><dt>Submission deadline override reason</dt><dd>{{ $record->submission_deadline_override_reason }}</dd></div>@endif</dl><div class="actions mt-3"><a class="btn btn-sm btn-outline-secondary" href="{{ route('compliance.checklist',$record->client) }}">Client compliance checklist</a></div>@endif

@if($module==='compliance')<section id="follow-ups" class="mt-4">@include('compliance._followups', ['record'=>$record,'users'=>$users])</section>@endif
@if($module==='documents')
@if($record->file_path)
<div class="panel panel-pad bg-light border mt-3 mb-3">
    <div class="toolbar mb-0">
        <div>
            <strong><i class="bi bi-paperclip me-1" aria-hidden="true"></i> {{ $record->original_file_name }}</strong>
            <div class="subtext">Attached file · Received {{ $record->received_date?->format('M j, Y') ?? '—' }}</div>
        </div>
        <div class="actions">
            @can('download',$record)<a class="btn btn-primary" href="{{ route('documents.download',$record) }}"><i class="bi bi-download" aria-hidden="true"></i>Download {{ $record->original_file_name }}</a>@endcan
        </div>
    </div>
</div>
@else
<p class="subtext mt-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i> No attachment has been added. Edit this record to upload a file.</p>
@endif
@can('viewAny', \App\Models\DocumentRequirement::class)<p class="mt-2"><a href="{{ route('requirements.checklist',$record->client_id) }}">Open client requirements and follow-ups</a></p>@endcan
@include('documents.validation')
@endif
@if($module==='ledger')@include('ledger.detail')@endif
@if($module==='billing')@include('billing.detail')@endif
</section>@endsection
