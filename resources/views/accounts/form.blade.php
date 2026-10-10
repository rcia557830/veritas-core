@extends('layouts.app')
@section('title',$account->exists?'Edit account':'Create account')
@section('content')
<section class="panel panel-pad"><h2 class="section-title">{{ $client->business_name }}</h2><p class="subtext">Accounts used in posted history retain their code, name, and classification. Deactivate them when needed.</p>
<form data-accounting-form method="post" action="{{ $account->exists?route('accounts.update',$account):route('accounts.store') }}">@csrf
@if($account->exists)@method('PUT')@else<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
@include('accounts.definition',['definition'=>$account])
<div class="actions"><a class="btn btn-outline-secondary" href="{{ route('accounts.index',['client_id'=>$client->id]) }}">Cancel</a><button class="btn btn-primary">Save account</button></div></form></section>
@endsection
