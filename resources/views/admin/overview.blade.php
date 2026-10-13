@extends('layouts.app')
@section('title','Super Admin overview')
@section('content')
<div data-dashboard-role="super-admin"><header class="overview-heading"><div><h2>Technical administration</h2><p>Manage employee invitations, access and system settings.</p></div></header>
@if($cards)<x-overview-cards :cards="$cards"/>@endif
@if(auth()->user()->hasAnyPermission(['security.view','workspace.manage']))<section class="panel panel-pad mt-4"><h2 class="section-title">Administration tasks</h2><p>Review employee access and use the activity logs to investigate account changes.</p><div class="actions">
@can('security.view')<a class="btn btn-outline-secondary" href="{{ route('admin.security.index') }}">Security Logs</a><a class="btn btn-outline-secondary" href="{{ route('admin.activity.index') }}">System Activity Logs</a>@endcan
@can('workspace.manage')<a class="btn btn-outline-secondary" href="{{ route('workspace.edit') }}">System Settings</a>@endcan
</div></section>@endif</div>
@endsection
