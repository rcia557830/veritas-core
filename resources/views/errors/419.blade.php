@extends('layouts.guest')
@section('title','419 — Session expired')
@section('content')<div class="eyebrow">ERROR 419</div><h2 class="modal-title">Session expired</h2><p class="subtext">Reload the page and sign in again before submitting your changes.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
