@extends('layouts.app')
@section('title','Security Logs')
@section('content')
<div class="panel table-responsive"><table class="table"><thead><tr><th>Time</th><th>Action</th><th>Actor ID</th><th>Employee ID</th><th>IP address</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->actor_id ?? 'System / Guest' }}</td><td>{{ $event->subject_id ?? '—' }}</td><td>{{ $event->ip_address }}</td></tr>
@empty<tr><td colspan="5">No security events yet.</td></tr>@endforelse
</tbody></table></div><x-pagination :records="$events"/>
@endsection
