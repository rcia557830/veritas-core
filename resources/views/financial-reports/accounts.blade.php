<div class="table-responsive" tabindex="0" role="region" aria-label="Financial report accounts"><table class="table"><thead><tr><th>Code</th><th>Account description</th><th>Classification</th><th class="numeric">Debit</th><th class="numeric">Credit</th>@if($showAmount)<th class="numeric">Statement amount</th>@endif</tr></thead><tbody>
@foreach($sections as $classification)
<tr><th colspan="{{ $showAmount ? 6 : 5 }}">{{ $classification }} accounts</th></tr>
@forelse($result['groups'][$classification] as $row)
<tr data-report-account="{{ $row['account']->id }}"><td>{{ $row['account']->code }}</td><td><a href="{{ route('general-ledger.index', ['client_id'=>$client->id,'account_id'=>$row['account']->id,'start_date'=>$result['start'],'end_date'=>$filters['end_date']]) }}">{{ $row['account']->name }}</a></td><td>{{ $classification }}</td><td class="numeric">{{ $money($row['debit']) }}</td><td class="numeric">{{ $money($row['credit']) }}</td>@if($showAmount)<td class="numeric">{{ $money($row['presentation']) }}</td>@endif</tr>
@empty<tr><td colspan="{{ $showAmount ? 6 : 5 }}">No {{ strtolower($classification) }} accounts.</td></tr>@endforelse
@if($showAmount)<tr><th colspan="5">Total {{ strtolower($classification) }}</th><th class="numeric">{{ $money($result['totals'][$classification]) }}</th></tr>@endif
@endforeach
</tbody></table></div>
