@php($money = fn(int $cents) => \App\Support\Money::formatExact(\App\Support\Money::decimal($cents)))
<header class="financial-report-heading"><p>VERITAS CORE · Financial Reports</p><h2>{{ $client->business_name }}</h2><h3>{{ $types[$filters['type']] }}</h3>
<p>{{ in_array($filters['type'], ['trial-balance','balance-sheet']) ? 'As of '.$filters['end_date'] : $filters['start_date'].' through '.$filters['end_date'].' (inclusive)' }}</p>
@if($period)<p>Accounting period: {{ $period->label }} · {{ $period->status }}</p>@endif</header>
@if($filters['type'] === 'accounts-ledger')
@php($ledger = $result['ledger'])
<h4>{{ $ledger['account']->code }} · {{ $ledger['account']->name }} · {{ $ledger['account']->classification }}</h4>
<dl class="detail-grid"><div><dt>Opening balance</dt><dd data-report-opening>{{ \App\Support\Money::balance($ledger['balance']->opening) }}</dd></div><div><dt>Period debits</dt><dd>{{ $money($ledger['balance']->debits) }}</dd></div><div><dt>Period credits</dt><dd>{{ $money($ledger['balance']->credits) }}</dd></div><div><dt>Closing balance</dt><dd data-report-closing>{{ \App\Support\Money::balance($ledger['balance']->closing) }}</dd></div></dl>
<p>Complete report · {{ $ledger['count'] }} movements. Opening includes posted movements before {{ $filters['start_date'] }}.</p>
<div class="table-responsive" tabindex="0" role="region" aria-label="Accounts Ledger"><table class="table"><thead><tr><th>Date</th><th>Journal / reference</th><th>Description</th><th class="numeric">Debit</th><th class="numeric">Credit</th><th class="numeric">Running balance</th></tr></thead><tbody>
@forelse($ledger['rows'] as $line)<tr data-report-line><td>{{ $line['date'] }}</td><td><a href="{{ route('ledger.show', $line['journal_id']) }}">Journal #{{ $line['journal_id'] }}</a><div>{{ $line['reference'] }}</div></td><td>{{ $line['description'] }}</td><td class="numeric">{{ $money($line['debit']) }}</td><td class="numeric">{{ $money($line['credit']) }}</td><td class="numeric">{{ \App\Support\Money::balance($line['running']) }}</td></tr>
@empty<tr><td colspan="6">No posted movements in this date range. Any opening balance is carried forward.</td></tr>@endforelse
</tbody><tfoot><tr><th colspan="3">Period totals / closing balance</th><th class="numeric">{{ $money($ledger['balance']->debits) }}</th><th class="numeric">{{ $money($ledger['balance']->credits) }}</th><th class="numeric">{{ \App\Support\Money::balance($ledger['balance']->closing) }}</th></tr></tfoot></table></div>
@else
@php($valid = $result['difference'] === 0 && ($filters['type'] !== 'balance-sheet' || $result['equationDifference'] === 0))
<p class="report-reconciliation {{ $valid ? '' : 'report-warning' }}" role="status" data-report-reconciliation>{{ $valid ? 'Reconciled: posted ledger debits equal credits.' : 'UNRECONCILED — do not treat this report as valid. Investigate the source ledger; no balancing adjustment has been inserted.' }} Difference: {{ $money($result['difference']) }}.</p>
@if($filters['type'] === 'trial-balance')
<p>Cumulative account balances through the reporting date. Opening balances are included once. Actual debit and credit directions are preserved.</p>
@include('financial-reports.accounts', ['sections'=>array_keys($result['groups']), 'showAmount'=>false])
<dl class="detail-grid"><div><dt>Total debit balances</dt><dd data-report-debits>{{ $money($result['debits']) }}</dd></div><div><dt>Total credit balances</dt><dd data-report-credits>{{ $money($result['credits']) }}</dd></div></dl>
@elseif($filters['type'] === 'income-statement')
<p>Posted movements during the selected period. Revenue is credit-positive; expenses are debit-positive. Negative amounts show reversals or unusual balances.</p>
@include('financial-reports.accounts', ['sections'=>['Revenue','Expense'], 'showAmount'=>true])
<dl class="detail-grid"><div><dt>Total revenue</dt><dd data-report-revenue>{{ $money($result['totals']['Revenue']) }}</dd></div><div><dt>Total expenses</dt><dd data-report-expenses>{{ $money($result['totals']['Expense']) }}</dd></div><div><dt>{{ $result['netIncome'] < 0 ? 'Net loss' : 'Net income' }}</dt><dd data-report-income>{{ $money($result['netIncome']) }}</dd></div></dl>
@else
<p>Fiscal year: {{ $result['year']->label }} · {{ substr($result['year']->starts_on,0,10) }} through {{ substr($result['year']->ends_on,0,10) }}.</p>
<p>Statement amounts are debit-positive for assets and credit-positive for liabilities and equity. Negative amounts preserve unusual balances.</p>
@include('financial-reports.accounts', ['sections'=>['Asset','Liability','Equity'], 'showAmount'=>true])
<dl class="detail-grid"><div><dt>Total assets</dt><dd data-report-assets>{{ $money($result['totals']['Asset']) }}</dd></div><div><dt>Total liabilities</dt><dd>{{ $money($result['totals']['Liability']) }}</dd></div><div><dt>Posted equity accounts</dt><dd>{{ $money($result['totals']['Equity']) }}</dd></div><div><dt>Prior-year unclosed earnings (temporary; not retained earnings)</dt><dd data-report-prior>{{ $money($result['priorEarnings']) }}</dd></div><div><dt>Current fiscal-year unclosed earnings through reporting date</dt><dd data-report-current>{{ $money($result['currentEarnings']) }}</dd></div><div><dt>Total liabilities and equity, including unclosed earnings</dt><dd data-report-equity>{{ $money($result['liabilitiesEquity']) }}</dd></div></dl>
<p data-report-equation><strong>Assets = Liabilities + Equity: {{ $result['equationDifference'] === 0 ? 'Verified' : 'NOT RECONCILED' }}</strong> · Difference: {{ $money($result['equationDifference']) }}.</p>
<p class="report-warning">Provisional development convention, not confirmed RBCIA accounting policy: unclosed revenue/expense balances are shown separately from posted equity. Prior-year unclosed earnings are temporary balances, not formally closed retained earnings. No earnings transfers, closing entries, or balancing figures are generated.</p>
@endif
@if(collect($result['groups'])->flatten(1)->isEmpty())<p>No accounts configured for this client.</p>@endif
@endif
<p class="form-text">Read-only report from Posted journals at request time. Unposted and unmapped records are excluded. Period closure alone does not create financial closing entries. Where closing entries have been posted, their account movements remain included; there is no separate pre-closing statement basis. Unclosed periods and incomplete posting can make these reports provisional. Reconciliation checks arithmetic consistency, not completeness of the books.</p>
