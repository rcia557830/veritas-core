@extends('layouts.app')
@section('title','User Management')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Manage employee access</p>@can('create',\App\Models\User::class)<a class="btn btn-primary" href="{{ route('admin.users.create') }}">Invite employee</a>@endcan</div>
<form method="get" class="filters"><label>Search<input class="form-control" name="q" value="{{ \App\Services\Records::searchText(request()) }}"></label><label>Status<select class="form-select" name="status"><option value="">All</option>@foreach(['Pending Invitation','Active','Inactive'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><x-per-page/><button class="btn btn-primary">Apply</button></form>
<div class="panel table-panel table-responsive"><table class="table"><thead><tr><th>Employee Name</th><th>Email</th><th>Role</th><th>Account Status</th><th>Date Added</th><th>Actions</th></tr></thead><tbody>
@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role?->name }}</td><td><x-badge :status="$user->status"/></td><td>{{ $user->created_at?->format('M j, Y') }}</td><td>
@can('update',$user)<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.edit',$user) }}">Edit Details</a>@endcan
@if($user->status==='Pending Invitation' || ($user->status==='Inactive' && !$user->invitation_accepted_at && \App\Models\EmployeeInvitation::where('user_id',$user->id)->exists()))
@can('update',$user)<form class="d-inline" method="post" action="{{ route('admin.users.resend',$user) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Resend Invitation</button></form>@endcan
@else
@can('resetPassword',$user)<form class="d-inline" method="post" action="{{ route('admin.users.reset',$user) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Send Password Reset Link</button></form>@endcan
@endif
@if($user->status==='Active')@can('revokeSessions',$user)<form class="d-inline" method="post" action="{{ route('admin.users.revoke',$user) }}" data-confirm="Sign out this employee from all sessions?">@csrf<button class="btn btn-sm btn-outline-secondary">Revoke Sessions</button></form>@endcan
@endif
</td></tr>@empty<tr><td colspan="6">No matching employees.</td></tr>@endforelse
</tbody></table></div><x-pagination :records="$users"/>
@endsection
