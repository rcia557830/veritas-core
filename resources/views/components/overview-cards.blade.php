@props(['cards'])
<div class="overview-cards">@foreach($cards as $card)
<a class="panel overview-card" href="{{ $card['url'] }}"><span class="stat-label">{{ $card['label'] }}</span><strong class="stat-value">{{ $card['value'] }}</strong><span class="stat-note">{{ $card['note'] }}</span><span class="card-action">View details <span aria-hidden="true">&rarr;</span></span></a>
@endforeach</div>
