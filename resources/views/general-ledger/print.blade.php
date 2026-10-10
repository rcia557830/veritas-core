<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>General Ledger · VERITAS CORE</title>
<style>body{font-family:Arial,sans-serif;color:#18283e;font-size:12px;margin:24px}h1{border-bottom:3px solid #c9a55f;padding-bottom:12px}table{width:100%;border-collapse:collapse}td,th{padding:8px;text-align:left;border-bottom:1px solid #dfe4ea;overflow-wrap:anywhere}.numeric{text-align:right;white-space:nowrap}.detail-grid{display:flex;flex-wrap:wrap;gap:24px}dt{font-weight:bold}dd{margin:8px 0}a{color:#18283e}.subtext,.form-text{color:#526176}thead{display:table-header-group}tr{break-inside:avoid}@media print{.print-actions{display:none}body{margin:0}}@media screen and (max-width:600px){.table-responsive{overflow-x:auto}table{min-width:700px}}</style></head><body>
<div class="print-actions"><button type="button" onclick="window.print()">Print General Ledger</button> <a href="{{ route('general-ledger.index', $filters) }}">Back to General Ledger</a></div>
<h1>VERITAS CORE · General Ledger</h1>
@include('general-ledger.summary')
<p><strong>Page {{ $movements->currentPage() }} of {{ $movements->lastPage() }} · Movements {{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }} of {{ $movements->total() }}</strong></p>
@include('general-ledger.movements')
<p>Read-only copy of the selected page. Balances are calculated from Posted journals at the time this page was requested.</p>
</body></html>
