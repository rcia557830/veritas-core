@extends('layouts.app')
@section('title',$notice->exists?'Edit notice':'Create notice')
@section('content')
<section class="panel panel-pad"><p class="subtext">Save a draft, review it, then publish it in the internal workspace. Editing a published notice returns it to Draft.</p>
<form method="post" action="{{ $notice->exists?route('notices.update',$notice):route('notices.store') }}">@csrf
@if($notice->exists)@method('PUT')@endif
<x-field name="title" label="Title" :value="$notice->title" :required="true"/>
<x-field name="client_id" label="Related client (optional)" type="select" :value="$notice->client_id" :options="[''=>'General notice']+$clients->pluck('business_name','id')->all()"/>
<x-field name="body" label="Notice content" type="textarea" :value="$notice->body" :required="true"/>
<div class="actions"><a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">Cancel</a><button class="btn btn-primary">Save draft</button></div></form></section>
@endsection
