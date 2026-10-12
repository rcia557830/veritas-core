@foreach($navigation as $key => $group)
@if($group['active'] && $key !== 'dashboard' && count($group['links']) > 1)
<nav class="workflow-tabs" aria-label="{{ $group['label'] }} workflows">
    @foreach($group['links'] as $link)
    <a href="{{ $link['url'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
    @endforeach
</nav>
@endif
@endforeach
@if(request()->routeIs('accounting-periods.*', 'account-templates.*'))
<nav class="workflow-tabs" aria-label="Accounting settings">
    @can('viewAny', \App\Models\Account::class)<a href="{{ route('accounting-periods.index') }}" @if(request()->routeIs('accounting-periods.*')) aria-current="page" @endif>Years and periods</a>@endcan
    @can('manage', \App\Models\AccountTemplate::class)<a href="{{ route('account-templates.index') }}" @if(request()->routeIs('account-templates.*')) aria-current="page" @endif>Account Templates</a>@endcan
</nav>
@endif
