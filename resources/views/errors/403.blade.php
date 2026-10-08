@extends('layouts.guest')
@section('title','403 — Access restricted')
@section('content')<div class="eyebrow">ERROR 403</div><h2 class="modal-title">Access restricted</h2><p class="subtext">Your account does not have access to this page.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
