@props(['status', 'compliance' => false, 'document' => false])
@php
    $statusStr = (string) ($status ?? '');
    $tone = $compliance && $statusStr === 'In Progress' ? 'warning' : \App\Support\Display::tone($statusStr);
    $slug = \Illuminate\Support\Str::slug($statusStr);
    $isDocStatus = in_array($statusStr, ['Submitted', 'Under Review', 'Reviewed', 'Needs Clarification', 'Approved', 'Rejected'], true);
    $isDocument = $document || ($isDocStatus && request()->routeIs('documents.*', 'requirements.*'));
@endphp
@if($compliance)
<span class="badge-status compliance-workflow compliance-workflow-{{ $slug }} deadline-tone-{{ $tone }}">{{ $status }}</span>
@elseif($isDocument)
<span class="badge-status document-status document-status-{{ $slug }} tone-{{ $tone }}">{{ $status }}</span>
@else
<span class="badge-status tone-{{ $tone }}">{{ $status }}</span>
@endif
