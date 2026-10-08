@extends('layouts.guest')
@section('title','409 — Record changed')
@section('content')<div class="eyebrow">ERROR 409</div><h2 class="modal-title">Record changed</h2><p class="subtext">This action is not available in the record’s current state. Reload and try again.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
