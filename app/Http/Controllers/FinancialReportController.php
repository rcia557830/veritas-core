<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Accounting\FinancialReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinancialReportController extends Controller
{
    public function index(Request $request, FinancialReports $reports)
    {
        return $this->render($request, $reports);
    }

    public function print(Request $request, FinancialReports $reports)
    {
        Gate::authorize('report.print');

        return $this->render($request, $reports, true);
    }

    private function render(Request $request, FinancialReports $reports, bool $print = false)
    {
        Gate::authorize('financialReports', LedgerEntry::class);
        $filters = Validator::make($request->query() + ['type' => 'trial-balance', 'start_date' => today()->startOfMonth()->toDateString(), 'end_date' => today()->toDateString()], [
            'client_id' => [$print ? 'required' : 'nullable', 'integer', 'min:1'],
            'period_id' => 'nullable|integer|min:1', 'account_id' => 'nullable|integer|min:1',
            'type' => ['required', Rule::in(array_keys(FinancialReports::TYPES))],
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:9999-12-31',
        ])->validate();
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $client = ! empty($filters['client_id']) ? Access::client((int) $filters['client_id']) : null;
        abort_if(! $client && (! empty($filters['period_id']) || ! empty($filters['account_id'])), 404);
        $periods = $client ? AccountingPeriod::where('client_id', $client->id)->orderByDesc('starts_on')->get() : collect();
        $accounts = $client ? $client->accounts()->orderBy('code')->get() : collect();
        $period = null;
        if (! empty($filters['period_id'])) {
            $period = $periods->firstWhere('id', (int) $filters['period_id']);
            abort_unless($period, 404);
            $filters['start_date'] = substr($period->starts_on, 0, 10);
            $filters['end_date'] = substr($period->ends_on, 0, 10);
        }
        if (! empty($filters['account_id'])) {
            abort_unless($accounts->contains('id', (int) $filters['account_id']), 404);
        }
        $result = null;
        if ($client && ($filters['type'] !== 'accounts-ledger' || ! empty($filters['account_id']))) {
            $result = $reports->generate($client, $filters['type'], $filters['start_date'], $filters['end_date'], isset($filters['account_id']) ? (int) $filters['account_id'] : null);
        }
        if ($print && ! $result) {
            abort(422, 'Select an account for the Accounts Ledger report.');
        }
        $types = FinancialReports::TYPES;

        return view($print ? 'financial-reports.print' : 'financial-reports.index', compact('clients', 'client', 'periods', 'period', 'accounts', 'filters', 'result', 'types'));
    }
}
