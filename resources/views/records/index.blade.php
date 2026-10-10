@extends('layouts.app')
@section('title',$config['title'])
@section('content')
@if(isset($billingSummary))@include('billing.summary')@endif
<div class="toolbar"><p class="toolbar-copy">{{ $records->total() }} records · {{ $config['title'] === 'Ledger Review' ? 'Review entries before posting in your general ledger system' : 'Your team’s work, in one place' }}</p>@can('create',$config['model'])<a data-modal class="btn btn-primary" href="{{ route($module.'.create') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>New {{ $config['singular'] }}</a>@endcan</div>
@include('records.filters')
@if(in_array($module,['compliance','knowledge']))
<div class="row g-3">
@forelse($records as $record)
@php($urgency = $module==='compliance' ? $record->urgency : null)
<div class="col-md-6 col-xl-4">
<article class="panel {{ $module==='compliance'?'deadline-card '.(in_array($urgency,['Filing Overdue','Submission Overdue'])?'urgent':(in_array($urgency,['Filing Deadline Approaching','Submission Deadline Approaching'])?'soon':'')):'article-card' }}">
<div class="toolbar mb-0"><span class="tag">{{ $record->agency??$record->category }}</span><x-badge :status="$record->display_status??$record->status"/></div>
<h2><a class="text-button" href="{{ route($module.'.show',$record) }}">{{ $record->{$config['label']} }}</a></h2>
@if($module==='compliance')
<p class="subtext">{{ $record->client->business_name }}</p>
<p class="deadline-date">Filing due {{ $record->due_date->format('M j, Y') }}</p>
<p class="deadline-date">Submission due {{ $record->submission_deadline?->format('M j, Y') ?? '—' }}</p>
<p class="section-description"><x-badge :status="$record->urgency"/></p>
@else
<p class="article-preview">{{ $record->content }}</p>
<div class="actions">@foreach($record->tags??[] as $tag)<span class="tag">#{{ $tag }}</span>@endforeach</div>
@endif
<div class="actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.show',$record) }}">View details</a>@can('update',$record)<a data-modal class="btn btn-sm btn-outline-secondary" href="{{ route($module.'.edit',$record) }}">{{ $module==='compliance' && auth()->user()->hasPermission('compliance.file')?'Update / mark filed':'Edit' }}</a>@endcan</div>
</article>
</div>
@empty
<div class="col-12"><div class="panel empty-state"><div class="empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div><h2>No matching records</h2><p>Clear your filters or add your first {{ $config['singular'] }}.</p><a class="btn btn-primary" href="{{ route($module.'.index') }}">Clear filters</a></div></div>
@endforelse
</div>
<x-pagination :records="$records"/>
@else @include('records.table') @endif
@endsection
