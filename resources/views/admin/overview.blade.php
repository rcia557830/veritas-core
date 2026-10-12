@extends('layouts.app')
@section('title','Super Admin overview')
@section('content')
<section class="panel panel-pad"><h2>Technical administration</h2><p>Manage employee invitations, account access and workspace configuration.</p>
<div class="actions">@can('viewAny', \App\Models\User::class)<a class="btn btn-primary" href="{{ route('admin.users.index') }}">User Management</a>@endcan
@can('security.view')<a class="btn btn-outline-secondary" href="{{ route('admin.security.index') }}">Security Logs</a>@endcan</div></section>
@endsection
