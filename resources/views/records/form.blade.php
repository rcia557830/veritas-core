@extends('layouts.app')
@section('title',($record->exists?'Edit ':'Add ').$config['singular'])
@section('content')<section class="panel panel-pad">@include('records.form-content')</section>@endsection
