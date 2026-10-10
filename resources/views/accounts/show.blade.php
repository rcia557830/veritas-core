@extends('layouts.app')
@section('title','Account details')
@section('content')
<div class="toolbar"><a class="btn btn-outline-secondary" href="{{ route('accounts.index',['client_id'=>$account->client_id]) }}">Back to client chart</a><div class="actions">
@can('update',$account)<a class="btn btn-primary" href="{{ route('accounts.edit',$account) }}">Edit account</a>@endcan
@can('status',$account)<form data-accounting-form method="post" action="{{ route('accounts.status',$account) }}" data-confirm="{{ $account->is_active?'Deactivate':'Activate' }} this account? Historical transactions will be preserved.">@csrf<input type="hidden" name="is_active" value="{{ $account->is_active?0:1 }}"><button class="btn btn-outline-secondary">{{ $account->is_active?'Deactivate':'Activate' }} account</button></form>@endcan</div></div>
<section class="panel panel-pad"><h2 class="section-title">{{ $account->code }} · {{ $account->name }}</h2><p>{{ $account->client->business_name }}</p><p>{{ $account->classification }} · <x-badge :status="$account->is_active?'Active':'Inactive'"/></p>
@if($account->templateItem)<p class="subtext">Copied from {{ $account->templateItem->template->name }} v{{ $account->templateItem->template->version }}. This client account is maintained independently.</p>@endif</section>
<section class="panel panel-pad mt-3"><h2 class="section-title">Account history</h2><p class="subtext">Recorded account changes. Earlier activity may not have account-specific audit records.</p>
@forelse($history as $event)<div class="border-bottom py-3"><strong>{{ $event->action }}</strong><span class="subtext"> · {{ $event->created_at->format('M j, Y H:i') }}</span><p class="mb-0">{{ $event->description }}</p></div>@empty<div class="empty-state"><p>No account changes recorded yet.</p></div>@endforelse
</section><x-pagination :records="$history"/>
@endsection
