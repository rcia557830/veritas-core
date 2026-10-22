@props(['record'])
<span class="compliance-warnings">
@foreach(\App\Services\DeadlineCalculator::warnings($record) as $action => $warning)
@php
    $actionLabel = ucfirst($action);
    $fullLabel = $warning['label'];
    $shortLabel = preg_replace('/^(Submission|Filing)\s+/', '', $fullLabel);
@endphp
<span class="compliance-warning" title="{{ $fullLabel }}"><span class="compliance-warning-label">{{ $actionLabel }}</span><span class="badge-status deadline-badge deadline-tone-{{ $warning['tone'] }}" aria-label="{{ $fullLabel }}">{{ $shortLabel }}</span></span>
@endforeach
</span>
