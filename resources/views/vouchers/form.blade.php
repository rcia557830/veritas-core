@extends('layouts.app')
@section('title','Accounting Vouchers')
@section('content')
@unless($voucher->exists)<form method="get" class="filters">@if(request("client_id"))<input type="hidden" name="client_id" value="{{ request("client_id") }}">@endif<label>Voucher type<select class="form-select" name="type">@foreach(\App\Models\Voucher::TYPES as $code=>$label)<option value="{{ $code }}" @selected($voucher->type===$code)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-outline-secondary">Choose type</button><p class="form-text">Choose the type before filling in the voucher.</p></form>@endunless
<section class="panel panel-pad">@include('ledger.form-content')</section>
@endsection
