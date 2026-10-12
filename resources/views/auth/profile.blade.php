@extends('layouts.app')
@section('title','Your profile')
@section('content')
<section class="panel panel-pad mb-4"><h2 class="section-title mb-4">Profile details</h2>
<form method="post" action="{{ route('profile.update') }}">@csrf @method('PATCH')
<x-field name="name" label="Full name" :value="auth()->user()->name" :required="true"/>
<x-field name="email" label="Email address" type="email" :value="auth()->user()->email" :required="true"/>
<div class="mb-3"><label class="form-label" for="profile_current_password">Current password to confirm details</label><input class="form-control" id="profile_current_password" type="password" name="current_password" autocomplete="current-password" required></div>
<button class="btn btn-primary">Save profile</button></form></section>
<section class="panel panel-pad"><h2 class="section-title mb-4">Security: Change Password</h2>
<form method="post" action="{{ route('profile.password') }}">@csrf @method('PATCH')
<x-field name="current_password" label="Current Password" type="password" :required="true"/>
<x-field name="password" label="New Password (at least 12 characters)" type="password" :required="true"/>
<x-field name="password_confirmation" label="Confirm New Password" type="password" :required="true"/>
<p>Changing your password signs out your other sessions.</p><button class="btn btn-primary">Change Password</button></form></section>
@endsection
