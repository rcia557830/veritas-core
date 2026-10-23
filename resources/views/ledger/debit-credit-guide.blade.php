<aside class="debit-credit-guidance-card" data-debit-credit-guide aria-label="Debit and credit guidance">
    <div class="guidance-card-header">
        <h3 class="guidance-card-title"><i class="bi bi-book me-2" aria-hidden="true"></i>Debit & Credit Guide</h3>
        <span class="badge bg-warning text-dark" data-live-balance-badge>Unbalanced</span>
    </div>

    <div class="guidance-rule-box">
        <strong>The Balancing Rule</strong>
        <p class="subtext mb-1">Double-entry accounting requires:</p>
        <div class="equation-badge">Total Debits = Total Credits</div>
    </div>

    <div class="guidance-example-box">
        <div class="example-header"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i> Example: Rent Payment</div>
        <div class="example-table">
            <div class="example-row"><span class="badge-dr">DR</span> <span>Rent Expense</span> <strong class="ms-auto">₱15,000.00</strong></div>
            <div class="example-row"><span class="badge-cr">CR</span> <span>Cash in Bank</span> <strong class="ms-auto">₱15,000.00</strong></div>
        </div>
        <p class="subtext mb-0 mt-2">Every debit to an expense/asset has an equal credit to an asset/liability.</p>
    </div>

    <div class="guidance-save-status">
        <div class="save-status-heading mb-2">
            <strong><i class="bi bi-shield-check me-1" aria-hidden="true"></i> {{ isset($voucher) ? 'Voucher save requirements' : 'Submission requirements' }}</strong>
        </div>
        <ul class="guidance-checklist" data-guidance-checklist>
            <li data-guide-check="single-sided"><span class="guide-check-symbol" aria-hidden="true">✓</span> <span>One side per line (DR or CR)</span></li>
            <li data-guide-check="valid-amounts"><span class="guide-check-symbol" aria-hidden="true">✓</span> <span>Valid positive numbers</span></li>
            <li data-guide-check="accounts-assigned"><span class="guide-check-symbol" aria-hidden="true">—</span> <span>Account chosen for each line</span></li>
            <li data-guide-check="totals-balanced"><span class="guide-check-symbol" aria-hidden="true">—</span> <span data-balanced-text>Totals balance (Difference: ₱0.00)</span></li>
            <li data-guide-check="period-selected"><span class="guide-check-symbol" aria-hidden="true">—</span> <span>Open accounting period chosen</span></li>
        </ul>
        <div class="guidance-block-reason alert alert-warning p-2 mb-0" data-guidance-block-reason role="status">
            {{ isset($voucher) ? 'Saving voucher requires balanced debits and credits and an open period.' : 'Drafts can be saved unbalanced, but review submission requires balanced lines.' }}
        </div>
    </div>
</aside>
