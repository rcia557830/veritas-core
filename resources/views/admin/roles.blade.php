@extends('layouts.app')
@section('title','Roles and Permissions')
@section('content')
<p>Review the permissions assigned to each role. Change an employee's role through User Management.</p>
<div class="row g-3">@foreach($roles as $role)<div class="col-lg-6"><section class="panel panel-pad"><h2 class="section-title">{{ $role->name }}</h2><p>{{ $role->permissions->count() }} permissions</p><details><summary>View permissions</summary><ul class="permission-list">@foreach($role->permissions as $permission)<li>{{ $permission->name }}</li>@endforeach</ul></details></section></div>@endforeach</div>
@endsection
