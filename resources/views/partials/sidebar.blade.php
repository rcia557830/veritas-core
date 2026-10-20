<aside class="sidebar" id="sidebar" aria-label="Workspace navigation">
<a class="brand" href="{{ route('dashboard') }}">@if($firm?->logo_path)<img class="brand-mark" style="object-fit:contain" src="{{ route('workspace.logo') }}" alt="Firm logo">@else<span class="brand-mark" aria-hidden="true">V</span>@endif<span><span class="brand-name">VERITAS CORE</span><span class="brand-caption">{{ $firm?->firm_name ?? 'RBCIA Accounting Firm' }}</span></span></a>
<button class="icon-button sidebar-close" id="sidebarClose" aria-label="Close navigation"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
<div class="nav-label">YOUR WORKSPACE</div>
<nav id="mainNav" aria-label="Primary navigation">
@foreach($navigation as $key => $group)
<details class="nav-group" data-nav-group="{{ $key }}" @if($group['active']) open @endif>
<summary><i class="bi bi-{{ $group['icon'] }}" aria-hidden="true"></i><span>{{ $group['label'] }}</span></summary>
<div class="nav-children">@foreach($group['links'] as $link)
<a class="nav-item {{ $link['active'] ? 'active' : '' }}" href="{{ $link['url'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
@endforeach</div></details>
@endforeach
</nav></aside>
