@php($followUps = $record->followUps)
<h4 class="section-title mt-3">Follow-ups</h4>
@forelse($followUps as $followUp)
  <div class="follow-up-item mb-2">
    <div class="toolbar mb-1"><x-badge :status="$followUp->status"/><span class="subtext">{{ $followUp->assignee?->name ?? 'Unassigned' }} · {{ $followUp->follow_up_date?->format('M j, Y') ?? 'No date' }} · by {{ $followUp->creator?->name ?? '—' }}</span>
      <div class="actions">@can('delete',$followUp)<form method="post" action="{{ route('compliance.follow-ups.destroy',$followUp) }}" data-confirm="Remove this follow-up?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>@endcan</div>
    </div>
    @if($followUp->remarks)<div class="subtext">{{ $followUp->remarks }}</div>@endif
  </div>
@empty
  <p class="subtext">No follow-ups recorded.</p>
@endforelse
@can('create', [App\Models\ComplianceFollowUp::class, $record])
<form method="post" action="{{ route('compliance.follow-ups.store',$record) }}" class="mt-2">@csrf
  <div class="row g-2">
    <div class="col-md-3"><select name="status" class="form-select"><option>Open</option><option>In Progress</option><option>Resolved</option></select></div>
    <div class="col-md-3"><select name="assigned_to" class="form-select"><option value="">Unassigned</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><input type="date" name="follow_up_date" class="form-control" value="{{ today()->toDateString() }}"></div>
    <div class="col-md-3"><input type="text" name="remarks" class="form-control" placeholder="Remarks"></div>
  </div>
  <button class="btn btn-sm btn-primary mt-2">Add follow-up</button>
</form>
@endcan
