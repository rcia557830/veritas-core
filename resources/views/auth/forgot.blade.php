@extends('layouts.guest')
@section('title','Forgot password')
@section('content')<h2 class="modal-title">Reset your password</h2><p class="subtext">Enter your account email to request a secure reset link.</p><form method="post" action="{{ route('password.email') }}">@csrf<x-field name="email" label="Email address" type="email" :required="true"/><button class="btn btn-primary">Send reset link</button><a class="btn btn-outline-secondary" href="{{ route('login') }}">Back to sign in</a></form>@endsection
