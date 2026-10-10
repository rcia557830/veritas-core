<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTemplate;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Access;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Account::class);
        $filters = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:150'], 'classification' => ['nullable', Rule::in(ChartOfAccounts::CLASSIFICATIONS)], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $client = ! empty($filters['client_id']) ? Access::client($filters['client_id']) : null;
        $accounts = null;
        if ($client) {
            $query = $client->accounts();
            if (($filters['q'] ?? '') !== '') {
                $query->where(fn ($q) => $q->where('code', 'like', '%'.$filters['q'].'%')->orWhere('name', 'like', '%'.$filters['q'].'%'));
            }
            if (! empty($filters['classification'])) {
                $query->where('classification', $filters['classification']);
            }
            if (! empty($filters['status'])) {
                $query->where('is_active', $filters['status'] === 'active');
            }
            $accounts = $query->orderBy('code')->orderBy('id')->paginate(25)->withQueryString();
        }

        return view('accounts.index', compact('clients', 'client', 'accounts'));
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Account::class);
        $request->validate(['client_id' => ['required', 'integer']]);
        $client = Access::client((int) $request->input('client_id'));
        Gate::authorize('create', [Account::class, $client]);

        return view('accounts.form', ['account' => new Account, 'client' => $client]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Account::class);
        $request->validate(['client_id' => ['required', 'integer']]);
        $account = ChartOfAccounts::save(Access::client((int) $request->input('client_id')), $request->all());

        return redirect()->route('accounts.show', $account)->with('success', 'Account created.');
    }

    public function show(Account $account)
    {
        Gate::authorize('view', $account);
        $history = AuditLog::where('module', 'accounts')->where('record_id', $account->id)->latest('id')->paginate(15);

        return view('accounts.show', compact('account', 'history'));
    }

    public function edit(Account $account)
    {
        Gate::authorize('update', $account);

        return view('accounts.form', ['account' => $account, 'client' => $account->client]);
    }

    public function update(Request $request, Account $account)
    {
        Gate::authorize('update', $account);
        // Client and source identifiers are never editable through this endpoint.
        $request->validate(['client_id' => ['prohibited'], 'account_template_item_id' => ['prohibited'], 'is_active' => ['prohibited']]);
        ChartOfAccounts::save($account->client, $request->all(), $account);

        return redirect()->route('accounts.show', $account)->with('success', 'Account updated.');
    }

    public function status(Request $request, Account $account)
    {
        Gate::authorize('status', $account);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        ChartOfAccounts::status($account, (bool) $data['is_active']);

        return redirect()->route('accounts.show', $account)->with('success', 'Account status saved. Historical transactions are preserved.');
    }

    public function initialization(Request $request)
    {
        Gate::authorize('initialize', Account::class);
        $request->validate(['client_id' => ['required', 'integer'], 'template_id' => ['nullable', 'integer']]);
        $client = Access::client((int) $request->input('client_id'));
        Gate::authorize('initialize', [Account::class, $client]);
        $templates = AccountTemplate::where('is_active', true)->orderBy('name')->orderByDesc('version')->get();
        $template = $request->filled('template_id') ? AccountTemplate::where('is_active', true)->findOrFail($request->input('template_id')) : null;
        $items = $template?->items()->where('is_active', true)->orderBy('code')->get();

        return view('accounts.initialize', compact('client', 'templates', 'template', 'items'));
    }

    public function initialize(Request $request)
    {
        Gate::authorize('initialize', Account::class);
        $data = $request->validate(['client_id' => ['required', 'integer'], 'template_id' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        $client = Access::client($data['client_id']);
        $result = ChartOfAccounts::initialize($client, AccountTemplate::findOrFail($data['template_id']));

        return redirect()->route('accounts.index', ['client_id' => $client->id])->with('success', $result['created'].' accounts copied; '.$result['existing'].' previously copied accounts retained.');
    }
}
