@extends('layouts.guest')
@section('title','Accept invitation')
@section('content')
<h2 class="modal-title">Create your password</h2>
<p>Enter the email address that received this invitation.</p>
<form method="post" action="{{ route('invitations.accept', $invitation) }}">
@csrf<input type="hidden" name="token" value="{{ $token }}">
<x-field name="email" label="Email address" type="email" :required="true"/>
<x-field name="password" label="Password (at least 12 characters)" type="password" :required="true"/>
<x-field name="password_confirmation" label="Confirm password" type="password" :required="true"/>
<button class="btn btn-primary">Accept invitation</button>
</form>
@endsection
