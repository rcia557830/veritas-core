@extends('layouts.guest')
@section('title','404 — Page not found')
@section('content')<div class="eyebrow">ERROR 404</div><h2 class="modal-title">Page not found</h2><p class="subtext">This record may have been archived, or the address may be incorrect.</p><a class="btn btn-primary" href="{{ url('/dashboard') }}">Return to workspace</a>@endsection
