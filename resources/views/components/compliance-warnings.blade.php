@props(['record'])
<span class="compliance-warnings">
@foreach(\App\Services\DeadlineCalculator::warnings($record) as $action => $warning)
<span class="compliance-warning"><span class="compliance-warning-label">{{ ucfirst($action) }}</span><span class="badge-status deadline-tone-{{ $warning['tone'] }}">{{ $warning['label'] }}</span></span>
@endforeach
</span>
