@extends('layouts.app')
@section('title', $module==='documents' && request('queue')==='validation' ? 'Document Validation' : ($module==='knowledge' ? 'Knowledge Base' : $config['title']))
@section('content')
@if($module==='knowledge')
@php
    $activeType = request('content_type');
    $filterParams = request()->except(['content_type', 'page']);
    $types = [
        '' => ['label' => 'All Content', 'icon' => 'bi-grid'],
        'article' => ['label' => 'Articles', 'icon' => 'bi-file-text'],
        'procedure' => ['label' => 'Procedures', 'icon' => 'bi-journal-text'],
        'template' => ['label' => 'Templates', 'icon' => 'bi-file-earmark-text'],
        'guideline' => ['label' => 'Guidelines', 'icon' => 'bi-compass'],
    ];
@endphp
<nav class="knowledge-nav-tabs mb-4" role="navigation" aria-label="Knowledge Base content types">
    @foreach($types as $typeKey => $meta)
        @php
            $isActive = ($activeType === $typeKey || ($typeKey === '' && empty($activeType)));
            $tabUrl = route('knowledge.index', array_filter(array_merge($filterParams, $typeKey ? ['content_type' => $typeKey] : [])));
        @endphp
        <a class="knowledge-nav-tab {{ $isActive ? 'active' : '' }}" href="{{ $tabUrl }}" @if($isActive) aria-current="page" @endif>
            <i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i>{{ $meta['label'] }}
        </a>
    @endforeach
</nav>
@endif
@if(isset($billingSummary))@include('billing.summary')@endif
@if($module==='documents')
@include('documents.workspace-tools')
@else
@php
    $currentType = request('content_type');
    $addLabel = $module === 'knowledge' ? match($currentType) {
        'procedure' => 'Add Procedure',
        'template' => 'Add Template',
        'guideline' => 'Add Guideline',
        default => 'Add Article',
    } : 'Add '.$config['singular'];
    $createParams = $module === 'knowledge'
        ? ($currentType ? ['content_type' => $currentType] : ['content_type' => 'article'])
        : request()->only('client_id');
@endphp
<div class="toolbar">
    <p class="toolbar-copy">{{ $records->total() }} records · {{ $module === 'knowledge' ? 'Internal knowledge and operational documentation' : ($config['title'] === 'Ledger Review' ? 'Review entries before posting in your general ledger system' : 'Your team’s work, in one place') }}</p>
    @can('create', $config['model'])
        <a data-modal class="btn btn-primary" href="{{ route($module.'.create', $createParams) }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>{{ $addLabel }}
        </a>
    @endcan
