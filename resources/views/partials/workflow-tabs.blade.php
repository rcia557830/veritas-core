@foreach($navigation as $key => $group)
@if($group['active'] && $key !== 'dashboard' && $key !== 'knowledge' && count($group['links']) > 1)
<nav class="workflow-tabs" aria-label="{{ $group['label'] }} workflows">
    @foreach($group['links'] as $link)
    @if(!($key === 'accounting' && in_array($link['route'], ['billing.index','financial-audit.index','reports.index'])))
    @php
        $extraParams = [];
        if ($key === 'compliance') {
            $extraParams = array_filter(request()->only(['client_id', 'agency', 'reporting_period', 'q']));
        } elseif (in_array($key, ['documents', 'accounting']) && request('client_id')) {
            $extraParams = ['client_id' => request('client_id')];
        }
    @endphp
    <a href="{{ !empty($extraParams) ? route($link['route'], $link['query'] + $extraParams) : $link['url'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
    @endif
    @endforeach
</nav>
@if($key === 'accounting')
<details class="workspace-tools mb-3" @if(request()->routeIs('billing.*','financial-audit.*','reports.*','admin.audit.*')) open @endif><summary>Billing, exports and audit history</summary><div class="actions mt-2">
@foreach($group['links'] as $link) @if(in_array($link['route'], ['billing.index','financial-audit.index','reports.index']))<a href="{{ request('client_id') ? route($link['route'], $link['query'] + ['client_id'=>request('client_id')]) : $link['url'] }}">{{ $link['label'] }}</a>@endif @endforeach
</div></details>
@endif
@endif
@endforeach
@if(request()->routeIs('accounting-periods.*', 'account-templates.*'))
<nav class="workflow-tabs" aria-label="Accounting settings">
    @can('viewAny', \App\Models\Account::class)<a href="{{ route('accounting-periods.index') }}" @if(request()->routeIs('accounting-periods.*')) aria-current="page" @endif>Years and periods</a>@endcan
    @can('manage', \App\Models\AccountTemplate::class)<a href="{{ route('account-templates.index') }}" @if(request()->routeIs('account-templates.*')) aria-current="page" @endif>Account Templates</a>@endcan
</nav>
@endif
