@php
    $setupData = isset($options['setup']) ? $options['setup'] + ['account_count' => count($options['accounts']), 'periods' => $options['periods']] : null;
    $setupDate = old('transaction_date', $record->transaction_date?->toDateString() ?? today()->toDateString());
    $setupDateValid = is_string($setupDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $setupDate);
    $yearReady = $setupData && $setupDateValid && collect($setupData['years'])->contains(fn ($year) => $year['starts_on'] <= $setupDate && $year['ends_on'] >= $setupDate);
    $periodReady = $setupData && $setupDateValid && collect($setupData['periods'])->contains(fn ($period) => $period['starts_on'] <= $setupDate && $period['ends_on'] >= $setupDate);
    $accountReady = $setupData && $setupData['account_count'] > 0;
    $setupComplete = $accountReady && $yearReady && $periodReady;
@endphp
<section class="client-accounting-setup" data-client-setup data-setup="{{ json_encode($setupData) }}" aria-label="Client accounting setup">
    <div class="setup-heading"><h3>Client setup checklist</h3><span data-setup-summary role="status">{{ !$setupData ? 'Select a client' : ($setupComplete ? '3 of 3 complete' : 'Setup needs attention') }}</span></div>
    <p class="subtext">Check setup for the transaction date before entering journal lines.</p>
    <ul class="setup-checks">
        @foreach(['accounts' => ['Active accounts', $accountReady, 'Active accounts available', 'No active accounts'], 'year' => ['Accounting year', $yearReady, 'Transaction date is covered', 'No year covers this date'], 'period' => ['Open accounting period', $periodReady, 'Open period covers this date', 'No open period covers this date']] as $key => [$label, $ready, $completeText, $missingText])
        <li data-setup-check="{{ $key }}" data-state="{{ !$setupData || (!$setupDateValid && $key !== 'accounts') ? 'pending' : ($ready ? 'complete' : 'missing') }}">
            <span class="setup-check-symbol" data-setup-symbol aria-hidden="true">{{ !$setupData ? '—' : ($ready ? '✓' : '!') }}</span>
            <div><strong>{{ $label }}</strong><span data-setup-detail>{{ !$setupData ? 'Select a client to check' : (!$setupDateValid && $key !== 'accounts' ? 'Enter a transaction date' : ($ready ? $completeText : $missingText)) }}</span>
                @php($setupUrl = $setupData[$key === 'accounts' ? 'accounts_url' : 'periods_url'] ?? null)
                <a data-setup-link @if($setupUrl) href="{{ $setupUrl }}" @else hidden @endif>{{ $key === 'accounts' ? 'Set up accounts' : 'Set up years and periods' }}</a>
            </div>
        </li>
        @endforeach
    </ul>
    <p class="setup-guidance" data-setup-guidance>{{ !$setupData ? 'Choose a client to see what is ready and what needs setup.' : ($setupComplete ? 'Setup is complete for this date. Select the matching period below; accounts, amounts, and permissions are still checked when saving.' : ($setupData['accounts_url'] ? 'Complete the missing setup before entering your voucher. Use an existing year or period if it already covers your dates.' : 'Ask an Owner or Office Manager to complete the missing setup for this client.')) }}</p>
</section>
