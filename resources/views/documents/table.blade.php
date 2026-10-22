<div class="panel table-panel"><div class="table-responsive"><table class="table table-hover">
<caption class="visually-hidden">Client documents and next actions</caption>
<thead><tr>
@foreach(['title'=>'Document','client'=>'Client','requirement'=>'Requirement','received_date'=>'Date received','status'=>'Validation status'] as $column=>$label)
<th @if(request('sort')===$column) aria-sort="{{ request('direction')==='asc'?'ascending':'descending' }}" @endif>
@if(in_array($column,['title','received_date','status']))<a class="sort-button" href="{{ request()->fullUrlWithQuery(['sort'=>$column,'direction'=>request('sort')===$column && request('direction')==='asc'?'desc':'asc','page'=>1]) }}">{{ $label }} <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a>@else{{ $label }}@endif
</th>@endforeach<th>Next required action</th></tr></thead>
<tbody>@forelse($records as $record)
<tr>
    <td><a href="{{ route('documents.show',$record) }}">{{ $record->title }}</a><div class="subtext">{{ $record->document_number }} · {{ $record->document_type }}</div></td>
    <td>{{ $record->client->business_name }}</td>
    <td>@forelse($record->requirements as $requirement)
        @can('view', $requirement)<a class="d-block" href="{{ route('requirements.checklist',$record->client_id) }}#requirement-{{ $requirement->id }}">{{ $requirement->name }}</a>@endcan
        @empty<span class="subtext">Not linked to a requirement</span>@endforelse</td>
    <td>{{ $record->received_date?->format('M j, Y') }}</td>
    <td><x-badge :status="$record->status" :document="true"/></td>
    <td>
        @if(in_array($record->status, ['Submitted','Under Review']))
            @can('validate',$record)<a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.show',$record) }}#validation">Validate document</a>@else<span class="subtext">Awaiting validation</span>@endcan
        @elseif(in_array($record->status, ['Needs Clarification','Rejected']))
            <a href="{{ route('documents.show',$record) }}">Review feedback</a>
            @can('viewAny', \App\Models\DocumentRequirement::class)<a class="d-block" href="{{ route('requirements.checklist',$record->client_id) }}">Follow up with client</a>@endcan
        @else<a href="{{ route('documents.show',$record) }}">View validated document</a>@endif
    </td>
</tr>
@empty<tr><td colspan="6"><div class="empty-state"><h2>No matching documents</h2><p>Select another client or view the missing requirements to see what is needed.</p></div></td></tr>@endforelse
</tbody></table></div></div><x-pagination :records="$records"/>
