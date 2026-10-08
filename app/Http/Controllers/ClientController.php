<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
}
