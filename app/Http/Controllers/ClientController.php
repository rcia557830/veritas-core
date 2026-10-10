<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\OnboardingReadiness;
use App\Services\Records;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientController extends ModuleController
{
    protected string $module = 'clients';

    public function show($record)
    {
        Gate::authorize('view', $record);
        $related = [];
        foreach (['Documents' => 'documents', 'Ledger Review' => 'ledger', 'Compliance' => 'compliance', 'Billing' => 'billing'] as $label => $module) {
            $model = Modules::get($module)['model'];
            if (! Gate::allows('viewAny', $model)) {
                continue;
            }
            $query = Access::query($model)->where('client_id', $record->id);
            if ($module === 'billing') {
                $query->with(['items', 'payments']);
            }
            $related[$label] = $query->latest()->limit(10)->get();
        }
        $activity = Audit::visible()->where('client_id', $record->id)->with('user')->latest()->limit(20)->get();

        return view('clients.show', $this->context() + compact('record', 'related', 'activity'));
    }

    public function archive($record)
    {
        Gate::authorize($record->status === 'Archived' ? 'restore' : 'archive', $record);
        DB::transaction(function () use ($record) {
            $record = Client::lockForUpdate()->findOrFail($record->id);
            Gate::authorize($record->status === 'Archived' ? 'restore' : 'archive', $record);
            $record->update(['status' => $record->status === 'Archived' ? 'Active' : 'Archived']);
            Audit::record('status', 'clients', $record, 'Client '.$record->status.'.');
        });

        return back()->with('success', 'Client status updated.');
    }

    public function destroy($record)
    {
        return $this->archive($record);
    }

    public function onboarding(Request $request)
    {
        Gate::authorize('viewAny', Client::class);
        $request->validate(['state' => 'nullable|in:onboarded,not_configured,not_started,in_progress,awaiting_verification,needs_clarification,ready_for_activation,pending,missing']);

        $clients = Access::query(Client::class)
            ->with(['assignee', 'documentRequirements' => fn ($q) => $q->where('is_active', true)->where('scope', OnboardingReadiness::onboardingScope())->with('documents')])
            ->orderBy('business_name')
            ->get();

        $rows = $clients->map(function (Client $client) {
            $calc = OnboardingReadiness::calculate($client, $client->documentRequirements);

            return (object) [
                'client' => $client,
                'state' => $calc['state'],
                'percentage' => $calc['percentage'],
                'verified' => $calc['counts']['verified'],
                'total' => $calc['total'],
                'missing' => $calc['counts']['missing'],
            ];
        })->values();

        $summary = [
            'total' => $rows->count(),
            'onboarded' => $rows->where('state', 'onboarded')->count(),
            'pending' => $rows->whereIn('state', ['not_started', 'in_progress', 'awaiting_verification', 'needs_clarification'])->count(),
            'awaiting_verification' => $rows->where('state', 'awaiting_verification')->count(),
            'ready' => $rows->where('state', 'ready_for_activation')->count(),
            'not_configured' => $rows->where('state', 'not_configured')->count(),
            'clients_missing' => $rows->where('missing', '>', 0)->count(),
        ];

        $state = $request->query('state');
        if ($state === 'pending') {
            $rows = $rows->whereIn('state', ['not_started', 'in_progress', 'awaiting_verification', 'needs_clarification'])->values();
        } elseif ($state === 'missing') {
            $rows = $rows->where('missing', '>', 0)->values();
        } elseif ($state) {
            $rows = $rows->where('state', $state)->values();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = Records::pageSize($request);
        $records = new LengthAwarePaginator($rows->forPage($page, $perPage), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('clients.onboarding', compact('records', 'summary'));
    }

    public function onboardingChecklist(Client $client)
    {
        Gate::authorize('view', $client);
        $requirements = OnboardingReadiness::requirements($client);
        $readiness = OnboardingReadiness::calculate($client, $requirements);
        $profile = OnboardingReadiness::profileProgress($client);
        $documents = Access::query(Document::class)->where('client_id', $client->id)->latest()->limit(500)->get();
        $users = User::where('status', 'Active')->orderBy('name')->get();

        return view('clients.onboarding-checklist', compact('client', 'requirements', 'readiness', 'profile', 'documents', 'users'));
    }

    public function activate(Request $request, Client $client)
    {
        Gate::authorize('activate', $client);
        $data = $request->validate(['exemption_reason' => ['nullable', 'string', 'max:30000']]);

        $result = DB::transaction(function () use ($client, $request, $data) {
            $client = Client::lockForUpdate()->findOrFail($client->id);
            Gate::authorize('activate', $client);
            $check = OnboardingReadiness::canActivate($client);

            if ($check['ok']) {
                $client->update(['onboarded_at' => now(), 'onboarded_by' => $request->user()->id]);
                Audit::record('onboarding.completed', 'clients', $client, 'Onboarding completed by '.$request->user()->name.'.');

                return ['ok' => true, 'message' => 'Client onboarding completed.'];
            }

            if ($check['exemptable']) {
                $reason = trim((string) ($data['exemption_reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages(['exemption_reason' => 'Provide a justification to complete onboarding for a client with no configured onboarding requirements.']);
                }
                $client->update(['onboarded_at' => now(), 'onboarded_by' => $request->user()->id]);
                Audit::record('onboarding.completed', 'clients', $client, 'Onboarding completed with exemption by '.$request->user()->name.': '.$reason.'.');

                return ['ok' => true, 'message' => 'Client onboarding completed with a recorded exemption.'];
            }

            return ['ok' => false, 'blockers' => $check['blockers']];
        });

        if ($result['ok']) {
            return redirect()->route('clients.onboarding.checklist', $client)->with('success', $result['message']);
        }

        return redirect()->route('clients.onboarding.checklist', $client)->with('onboarding_blockers', $result['blockers']);
    }
}
