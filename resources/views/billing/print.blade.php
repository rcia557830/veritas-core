<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $record->invoice_number }} · VERITAS CORE</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #18283e; font-size: 11px; margin: 32px; line-height: 1.5; }
    .invoice-header { display: table; width: 100%; border-bottom: 3px solid #c9a55f; padding-bottom: 16px; margin-bottom: 24px; }
    .header-left { display: table-cell; vertical-align: top; width: 55%; }
    .header-right { display: table-cell; vertical-align: top; width: 45%; text-align: right; }
    .firm-name { font-size: 16px; font-weight: bold; color: #18283e; margin: 0 0 4px 0; }
    .firm-sub { color: #64748b; font-size: 10px; margin: 0; }
    .invoice-title { font-size: 20px; font-weight: bold; color: #18283e; margin: 0 0 4px 0; }
    .invoice-meta { font-size: 10px; color: #475569; margin: 0; }
    .parties { display: table; width: 100%; margin-bottom: 24px; }
    .party-box { display: table-cell; width: 50%; vertical-align: top; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; }
    .party-title { font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 6px; }
    .party-name { font-size: 13px; font-weight: bold; color: #0f172a; margin-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; margin-bottom: 20px; }
    td, th { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
    th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; color: #475569; font-weight: bold; }
    .numeric { text-align: right; white-space: nowrap; }
    .summary-table { float: right; width: 45%; border-collapse: collapse; margin-top: 10px; }
    .summary-table td, .summary-table th { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; }
    .total-row { font-size: 13px; font-weight: bold; background: #f8fafc; border-top: 2px solid #18283e; }
    .balance-row { font-size: 13px; font-weight: bold; color: #0f172a; background: #fffbeb; }
    .notes-box { clear: both; padding-top: 24px; font-size: 10px; color: #64748b; }
    .print-bar { margin-bottom: 20px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; }
    button { padding: 8px 16px; background: #18283e; color: #ffffff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
    @media print {
        .print-bar, button { display: none !important; }
        body { margin: 12mm; }
    }
</style>
</head>
<body>
@if(empty($pdf))
<div class="print-bar">
    <button type="button" onclick="window.print()">Print invoice</button>
</div>
@endif

<div class="invoice-header">
    <div class="header-left">
        <div class="firm-name">{{ $firm?->firm_name ?? 'RBCIA ACCOUNTING FIRM' }}</div>
        <p class="firm-sub">{{ $firm?->firm_address }}<br>{{ $firm?->firm_email }} · {{ $firm?->contact_number }}</p>
    </div>
    <div class="header-right">
        <div class="invoice-title">INVOICE {{ $record->invoice_number }}</div>
        <p class="invoice-meta">
            <strong>Status:</strong> {{ $record->display_status }}<br>
            <strong>Date issued:</strong> {{ $record->invoice_date->format('M j, Y') }}<br>
            <strong>Payment due:</strong> {{ $record->due_date->format('M j, Y') }}
        </p>
    </div>
</div>

<div class="parties">
    <div class="party-box">
        <div class="party-title">Bill to</div>
        <div class="party-name">{{ $record->client->business_name }}</div>
        <div>{{ $record->client->address ?: 'No registered address on file' }}</div>
        <div>Email: {{ $record->client->email ?: '—' }}</div>
        <div>TIN: {{ $record->client->tin ?: '—' }}</div>
    </div>
</div>

@include('billing.items')

<table class="summary-table">
    <tr>
        <th>Amount paid to date</th>
        <td class="numeric">{{ \App\Support\Money::format($record->amount_paid) }}</td>
    </tr>
    <tr class="balance-row">
        <th>Remaining balance due</th>
        <td class="numeric"><strong>{{ \App\Support\Money::format($record->balance) }}</strong></td>
    </tr>
</table>

<div class="notes-box">
    @if($record->notes)
        <p><strong>Notes / Payment instructions:</strong><br>{{ $record->notes }}</p>
    @endif
    <p>Please include invoice reference <strong>{{ $record->invoice_number }}</strong> with your remittance.</p>
</div>
</body>
</html>
