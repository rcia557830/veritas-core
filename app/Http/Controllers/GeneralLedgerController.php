<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Accounting\GeneralLedger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GeneralLedgerController extends Controller
{
    public function index(Request $request, GeneralLedger $ledger)
    {
        return $this->render($request, $ledger);
    }

    public function print(Request $request, GeneralLedger $ledger)
    {
        Gate::authorize('report.print');

        return $this->render($request, $ledger, true);
    }

    private function render(Request $request, GeneralLedger $ledger, bool $print = false)
    {
        Gate::authorize('generalLedger', LedgerEntry::class);
        $filters = Validator::make($request->query() + [
            'start_date' => today()->startOfMonth()->toDateString(), 'end_date' => today()->toDateString(), 'page' => 1, 'per_page' => 25,
        ], [
            'client_id' => [$print ? 'required' : 'nullable', 'integer', 'min:1'],
            'account_id' => [$print ? 'required' : 'nullable', 'integer', 'min:1'],
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:9999-12-31',
            'page' => 'required|integer|min:1|max:1000000', 'per_page' => ['required', 'integer', Rule::in([10, 25, 50, 100])],
        ])->validate();
        // Client selection is a separate GET step and may have no account yet.
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $client = ! empty($filters['client_id']) ? Access::client((int) $filters['client_id']) : null;
        abort_if(! $client && ! empty($filters['account_id']), 404);
        $accounts = $client ? $client->accounts()->orderBy('code')->orderBy('id')->get() : collect();
        $result = $movements = null;
        if ($client && ! empty($filters['account_id'])) {
            $account = $accounts->firstWhere('id', (int) $filters['account_id']);
            abort_unless($account, 404);
            $result = $ledger->account($client, $account, $filters['start_date'], $filters['end_date'], (int) $filters['page'], (int) $filters['per_page']);
            $movements = new LengthAwarePaginator($result['rows'], $result['count'], (int) $filters['per_page'], (int) $filters['page'], [
                'path' => route('general-ledger.index'), 'query' => array_diff_key($filters, ['page' => true]),
            ]);
        }

        return view($print ? 'general-ledger.print' : 'general-ledger.index', compact('clients', 'client', 'accounts', 'filters', 'result', 'movements'));
    }
}
