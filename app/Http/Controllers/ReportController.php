<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LedgerItem;
use App\Services\Access;
use App\Services\Records;
use App\Services\Summary;
use App\Support\Display;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('report.view');
        Gate::authorize('report.generate');
        $request->validate(['module' => 'nullable|string|in:'.implode(',', array_keys(Modules::all()))]);
        $module = ($request->query('module') ?: 'clients');
        $config = Modules::get($module);
        Gate::authorize('viewAny', $config['model']);
        $query = Records::query($module, $request);
        $summary = ['Records' => (clone $query)->count()];
        if ($module === 'billing') {
            $summary += Summary::billing(clone $query);
        }
        if ($module === 'ledger') {
            foreach (['debit', 'credit'] as $key) {
                $summary['Total '.$key] = LedgerItem::whereIn('ledger_entry_id', (clone $query)->reorder()->select('id'))->sum($key);
            }
        }
        $records = $query->paginate(Records::pageSize())->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();

        return view('reports.index', compact('module', 'config', 'records', 'clients', 'summary'));
    }

    public function csv(Request $request)
    {
        Gate::authorize('report.export');
        $request->validate(['module' => 'nullable|string|in:'.implode(',', array_keys(Modules::all()))]);
        $module = ($request->query('module') ?: 'clients');
        $config = Modules::get($module);
        Gate::authorize('viewAny', $config['model']);
        $query = Records::query($module, $request);

        return response()->streamDownload(function () use ($query, $config) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($config['columns']));
            $query->reorder()->chunkById(200, function ($records) use ($out, $config) {
                foreach ($records as $r) {
                    $row = [];
                    foreach ($config['columns'] as $key => $label) {
                        $value = Display::value($r, $key);
                        if (preg_match('/^[\s]*[=+@-]/u', $value)) {
                            $value = "'".$value;
                        }$row[] = $value;
                    }fputcsv($out, $row);
                }
            });
            fclose($out);
        }, $module.'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
