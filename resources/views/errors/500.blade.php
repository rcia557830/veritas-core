@extends('layouts.guest')
@section('title','500 — Something went wrong')
@section('content')<div class="eyebrow">ERROR 500</div><h2 class="modal-title">Something went wrong</h2><p class="subtext">The request could not be completed. Please try again or contact your administrator.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
