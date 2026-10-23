<h2 class="section-title mb-4">{{ $record->exists?'Edit':'New' }} {{ isset($voucher) ? \App\Models\Voucher::TYPES[$voucher->type] : 'journal entry' }}</h2>
<form data-journal-form data-accounting-form @if(isset($voucher)) data-voucher-form @endif data-options-url="{{ route('ledger.options') }}" method="post" action="{{ isset($voucher) ? ($voucher->exists?route('vouchers.update',$voucher):route('vouchers.store')) : ($record->exists?route('ledger.update',$record):route('ledger.store')) }}">@csrf @if($record->exists)@method('PUT')@endif
@if(isset($voucher))@include('vouchers.fields')<p class="form-text">Voucher drafts require balanced journal lines and an open period. Select the correct transaction accounts explicitly; no counterpart account is assumed.</p>@else<p class="form-text">Save a draft while you work. Each line needs an account and one positive debit or credit. Balance the totals and choose an open period before submitting for independent review.</p>@endif
<div class="row g-4 align-items-start">
<div class="col-lg-8">
<div class="row"><div class="col-md-6">
@if($record->exists)<label class="form-label">Client</label><p>{{ $record->client->business_name }}</p><input type="hidden" name="client_id" value="{{ $record->client_id }}">
@else<x-field name="client_id" label="Client" type="select" :required="true" :value="request('client_id')" :options="[''=>'Choose a client']+$clients->pluck('business_name','id')->all()"/>@endif</div>
<div class="col-md-6"><x-field name="transaction_date" label="Transaction date" type="date" :required="true" :value="$record->transaction_date?->toDateString()??today()->toDateString()"/></div>
<div class="col-12">@include('ledger.setup-checklist')</div>
<div class="col-md-6">
@php
    $hasClient = !empty($options['setup']);
    $hasPeriods = !empty($options['periods']);
    $hasAccounts = !empty($options['accounts']);
    $periodPrompt = $hasClient && !$hasPeriods
        ? 'No open periods for this client'
        : (isset($voucher) ? 'Select an open period (required to save)' : 'Accounting period required before submission');
    $accountPrompt = $hasClient && !$hasAccounts
        ? 'No active accounts for this client'
        : 'Choose an active account';
