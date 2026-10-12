<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Services\Access;
use App\Services\Accounting\AccountingTransaction;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingPeriodController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Account::class);
        $request->validate(['client_id' => 'nullable|integer|min:1']);
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $client = $request->filled('client_id') ? Access::client($request->integer('client_id')) : null;
        $years = $client ? AccountingYear::where('client_id', $client->id)->with(['periods' => fn ($q) => $q->withCount('entries')->with('closer')->orderBy('starts_on')])->orderByDesc('starts_on')->get() : collect();

        return view('accounting-periods.index', compact('client', 'clients', 'years'));
    }

    public function storeYear(Request $request, Client $client)
    {
        Gate::authorize('create', [Account::class, $client]);
        $data = $request->validate($this->dateRules());
        AccountingTransaction::forClient($client->id, function () use ($client, $data) {
            Gate::authorize('create', [Account::class, $client->fresh()]);
            $year = AccountingYear::create($data + ['client_id' => $client->id]);
            Audit::record('year.created', 'accounting-periods', $year, 'Accounting year created: '.$year->label.'.');
        });

        return back()->with('success', 'Accounting year created.');
    }

    public function storePeriod(Request $request, Client $client)
    {
        Gate::authorize('create', [Account::class, $client]);
        $data = $request->validate($this->dateRules() + ['accounting_year_id' => 'required|integer']);
        AccountingTransaction::forClient($client->id, function () use ($client, $data) {
            Gate::authorize('create', [Account::class, $client->fresh()]);
            $period = AccountingPeriod::create($data + ['client_id' => $client->id]);
            Audit::record('period.created', 'accounting-periods', $period, 'Accounting period created: '.$period->label.'.');
        });

        return back()->with('success', 'Accounting period created.');
    }

    public function close(Request $request, AccountingPeriod $period)
    {
        Gate::authorize('create', [Account::class, $period->client]);
        abort_unless($request->user()->hasPermission('account.update'), 403);
        $request->validate(['confirmation' => 'required|accepted']);
        AccountingTransaction::forClient($period->client_id, function () use ($period, $request) {
            $period = AccountingPeriod::lockForUpdate()->findOrFail($period->id);
            Gate::authorize('create', [Account::class, $period->client]);
            if ($period->status === 'Closed') {
                throw ValidationException::withMessages(['period' => 'This period is already closed.']);
            }
            if ($period->entries()->whereIn('status', ['For Review', 'Reviewed'])->exists()) {
                throw ValidationException::withMessages(['period' => 'Resolve journals awaiting review or posting before closing this period.']);
            }
            $period->forceFill(['status' => 'Closed', 'closed_at' => now(), 'closed_by' => $request->user()->id])->save();
            Audit::record('period.closed', 'accounting-periods', $period, 'Accounting period closed: '.$period->label.'.');
        });

        return back()->with('success', 'Accounting period closed.');
    }

    private function dateRules(): array
    {
        return ['label' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on'];
    }
}
