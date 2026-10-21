@props(['status', 'compliance' => false])
@php($tone = $compliance && $status === 'In Progress' ? 'warning' : \App\Support\Display::tone($status??''))
<span class="badge-status {{ $compliance ? 'compliance-workflow deadline-tone-' : 'tone-' }}{{ $tone }}">{{ $status }}</span>
