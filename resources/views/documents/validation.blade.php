@can('validate',$record)
<div class="panel panel-pad mt-4" id="validation">
<h3 class="section-title mb-2">Validate document</h3>
@if(in_array($record->status,['Reviewed','Approved']))<p class="form-text">To replace the attachment, first save Under Review or Needs Clarification to reopen review. Verify the replacement after it has been uploaded.</p>@endif
<form method="post" action="{{ route('documents.validate',$record) }}">@csrf
<div class="row"><div class="col-md-6"><label class="form-label" for="validation_status">Validation decision</label><select class="form-select" name="status" id="validation_status" required>
<option value="Under Review" @selected($record->status==='Under Review')>Under Review — review is in progress</option>
<option value="Reviewed" @selected($record->status==='Reviewed')>Reviewed — preliminary check complete</option>
<option value="Needs Clarification" @selected($record->status==='Needs Clarification')>Needs Clarification — client or staff remarks required</option>
@can('approve',$record)<option value="Approved" @selected($record->status==='Approved')>Approved — verified and accepted</option>@endcan
@can('reject',$record)<option value="Rejected" @selected($record->status==='Rejected')>Rejected — incomplete or invalid</option>@endcan
</select></div><div class="col-md-6"><x-field name="notes" label="Validation notes" type="textarea" :value="$record->notes"/></div></div>
<button class="btn btn-primary mt-3">Save validation</button></form>
</div>
@else
<div class="panel panel-pad mt-4" id="validation">
<h3 class="section-title mb-1">Document validation</h3>
<p class="subtext mb-0">Validation is restricted to authorized reviewers (Owner or Office Manager). Bookkeepers cannot validate submitted documents.</p>
</div>
@endcan
