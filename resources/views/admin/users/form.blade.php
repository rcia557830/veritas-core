@extends('layouts.app')
@section('title',$user->exists?'Edit employee details':'Invite employee')
@section('content')
<section class="panel panel-pad">
<p>{{ $user->exists ? 'Employees manage their own email and password in their profile.' : 'An invitation will be emailed to the employee. They will choose their own password.' }}</p>
<form method="post" action="{{ $user->exists?route('admin.users.update',$user):route('admin.users.store') }}">
@csrf @if($user->exists)@method('PUT')@endif
<div class="row">
<div class="col-md-6"><x-field name="name" label="Employee name" :value="$user->name" :required="true"/></div>
<div class="col-md-6">@if($user->exists)<label class="form-label">Email address</label><p>{{ $user->email }}</p><input type="hidden" name="email" value="{{ $user->email }}">@else<x-field name="email" label="Email address" type="email" :required="true"/>@endif</div>
<div class="col-md-6">@can('changeRole',$user)<x-field name="role_id" label="Role" type="select" :value="$user->role_id" :options="$roles->pluck('name','id')->all()" :required="true"/>@else<p>Role: {{ $user->role?->name }}</p><input type="hidden" name="role_id" value="{{ $user->role_id }}">@endcan</div>
@if($user->exists)<div class="col-md-6">
@if($user->id === auth()->id())<p>Status: {{ $user->status }}</p><input type="hidden" name="status" value="{{ $user->status }}">
@elseif($user->status === 'Inactive' && !$user->invitation_accepted_at && \App\Models\EmployeeInvitation::where('user_id',$user->id)->exists())<p>Inactive invitation. Use Resend Invitation on the employee list to invite this employee again.</p><input type="hidden" name="status" value="Inactive">
@else<x-field name="status" label="Account status" type="select" :value="$user->status" :options="$user->status === 'Pending Invitation' ? ['Pending Invitation'=>'Pending Invitation','Inactive'=>'Inactive'] : ['Active'=>'Active','Inactive'=>'Inactive']" :required="true"/>@endif
</div>@endif
</div><div class="actions"><a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancel</a><button class="btn btn-primary">{{ $user->exists?'Save details':'Send invitation' }}</button></div>
</form></section>
@endsection
