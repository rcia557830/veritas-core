@extends('layouts.app')
@section('title','Chart of Accounts')
@section('content')
<div class="toolbar"><p class="toolbar-copy">Independent accounts for each client. Select a client to view their chart.</p>
@if($client)<div class="actions">@can('create',[\App\Models\Account::class,$client])<a class="btn btn-primary" href="{{ route('accounts.create',['client_id'=>$client->id]) }}">Create account</a>@endcan
@can('initialize',[\App\Models\Account::class,$client])<a class="btn btn-outline-secondary" href="{{ route('accounts.initialization',['client_id'=>$client->id]) }}">Initialize from template</a>@endcan</div>@endif</div>
<form class="filters" method="get">
<label>Client<select class="form-select" name="client_id" required><option value="">Select a client</option>@foreach($clients as $option)<option value="{{ $option->id }}" @selected($client?->id===$option->id)>{{ $option->business_name }}</option>@endforeach</select></label>
<label>Search code or name<input class="form-control" type="search" name="q" maxlength="150" value="{{ request('q') }}"></label>
<label>Classification<select class="form-select" name="classification"><option value="">All classifications</option>@foreach(\App\Services\Accounting\ChartOfAccounts::CLASSIFICATIONS as $classification)<option @selected(request('classification')===$classification)>{{ $classification }}</option>@endforeach</select></label>
<label>Status<select class="form-select" name="status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select></label>
<button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ route('accounts.index',$client?['client_id'=>$client->id]:[]) }}">Clear filters</a></form>
<section class="panel table-panel">@if($accounts)<div class="table-responsive"><table class="table"><thead><tr><th>Code</th><th>Account name</th><th>Classification</th><th>Status</th></tr></thead><tbody>
@forelse($accounts as $account)<tr><td><a href="{{ route('accounts.show',$account) }}">{{ $account->code }}</a></td><td>{{ $account->name }}</td><td>{{ $account->classification }}</td><td><x-badge :status="$account->is_active?'Active':'Inactive'"/></td></tr>
@empty<tr><td colspan="4"><div class="empty-state"><h2>No accounts found</h2><p>Create an account, initialize a template, or adjust your filters.</p></div></td></tr>@endforelse
</tbody></table></div>@else<div class="empty-state"><h2>Select a client</h2><p>Only clients you are authorized to view are available.</p></div>@endif</section>
@if($accounts)<x-pagination :records="$accounts"/>@endif
@endsection
