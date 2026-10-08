@extends('layouts.app')
@section('title','Notice')
@section('content')
<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Back to notices</a><div class="actions">
@can('update',$notice)<a class="btn btn-primary" href="{{ route('notices.edit',$notice) }}">Edit notice</a>@endcan
@can('publish',$notice)@if($notice->status==='Draft')<form method="post" action="{{ route('notices.publish',$notice) }}" data-confirm="Publish this notice in the internal workspace?">@csrf<button class="btn btn-primary">Publish notice</button></form>@endif
@endcan
@can('delete',$notice)@if($notice->status!=='Archived')<form method="post" action="{{ route('notices.archive',$notice) }}" data-confirm="Archive this notice?">@csrf<button class="btn btn-outline-danger">Archive</button></form>@endif
@endcan
</div></div>
<section class="panel panel-pad"><div class="toolbar"><h2 class="section-title">{{ $notice->title }}</h2><x-badge :status="$notice->status"/></div><p class="subtext">{{ $notice->client?->business_name??'General notice' }} · Created by {{ $notice->creator?->name }}@if($notice->published_at) · Published {{ $notice->published_at->format('M j, Y g:i A') }}@endif</p><div class="prose">{{ $notice->body }}</div></section>
@endsection
