<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $types[$filters['type']] }} · VERITAS CORE</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #18283e; font-size: 11px; margin: 24px; line-height: 1.5; }
    .print-actions { margin-bottom: 20px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; gap: 12px; }
    .print-actions button { padding: 8px 16px; background: #18283e; color: #ffffff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
    .print-actions a { color: #3b82f6; text-decoration: none; font-size: 12px; }
    .print-actions a:hover { text-decoration: underline; }
    .financial-report-heading { border-bottom: 3px solid #c9a55f; margin-bottom: 20px; padding-bottom: 12px; }
    .eyebrow { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
    h2 { font-size: 18px; margin: 4px 0; color: #18283e; }
    h3 { font-size: 14px; margin: 0 0 6px 0; color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; margin-bottom: 20px; }
    td, th { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e2e8f0; overflow-wrap: anywhere; }
    th { font-size: 10px; font-weight: bold; text-transform: uppercase; background: #f8fafc; color: #475569; }
    .numeric { text-align: right; white-space: nowrap; }
    .detail-grid { display: flex; flex-wrap: wrap; gap: 20px; margin: 16px 0; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; }
    dt { font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; }
    dd { margin: 4px 0 0; font-size: 13px; font-weight: bold; color: #0f172a; }
    a { color: inherit; text-decoration: none; }
    .report-warning { padding: 10px 14px; border: 1px solid #c9a55f; background: #fffdf5; border-radius: 4px; font-size: 11px; margin: 12px 0; }
    .report-reconciliation { padding: 8px 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 4px; font-size: 11px; margin: 12px 0; color: #166534; }
    .form-text, .subtext { font-size: 10px; color: #64748b; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
    @page { size: landscape; margin: 12mm; }
    @media print {
        .print-actions { display: none !important; }
        body { margin: 0; }
    }
    @media screen and (max-width: 600px) {
        .table-responsive { overflow-x: auto; }
        table { min-width: 760px; }
    }
</style>
</head>
<body>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print report</button>
    <a href="{{ route('financial-reports.index', $filters) }}">Back to Financial Reports</a>
</div>
@include('financial-reports.content')
</body>
</html>
