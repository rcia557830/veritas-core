<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Document;
use App\Models\JournalDocument;
use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Accounting\JournalEvidence;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Audit;
use App\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class LedgerController extends ModuleController
{
    protected string $module = 'ledger';

    protected function form(Request $request, $record)
    {
        if ($record->exists && $record->voucher) {
            return redirect()->route('vouchers.edit', $record->voucher);
        }
        Gate::authorize('viewAny', Account::class);
        $clients = Access::query(Client::class)->orderBy('business_name')->get();
        $clientId = old('client_id', $record->client_id ?? $request->input('client_id'));
        $options = ['accounts' => [], 'periods' => [], 'documents' => []];
        if (is_scalar($clientId) && $clients->contains('id', (int) $clientId)) {
            $options = $this->choices(Access::client((int) $clientId));
        }
        $evidence = $record->exists ? $record->evidence()->whereNull('detached_at')->get()->filter(fn ($e) => Gate::allows('view', $e->document)) : collect();

        return view($request->ajax() ? 'ledger.form-content' : 'ledger.form', $this->context() + compact('record', 'clients', 'options', 'evidence'));
    }

    public function options(Request $request)
    {
        Gate::authorize('viewAny', LedgerEntry::class);
        Gate::authorize('viewAny', Account::class);
        $data = $request->validate(['client_id' => ['required', 'integer']]);

        return response()->json($this->choices(Access::client($data['client_id'])));
    }

    public function choices(Client $client): array
    {
        return [
            'accounts' => $client->accounts()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'classification'])->toArray(),
            'periods' => $client->accountingPeriods()->where('status', 'Open')->orderBy('starts_on')->get(['id', 'label', 'starts_on', 'ends_on'])->toArray(),
            'documents' => Access::query(Document::class)->where('client_id', $client->id)->orderBy('document_number')->get()->filter(fn ($d) => ! $d->file_path || Gate::allows('download', $d))->map(fn ($d) => ['id' => $d->id, 'label' => $d->document_number.' - '.$d->title])->values()->all(),
        ];
    }

    public function transition(Request $request, $record)
    {
        Gate::authorize('view', $record);
        $data = $request->validate(['action' => 'required|in:submit,review,return', 'notes' => 'nullable|string|max:3000']);
        $entry = JournalWriter::transition($record, $data['action'], $data['notes'] ?? null);
        Notify::record($entry, 'ledger', 'Journal review: '.$entry->description);

        return back()->with('success', 'Journal workflow updated.');
    }

    public function post($record)
    {
        JournalPosting::post($record);

        return back()->with('success', 'Journal posted successfully. This transaction is now read-only.');
    }

    public function evidence($record, JournalDocument $evidence)
    {
        Gate::authorize('view', $record);
        abort_unless($evidence->ledger_entry_id === $record->id && $evidence->client_id === $record->client_id, 404);
        Gate::authorize('download', $evidence->document);
        abort_unless($evidence->file_path, 404);
        JournalEvidence::verify($evidence);
        Audit::record('evidence.download', 'ledger', $record, 'Retained evidence #'.$evidence->id.' downloaded.');

        return Storage::disk('local')->download($evidence->file_path, $evidence->original_file_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
