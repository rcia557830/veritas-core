<form method="get" class="filters" aria-label="Filter records">
@if(request()->routeIs('reports.*'))<input type="hidden" name="module" value="{{ $module }}">@endif
@if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
@if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
<label class="search-filter">Search<input type="search" name="q" class="form-control" value="{{ \App\Services\Records::searchText(request()) }}" placeholder="Name, reference, or details"></label>
<label>Status<select name="status" class="form-select"><option value="">All statuses</option>@foreach($config['statuses'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
@if(!in_array($module,['clients','knowledge']))<label>Client<select class="form-select" name="client_id"><option value="">All assigned clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->business_name }}</option>@endforeach</select></label>@endif
@php($typeKey=['clients'=>'business_type','documents'=>'document_type','compliance'=>'agency','knowledge'=>'category'][$module]??null)
@if($typeKey)<label>{{ $config['fields'][$typeKey][0] }}<select class="form-select" name="type"><option value="">All types</option>@foreach($config['fields'][$typeKey][1] as $type)<option @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label>@endif
<label>From<input type="date" class="form-control" name="from" value="{{ request('from') }}"></label><label>To<input type="date" class="form-control" name="to" value="{{ request('to') }}"></label><x-per-page/><button class="btn btn-primary">Apply</button><a class="btn btn-outline-secondary" href="{{ request()->url() }}{{ request()->routeIs('reports.*')?'?module='.$module:'' }}">Clear</a></form>