@endphp
<label class="form-label" for="journal_period">Accounting period</label>
<select class="form-select @if($hasPeriods || !$hasClient) mb-3 @endif" id="journal_period" name="accounting_period_id" @required(isset($voucher))>
<option value="">{{ $periodPrompt }}</option>
@foreach($options['periods'] as $period)<option value="{{ $period['id'] }}" @selected((string)old('accounting_period_id',$record->accounting_period_id)===(string)$period['id'])>{{ $period['label'] }} · {{ $period['starts_on'] }} – {{ $period['ends_on'] }}</option>@endforeach
</select>
<div data-period-empty-help class="form-text mb-3" @if($hasPeriods || !$hasClient) hidden @endif>
<span class="text-warning-emphasis">No open periods for this client.</span>
@if(!empty($options['setup']['periods_url']))
<a href="{{ $options['setup']['periods_url'] }}" data-setup-period-link class="ms-1 text-decoration-underline">Set up periods</a>
@else
<span class="subtext ms-1" data-setup-period-restricted>Ask an Owner or Office Manager to set up periods.</span>
@endif
</div>
@error('accounting_period_id')<p class="text-danger">{{ $message }}</p>@enderror</div>
@unless(isset($voucher))<div class="col-md-6"><x-field name="reference_number" label="Reference number" :value="$record->reference_number"/></div>@endunless
<div class="col-12"><x-field name="description" label="Description" :value="\App\Support\DemoLabels::display($record->description)" :required="true"/><details class="workspace-tools mb-3" @if(old('notes',$record->notes) || $errors->has('notes')) open @endif><summary>Notes (optional)</summary><div class="mt-2"><x-field name="notes" label="Notes" type="textarea" :value="$record->notes"/></div></details></div></div>
<p data-journal-options-status class="system-alert" aria-live="polite">{{ count($options['accounts'])?'Only active accounts and open periods for this client are available.':'Select a client with active accounts. An authorized employee must configure its Chart of Accounts and accounting periods before submission.' }}</p>
@php($lines=old('items',$record->exists?$record->items->toArray():[['account_id'=>'','debit'=>'0','credit'=>'0'],['account_id'=>'','debit'=>'0','credit'=>'0']]))
<section data-lines="journal"><div class="toolbar"><h3 class="section-title">Debit and credit lines</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line>Add line</button></div>
<div data-account-empty-help class="form-text mb-2" @if($hasAccounts || !$hasClient) hidden @endif>
<span class="text-warning-emphasis">No active accounts for this client.</span>
@if(!empty($options['setup']['accounts_url']))
<a href="{{ $options['setup']['accounts_url'] }}" data-setup-account-link class="ms-1 text-decoration-underline">Set up accounts</a>
@else
<span class="subtext ms-1" data-setup-account-restricted>Ask an Owner or Office Manager to set up accounts.</span>
@endif
</div>
<div class="table-responsive"><table class="table"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Remove</th></tr></thead><tbody data-line-body>
@foreach($lines as $index=>$line)<tr><td><input type="hidden" name="items[{{ $index }}][id]" value="{{ $line['id']??'' }}"><label class="visually-hidden" for="item_{{ $index }}_account_id">Account line {{ $index+1 }}</label><select class="form-select" style="min-width:220px" id="item_{{ $index }}_account_id" name="items[{{ $index }}][account_id]" required><option value="">{{ $accountPrompt }}</option>@foreach($options['accounts'] as $account)<option value="{{ $account['id'] }}" @selected((string)($line['account_id']??'')===(string)$account['id'])>{{ $account['code'] }} · {{ $account['name'] }}</option>@endforeach</select>@error('items.'.$index.'.account_id')<p class="text-danger">{{ $message }}</p>@enderror</td>
@foreach(['debit'=>'Debit','credit'=>'Credit'] as $key=>$label)<td><label class="visually-hidden" for="item_{{ $index }}_{{ $key }}">{{ $label }} line {{ $index+1 }}</label><input class="form-control" style="min-width:120px" type="text" inputmode="decimal" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" id="item_{{ $index }}_{{ $key }}" name="items[{{ $index }}][{{ $key }}]" value="{{ $line[$key]??'0' }}" required>@error('items.'.$index.'.'.$key)<p class="text-danger">{{ $message }}</p>@enderror</td>@endforeach
<td><button type="button" class="icon-button" data-remove-line aria-label="Remove line"><i class="bi bi-trash" aria-hidden="true"></i></button></td></tr>@endforeach</tbody></table></div><div class="journal-totals" aria-label="Journal totals">
<div><span>Total Debit</span><strong data-total-debit>—</strong></div>
<div><span>Total Credit</span><strong data-total-credit>—</strong></div>
<div><span>Difference</span><strong data-total-difference>—</strong></div>
</div><p data-line-total class="system-alert mt-3" role="status" aria-live="polite">Enter amounts to see the balance.</p></section>
<h3 class="section-title mt-4">Supporting documents (optional)</h3><p class="form-text">Selected attachment versions are retained privately. To use a replacement, explicitly refresh that document. Earlier associations remain in history.</p>
@php($selected=array_map('strval',old('document_ids',$evidence->pluck('document_id')->all())))
<div data-journal-documents>
@foreach($options['documents'] as $document)<div class="form-check"><input class="form-check-input" type="checkbox" name="document_ids[]" id="journal_doc_{{ $document['id'] }}" value="{{ $document['id'] }}" @checked(in_array((string)$document['id'],$selected))><label class="form-check-label" for="journal_doc_{{ $document['id'] }}">{{ $document['label'] }}</label></div>@endforeach
@if(!count($options['documents']))<p class="subtext">No accessible documents for this client.</p>@endif
@foreach($evidence as $snapshot)
@if(!collect($options['documents'])->contains('id',$snapshot->document_id))<div class="form-check"><input class="form-check-input" type="checkbox" name="document_ids[]" id="retained_doc_{{ $snapshot->id }}" value="{{ $snapshot->document_id }}" @checked(in_array((string)$snapshot->document_id,$selected))><label class="form-check-label" for="retained_doc_{{ $snapshot->id }}">Retained {{ $snapshot->document_number }} · {{ $snapshot->title }}</label></div>@endif
@if(!$snapshot->document->trashed())<div class="form-check ms-4"><input class="form-check-input" type="checkbox" name="refresh_document_ids[]" id="refresh_doc_{{ $snapshot->id }}" value="{{ $snapshot->document_id }}"><label class="form-check-label" for="refresh_doc_{{ $snapshot->id }}">Use current attachment version of {{ $snapshot->document_number }}</label></div>@endif
@endforeach</div>
<div data-save-status-banner class="system-alert mt-4 mb-2" role="status" hidden>
<i class="bi bi-info-circle me-1" aria-hidden="true"></i>
<span data-save-status-text></span>
</div>
<div class="actions mt-3"><a class="btn btn-outline-secondary" href="{{ isset($voucher) ? route('vouchers.index') : ($record->exists?route('ledger.show',$record):route('ledger.index')) }}">Cancel</a><button class="btn btn-primary" type="submit">{{ isset($voucher) ? 'Save voucher draft' : 'Save Draft' }}</button></div>
</div>
<div class="col-lg-4 journal-guidance-sticky">
@include('ledger.debit-credit-guide')
</div>
</div>
</form>
