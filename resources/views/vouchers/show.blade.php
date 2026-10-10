@extends('layouts.app')
@section('title','Accounting Voucher')
@section('content')
<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">All vouchers</a><div class="actions">@can('update',$record)<a class="btn btn-primary" href="{{ route('vouchers.edit',$voucher) }}">Edit voucher</a>@endcan @can('report.print')<a class="btn btn-outline-secondary" href="{{ route('vouchers.print',$voucher) }}" target="_blank" rel="noopener">Print voucher</a>@endcan</div></div>
<section class="panel panel-pad">@include('vouchers.metadata')<h3>{{ $record->description }}</h3>@if($record->notes)<p>{{ $record->notes }}</p>@endif @include('ledger.detail')</section>
@endsection
