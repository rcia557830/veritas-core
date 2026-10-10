@extends('layouts.app')
@section('title',$item->exists?'Edit template item':'Add template item')
@section('content')
<section class="panel panel-pad"><h2 class="section-title">{{ $template->name }} · v{{ $template->version }}</h2><form data-accounting-form method="post" action="{{ $item->exists?route('account-templates.items.update',[$template,$item]):route('account-templates.items.store',$template) }}">@csrf @if($item->exists)@method('PUT')@endif
@include('accounts.definition',['definition'=>$item])
<x-field name="is_active" label="Status" type="select" :value="(int)$item->is_active" :options="[0=>'Inactive',1=>'Active']" :required="true"/>
<div class="actions"><a class="btn btn-outline-secondary" href="{{ route('account-templates.show',$template) }}">Cancel</a><button class="btn btn-primary">Save item</button></div></form></section>
@endsection
