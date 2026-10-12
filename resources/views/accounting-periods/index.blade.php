@extends('layouts.app')
@section('title','Accounting periods')
@section('content')
<p>Manage each client's fiscal calendar. Closed periods cannot accept postings. Dates and labels must follow the client's approved accounting calendar.</p>
<form class="filters" method="get"><label>Client<select name="client_id" class="form-select" required><option value="">Select a client</option>@foreach($clients as $option)<option value="{{ $option->id }}" @selected($client?->id === $option->id)>{{ $option->business_name }}</option>@endforeach</select></label><button class="btn btn-primary">View periods</button></form>
@if($client)
@foreach($years as $year)
<section class="panel panel-pad mb-3"><h2>{{ $year->label }}</h2><p>{{ $year->starts_on }} through {{ $year->ends_on }}</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Period</th><th>Dates</th><th>Status</th><th>Journals</th><th>Action</th></tr></thead><tbody>
@forelse($year->periods as $period)<tr><td>{{ $period->label }}</td><td>{{ $period->starts_on }} through {{ $period->ends_on }}</td><td>{{ $period->status }}@if($period->closed_at)<br>{{ $period->closed_at }} by {{ $period->closer?->name }}@endif</td><td>{{ $period->entries_count }}</td><td>
@can('create',[\App\Models\Account::class,$client])
@if($period->status === 'Open' && auth()->user()->hasPermission('account.update'))<form method="post" action="{{ route('accounting-periods.close',$period) }}">@csrf<label><input type="checkbox" name="confirmation" value="1" required> Confirm closing; further postings will be blocked.</label><button class="btn btn-outline-danger">Close period</button></form>@endif
@endcan</td></tr>@empty<tr><td colspan="5">No periods in this year.</td></tr>@endforelse
</tbody></table></div></section>
@endforeach
@can('create',[\App\Models\Account::class,$client])
@foreach(['year' => 'Accounting year', 'period' => 'Accounting period'] as $kind => $label)
<section class="panel panel-pad mb-3"><h2>Create {{ strtolower($label) }}</h2><form method="post" action="{{ route('accounting-periods.'.$kind,$client) }}">@csrf
@if($kind === 'period')<label>Accounting year<select class="form-select" name="accounting_year_id" required>@foreach($years as $year)<option value="{{ $year->id }}">{{ $year->label }}</option>@endforeach</select></label>@endif
<label>Label<input class="form-control" name="label" maxlength="100" required value="{{ old('label') }}"></label>
<label>Start date<input class="form-control" type="date" name="starts_on" required value="{{ old('starts_on') }}"></label>
<label>End date<input class="form-control" type="date" name="ends_on" required value="{{ old('ends_on') }}"></label>
<button class="btn btn-primary mt-3">Create {{ strtolower($label) }}</button></form></section>
@endforeach
@endcan
@endif
@endsection
