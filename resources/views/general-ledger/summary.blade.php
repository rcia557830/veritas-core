<div class="toolbar"><h2 class="section-title">{{ $result['account']->code }} · {{ $result['account']->name }}</h2><x-badge :status="$result['account']->is_active ? 'Active' : 'Inactive'"/></div>
<p>{{ $client->business_name }} · {{ $result['account']->classification }} · {{ $filters['start_date'] }} through {{ $filters['end_date'] }} (inclusive)</p>
<dl class="detail-grid ledger-balances">
<div><dt>Opening balance</dt><dd data-ledger-opening>{{ \App\Support\Money::balance($result['balance']->opening) }}</dd></div>
<div><dt>Period debits</dt><dd data-ledger-debits>{{ \App\Support\Money::formatExact(\App\Support\Money::decimal($result['balance']->debits)) }}</dd></div>
<div><dt>Period credits</dt><dd data-ledger-credits>{{ \App\Support\Money::formatExact(\App\Support\Money::decimal($result['balance']->credits)) }}</dd></div>
<div><dt>Net movement</dt><dd>{{ \App\Support\Money::balance($result['balance']->movement) }}</dd></div>
<div><dt>Closing balance</dt><dd data-ledger-closing>{{ \App\Support\Money::balance($result['balance']->closing) }}</dd></div>
</dl>
<p class="form-text">Opening includes all posted movements before {{ $filters['start_date'] }}. Summary totals cover the entire selected date range, including other pages.</p>
