@extends('layouts.app')
@section('title', $module==='documents' && request('queue')==='validation' ? 'Document Validation' : ($module==='knowledge' ? 'Knowledge Base' : $config['title']))
@section('content')
@if($module==='knowledge')
<div class="knowledge-chips d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="Knowledge categories">
    <a class="btn btn-sm {{ !request('type') && !request('q') ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index') }}">All Articles</a>
    <a class="btn btn-sm {{ request('type') === 'Internal Procedure' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['type' => 'Internal Procedure']) }}"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Procedures</a>
    <a class="btn btn-sm {{ request('q') === 'template' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['q' => 'template']) }}"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Templates</a>
    <a class="btn btn-sm {{ request('q') === 'guideline' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['q' => 'guideline']) }}"><i class="bi bi-compass me-1" aria-hidden="true"></i>Guidelines</a>
    <a class="btn btn-sm {{ request('type') === 'Compliance' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['type' => 'Compliance']) }}"><i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Compliance</a>
    <a class="btn btn-sm {{ request('type') === 'Documentation' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['type' => 'Documentation']) }}"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Documentation</a>
    <a class="btn btn-sm {{ request('type') === 'Accounting' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('knowledge.index', ['type' => 'Accounting']) }}"><i class="bi bi-cash-stack me-1" aria-hidden="true"></i>Accounting</a>
</div>
@if(in_array(request('q'),['template','guideline']))<p class="form-text">Showing articles matching <strong>{{ request('q') }}</strong> in their title, content, category or tags. Use search and category filters to refine the results.</p>@endif
@endif
@if(isset($billingSummary))@include('billing.summary')@endif
@if($module==='documents')
@include('documents.workspace-tools')
@else
<div class="toolbar"><p class="toolbar-copy">{{ $records->total() }} records · {{ $config['title'] === 'Ledger Review' ? 'Review entries before posting in your general ledger system' : 'Your team’s work, in one place' }}</p>@can('create',$config['model'])<a data-modal class="btn btn-primary" href="{{ route($module.'.create', request()->only('client_id')) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add {{ $config['singular'] }}</a>@endcan</div>
@endif
@include('records.filters')
@if(in_array($module,['compliance','knowledge']))
<div class="row g-3">
@forelse($records as $record)
<div class="col-md-6 col-xl-4">
<article class="panel {{ $module==='compliance' ? 'deadline-card deadline-tone-'.\App\Services\DeadlineCalculator::tone($record) : 'article-card' }} h-100 d-flex flex-column justify-content-between">
<div>
<div class="toolbar mb-2"><span class="tag">{{ $record->agency??$record->category }}</span><x-badge :status="$record->display_status??$record->status" :compliance="$module==='compliance'"/></div>
<h2 class="h5 mb-2"><a class="text-button" href="{{ route($module.'.show',$record) }}">{{ $record->{$config['label']} }}</a></h2>
@if($module==='compliance')
<p class="subtext">{{ $record->client->business_name }}</p>
<p class="deadline-date">Filing due {{ $record->due_date->format('M j, Y') }}</p>
<p class="deadline-date">Submission due {{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</p>
<p class="section-description"><x-compliance-warnings :record="$record"/></p>
@else
<p class="article-preview text-muted mb-2" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">{{ $record->content }}</p>
<div class="actions mb-2">@foreach($record->tags??[] as $tag)<span class="tag">#{{ $tag }}</span>@endforeach</div>
<div class="subtext mb-2"><i class="bi bi-clock me-1" aria-hidden="true"></i>{{ $record->created_at?->format('M j, Y') }} · {{ $record->author?->name ?? 'Staff' }}</div>
@endif
</div>
<div class="actions pt-2 border-top mt-auto"><a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">Read {{ $config['singular'] }}</a>@can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">Edit</a>@endcan</div>
</article>
</div>
@empty
<div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div><h2>No matching records</h2><p>Clear your filters or add your first {{ $config['singular'] }}.</p><a class="btn btn-primary" href="{{ route($module.'.index') }}">Clear filters</a></div></div>
@endforelse
</div>
<x-pagination :records="$records"/>
@elseif($module==='documents') @include('documents.table')
@else @include('records.table') @endif
@endsection
