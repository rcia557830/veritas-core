<section aria-label="Overall billing totals" class="billing-summary mb-4">
<div class="row g-3">
<x-stat label="Total billed" :value="\App\Support\Money::format($billingSummary['billed'])" icon="receipt"/>
<x-stat label="Total collected" :value="\App\Support\Money::format($billingSummary['collected'])" icon="cash-stack" tone="success"/>
<x-stat label="Outstanding balance" :value="\App\Support\Money::format($billingSummary['outstanding'])" icon="wallet2"/>
<x-stat label="Overdue amount" :value="\App\Support\Money::format($billingSummary['overdue'])" icon="exclamation-circle" tone="warning"/>
</div><p class="subtext mt-2 mb-0">Totals for all invoices you can access. Search and filters below affect the table only.</p>
</section>
