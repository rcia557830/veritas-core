@can('validate',$record)
<h3 class="section-title mt-4">Validate document</h3>
@if(in_array($record->status,['Reviewed','Approved']))<p class="form-text">To replace the attachment, first save Under Review or Needs Clarification to reopen review. Verify the replacement after it has been uploaded.</p>@endif
<form method="post" action="{{ route('documents.validate',$record) }}">@csrf
<div class="row"><div class="col-md-6"><label class="form-label" for="validation_status">Validation decision</label><select class="form-select" name="status" id="validation_status" required>
<option>Under Review</option><option>Reviewed</option><option>Needs Clarification</option>
@can('approve',$record)<option>Approved</option>@endcan
@can('reject',$record)<option>Rejected</option>@endcan
</select></div><div class="col-md-6"><x-field name="notes" label="Validation notes" type="textarea" :value="$record->notes"/></div></div>
<button class="btn btn-primary">Save validation</button></form>
@endcan