</div>
@endif
@include('records.filters')
@if(in_array($module,['compliance','knowledge']))
<div class="row g-3">
@forelse($records as $record)
<div class="col-12 col-md-6 col-xl-4">
<article class="panel {{ $module==='compliance' ? 'deadline-card deadline-tone-'.\App\Services\DeadlineCalculator::tone($record) : 'article-card' }} h-100 d-flex flex-column justify-content-between">
<div>
@if($module==='compliance')
<div class="toolbar mb-2"><span class="tag">{{ $record->agency??$record->category }}</span><x-badge :status="$record->display_status??$record->status" :compliance="true"/></div>
<h2 class="h5 mb-2"><a class="text-button" href="{{ route($module.'.show',$record) }}">{{ $record->{$config['label']} }}</a></h2>
<p class="subtext">{{ $record->client->business_name }}</p>
<p class="deadline-date">Filing due {{ $record->due_date->format('M j, Y') }}</p>
<p class="deadline-date">Submission due {{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</p>
<p class="section-description"><x-compliance-warnings :record="$record"/></p>
@else
<div class="toolbar mb-2">
    <div class="d-flex flex-wrap gap-1 align-items-center">
        <span class="tag"><i class="bi {{ match($record->content_type) { 'procedure' => 'bi-journal-text', 'template' => 'bi-file-earmark-text', 'guideline' => 'bi-compass', default => 'bi-file-text' } }} me-1" aria-hidden="true"></i>{{ ucfirst($record->content_type ?? 'article') }}</span>
        @if($record->category && $record->category !== 'Internal Procedure' && strtolower($record->category) !== strtolower($record->content_type ?? ''))
            <span class="tag">{{ $record->category }}</span>
        @elseif($record->category === 'Internal Procedure' && ($record->content_type ?? 'article') !== 'procedure')
            <span class="tag">{{ $record->category }}</span>
        @endif
    </div>
    <x-badge :status="$record->display_status??$record->status"/>
</div>
<h2 class="h5 mb-2"><a class="text-button" href="{{ route($module.'.show',$record) }}">{{ $record->title }}</a></h2>
<p class="article-preview text-muted mb-2" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">{{ $record->plain_excerpt }}</p>
@if(!empty($record->tags))
<div class="actions mb-2">@foreach($record->tags??[] as $tag)<span class="tag">#{{ $tag }}</span>@endforeach</div>
@endif
<div class="subtext mb-2"><i class="bi bi-clock me-1" aria-hidden="true"></i>{{ $record->updated_at && $record->updated_at->gt($record->created_at) ? 'Updated '.$record->updated_at->format('M j, Y') : 'Created '.$record->created_at?->format('M j, Y') }} · {{ $record->author?->name ?? 'Staff' }}</div>
@endif
</div>
<div class="actions pt-2 border-top mt-auto">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">Read {{ $module === 'knowledge' ? ucfirst($record->content_type ?? 'article') : $config['singular'] }}</a>
    @can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">Edit</a>@endcan
</div>
</article>
</div>
@empty
<div class="col-12">
    <div class="panel empty-state">
        <div class="empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div>
        @if($module === 'knowledge')
            @php
                $hasFilters = request()->hasAny(['q', 'search', 'category', 'type', 'status', 'from', 'to']);
                $activeType = request('content_type');
            @endphp
            @if($hasFilters)
                <h2>No matching records</h2>
                <p>Clear your filters or adjust your search to find what you're looking for.</p>
                <a class="btn btn-primary" href="{{ route('knowledge.index', request()->only('content_type')) }}">Clear filters</a>
            @else
                @php
                    $emptyConfig = match($activeType) {
                        'template' => [
                            'title' => 'No templates yet',
                            'desc' => 'Create a reusable template to organize standard knowledge and reference content.',
                            'btn' => 'Add Template',
                            'params' => ['content_type' => 'template']
                        ],
                        'procedure' => [
                            'title' => 'No procedures yet',
                            'desc' => 'Create a standard operating procedure to document operational steps and protocols.',
                            'btn' => 'Add Procedure',
                            'params' => ['content_type' => 'procedure']
                        ],
                        'guideline' => [
                            'title' => 'No guidelines yet',
                            'desc' => 'Create an internal guideline to define best practices and organizational policies.',
                            'btn' => 'Add Guideline',
                            'params' => ['content_type' => 'guideline']
                        ],
                        'article' => [
                            'title' => 'No articles yet',
                            'desc' => 'Create an informative article to share insights, documentation, and references.',
                            'btn' => 'Add Article',
                            'params' => ['content_type' => 'article']
                        ],
                        default => [
                            'title' => 'No knowledge entries yet',
                            'desc' => 'Start building your team’s internal knowledge base with articles, procedures, templates, and guidelines.',
                            'btn' => 'Add Content',
                            'params' => ['content_type' => 'article']
                        ]
                    };
                @endphp
                <h2>{{ $emptyConfig['title'] }}</h2>
                <p>{{ $emptyConfig['desc'] }}</p>
                @can('create', $config['model'])
                    <a data-modal class="btn btn-primary" href="{{ route('knowledge.create', $emptyConfig['params']) }}">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ $emptyConfig['btn'] }}
                    </a>
                @endcan
            @endif
        @else
            <h2>No matching records</h2>
            <p>Clear your filters or add your first {{ $config['singular'] }}.</p>
            <a class="btn btn-primary" href="{{ route($module.'.index') }}">Clear filters</a>
        @endif
    </div>
</div>
@endforelse
</div>
<x-pagination :records="$records"/>
@elseif($module==='documents') @include('documents.table')
@else @include('records.table') @endif
@endsection
