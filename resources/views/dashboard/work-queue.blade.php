<div class="dashboard-main"><section class="panel table-panel"><div class="panel-heading"><div><h2 class="section-title">{{ $heading }}</h2><p class="section-description">Oldest pending work first</p></div><a class="queue-link" href="{{ route($module.'.index') }}">View all <span aria-hidden="true">&rarr;</span></a></div>
<div class="table-responsive" tabindex="0" role="region" aria-label="{{ $heading }}"><table class="table"><thead><tr><th>{{ $module==='ledger'?'Journal':'Document' }}</th><th>Client</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($items as $item)
@can('view',$item)<tr><td><a href="{{ route($module.'.show',$item) }}">{{ $module==='ledger'?$item->reference_number:$item->title }}</a></td><td>{{ $item->client->business_name }}</td><td><x-badge :status="$item->status"/></td><td>
@if($module==='documents') @can('validate',$item)<a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.validation',$item) }}">Validate document</a>@endcan
@else @if(auth()->user()->can('update',$item) && auth()->user()->can('viewAny',\App\Models\Account::class))<a class="btn btn-sm btn-outline-secondary" href="{{ route('ledger.edit',$item) }}">Edit</a>@else<span class="subtext">View details</span>@endif @endif
</td></tr>@endcan
@empty<tr><td colspan="4"><div class="empty-state"><h3>{{ $empty }}</h3><p>New work will appear here when it is available.</p></div></td></tr>@endforelse
</tbody></table></div></section></div>
