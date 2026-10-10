@if(!isset($voucher) && $record->voucher)<p><a href="{{ route('vouchers.show',$record->voucher) }}">Open {{ $record->voucher->reference }} · {{ \App\Models\Voucher::TYPES[$record->voucher->type] }}</a></p>@endif
@include('ledger.lines')
<p class="system-alert mt-3">{{ $record->total_debit!=='0.00' && $record->total_debit===$record->total_credit?'This transaction is balanced.':'This transaction is unbalanced and cannot be submitted for review.' }}</p>
<p>Accounting period: {{ $record->accountingPeriod?->label??'Not associated' }}</p>
@if($record->is_legacy)<p class="system-alert">Unmapped legacy entry: original labels and financial content are preserved read-only. Controlled mapping and independent review are required before future posting.</p>@endif
@can('submit',$record)<form method="post" action="{{ route('ledger.transition',$record) }}">@csrf<input type="hidden" name="action" value="submit"><button class="btn btn-primary">Submit for review</button></form>@endcan
@can('review',$record)@if(in_array($record->status,['For Review','Reviewed']))<form method="post" action="{{ route('ledger.transition',$record) }}">@csrf<x-field name="notes" label="Review notes" type="textarea" :value="$record->notes"/><div class="actions">@if($record->status==='For Review' && !$record->is_legacy)@can('approve',$record)<button name="action" value="review" class="btn btn-primary">Approve review</button>@endcan @endif<button name="action" value="return" class="btn btn-outline-danger">Return for correction</button></div><p class="form-text">A different employee must review the transaction. Returning for correction clears its current approval.</p></form>@endif
@endcan
@if($record->reviewed_by)<p class="subtext mt-3">Reviewed by {{ $record->reviewer?->name }} on {{ $record->reviewed_at?->format('M j, Y g:i A') }}.</p>@endif
<h3 class="section-title mt-4">Supporting evidence and history</h3>
@php
$visibleEvidence=$record->evidence()->orderBy('id')->get()->filter(fn($e)=>$e->document && \Illuminate\Support\Facades\Gate::allows('view',$e->document));
@endphp
@forelse($visibleEvidence as $evidence)<div class="border-bottom py-3"><strong>{{ $evidence->document_number }} · {{ $evidence->title }}</strong><p class="subtext">{{ $evidence->detached_at?'Historical association':'Current association' }} · attached {{ $evidence->created_at->format('M j, Y H:i') }}@if($evidence->file_path !== $evidence->document->file_path) · Original attachment retained; the source document has a replacement.@endif</p>
@if($evidence->file_path)@can('download',$evidence->document)<a class="btn btn-sm btn-outline-secondary" href="{{ route('ledger.evidence',[$record,$evidence]) }}">Download retained {{ $evidence->original_file_name }}</a>@endcan @else<p class="subtext">Document metadata only; no attachment was present when associated.</p>@endif</div>@empty<p class="subtext">No supporting documents are associated or accessible. Evidence is optional.</p>@endforelse

@if($record->status === 'Posted')
<p class="system-alert mt-3">Posted transaction - read-only. Corrections require a separately authorized adjusting or reversing entry.</p>
<p>Posted by {{ $record->poster?->name }} on {{ $record->posted_at?->format('M j, Y g:i A') }}.</p>
@elseif($record->status === 'Reviewed')
@can('post', $record)
@php
    $postingErrors = [];
    try { \App\Services\Accounting\JournalPosting::validate($record); }
    catch (\Illuminate\Validation\ValidationException $error) { $postingErrors = \Illuminate\Support\Arr::flatten($error->errors()); }
    catch (\Illuminate\Auth\Access\AuthorizationException $error) { $postingErrors = ['Access to supporting evidence is required before posting.']; }
@endphp
@if($postingErrors)
@foreach($postingErrors as $error)<p class="system-alert">{{ $error }}</p>@endforeach
@else
<form method="post" action="{{ route('ledger.post', $record) }}" data-confirm="Post this reviewed journal? Its financial details and evidence will become permanently read-only.">
@csrf<button class="btn btn-primary">Post journal</button>
<p class="form-text">Independent review is complete. Posting rechecks the period, accounts, exact balance and retained evidence.</p>
</form>
@endif
@else<p class="system-alert">Posting requires an authorized Owner or Office Manager who did not create this entry.</p>@endcan
@else<p class="form-text">Only independently Reviewed journals can be posted.</p>@endif
<h3 class="section-title mt-4">Journal audit history</h3>
@foreach(\App\Services\Audit::visible()->where('module', 'ledger')->where('record_id', $record->id)->orderBy('id')->get() as $event)
<p class="subtext journal-audit-event">{{ $event->created_at?->format('M j, Y H:i') }} | {{ $event->action }} | {{ $event->description }}</p>
@endforeach
