@extends('layouts.app')
@section('title',$template->exists?'Edit template':'Create template')
@section('content')
<section class="panel panel-pad"><p class="subtext">Used versions can be activated or deactivated. Create a new version to change their definitions, name, or version number.</p>
<form data-accounting-form method="post" action="{{ $template->exists?route('account-templates.update',$template):route('account-templates.store') }}">@csrf @if($template->exists)@method('PUT')@endif
<x-field name="name" label="Template name" :value="$template->name" :required="true"/>
<x-field name="version" label="Version" type="number" :value="$template->version" :required="true"/>
<x-field name="is_active" label="Status" type="select" :value="(int)$template->is_active" :options="[0=>'Inactive',1=>'Active']" :required="true"/>
<div class="actions"><a class="btn btn-outline-secondary" href="{{ route('account-templates.index') }}">Cancel</a><button class="btn btn-primary">Save template</button></div></form></section>
@endsection
