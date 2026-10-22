<form method="get" class="filters" aria-label="Filter records">
@if(request()->routeIs('reports.*'))<input type="hidden" name="module" value="{{ $module }}">@endif
@if($module === 'knowledge')
@if(request('content_type'))<input type="hidden" name="content_type" value="{{ request('content_type') }}">@endif
<label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}" placeholder="Search title, category, content, or tags..."></label>
<label>Category<select name="category" class="form-select"><option value="">All categories</option>@foreach($config['fields']['category'][1] as $cat)<option value="{{ $cat }}" @selected(request('category', request('type')) === $cat)>{{ $cat }}</option>@endforeach</select></label>
<label>Sort By<select name="sort" class="form-select"><option value="newest" @selected(request('sort') === 'newest' || !request('sort'))>Newest First</option><option value="updated" @selected(request('sort') === 'updated' || request('sort') === 'updated_at')>Recently Updated</option><option value="oldest" @selected(request('sort') === 'oldest')>Oldest First</option><option value="title_asc" @selected(request('sort') === 'title_asc' || (request('sort') === 'title' && request('direction') === 'asc'))>Title A–Z</option><option value="title_desc" @selected(request('sort') === 'title_desc' || (request('sort') === 'title' && request('direction') === 'desc'))>Title Z–A</option></select></label>
<details class="workspace-tools filter-details" @if(request()->hasAny(['status','from','to','per_page'])) open @endif><summary>More filters</summary><div class="filters mt-2">
<label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach($config['statuses'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label>
<label>From<input type="date" class="form-control" name="from" value="{{ request('from') }}"></label>
<label>To<input type="date" class="form-control" name="to" value="{{ request('to') }}"></label>
<x-per-page/>
</div></details>
<button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-outline-secondary" href="{{ route('knowledge.index', request()->only('content_type')) }}">Clear</a>
@else
@if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
@if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
@if(in_array($module,['documents','billing']))<label>Queue<select name="queue" class="form-select"><option value="">All records</option>@foreach(($module==='documents'?['attention'=>'Needs attention','validation'=>'Awaiting validation']:['outstanding'=>'Outstanding balance']) as $value=>$label)<option value="{{ $value }}" @selected(request('queue')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
@if($module==='ledger')<label>Work queue<select class="form-select" name="work"><option value="">All accessible journals</option><option value="mine" @selected(request('work')==='mine')>My journals</option>@can('bookkeeping.review')<option value="reviewable" @selected(request('work')==='reviewable')>Awaiting independent review</option>@endcan @can('bookkeeping.post')<option value="postable" @selected(request('work')==='postable')>Reviewed awaiting posting</option>@endcan</select></label>@endif
<label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}" placeholder="Name, reference, or details"></label>
<label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach($config['statuses'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
@if(!in_array($module,['clients','knowledge']))<label>Client<select class="form-select" name="client_id"><option value="">All accessible clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>@endif
@if(in_array($module,['documents','ledger']))<details class="workspace-tools filter-details" @if(request()->hasAny(['type','from','to','per_page'])) open @endif><summary>More filters</summary><div class="filters mt-2">@endif
@php($typeKey=['clients'=>'business_type','documents'=>'document_type','compliance'=>'agency'][$module]??null)
@if($typeKey)<label>{{ $config['fields'][$typeKey][0] }}<select class="form-select" name="type"><option value="">All types</option>@foreach($config['fields'][$typeKey][1] as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>@endif
<label>From<input type="date" class="form-control" name="from" value="{{ request('from') }}"></label><label>To<input type="date" class="form-control" name="to" value="{{ request('to') }}"></label><x-per-page/>@if(in_array($module,['documents','ledger']))</div></details>@endif<button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ request()->url() }}{{ request()->routeIs('reports.*')?'?module='.$module:'' }}">Clear</a>
@endif
</form>
