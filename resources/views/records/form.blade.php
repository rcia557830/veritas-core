@extends('layouts.app')
@section('title',($record->exists?'Edit ':'New ').$config['singular'])
@section('content')<section class="panel panel-pad">@include('records.form-content')</section>@endsection
