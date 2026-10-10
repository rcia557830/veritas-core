<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\DocumentRequirementTemplate;
use App\Services\Access;
use App\Services\Audit;
use App\Services\DocumentCompleteness;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RequirementController extends Controller
{
    private function types(): array
    {
        return Modules::get('documents')['fields']['document_type'][1];
    }

    private function rules(bool $forCreate): array
    {
        return [
            'client_id' => [$forCreate ? 'required' : 'sometimes', 'integer', 'exists:clients,id'],
            'template_id' => ['nullable', 'integer', 'exists:document_requirement_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', $this->types())],
            'description' => ['nullable', 'string', 'max:30000'],
            'accounting_period_id' => ['nullable', 'integer', 'exists:accounting_periods,id'],
            'scope' => ['nullable', 'string', 'in:onboarding,periodic'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:30000'],
        ];
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', DocumentRequirement::class);
        $request->validate([
            'q' => 'nullable|string|max:150',
            'client_id' => 'nullable|integer|min:1',
            'accounting_period_id' => 'nullable|integer|min:1',
            'type' => 'nullable|string|max:255',
            'scope' => 'nullable|in:all,required,optional,active,inactive',
        ]);

        $query = Access::query(DocumentRequirement::class)->with(['client', 'accountingPeriod', 'documents']);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('type', 'like', '%'.$search.'%')
                    ->orWhere('remarks', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', '%'.$search.'%'));
            });
        }
        if ($clientId = $request->integer('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($periodId = $request->integer('accounting_period_id')) {
            $query->where('accounting_period_id', $periodId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        match ($request->query('scope')) {
            'required' => $query->where('is_required', true),
            'optional' => $query->where('is_required', false),
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        $records = $query->orderBy('due_date')->paginate(15)->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $periods = AccountingPeriod::with('client')->orderBy('ends_on', 'desc')->limit(1000)->get();

        return view('requirements.index', compact('records', 'clients', 'periods') + ['types' => $this->types()]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', DocumentRequirement::class);
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $templates = DocumentRequirementTemplate::orderBy('name')->get();
        $periods = $this->periodOptions();

        return view('requirements.form', [
            'requirement' => new DocumentRequirement,
            'clients' => $clients,
            'templates' => $templates,
            'periods' => $periods,
            'types' => $this->types(),
            'preselectedClient' => $request->integer('client_id'),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', DocumentRequirement::class);
        $data = $this->validated($request, true);

        $requirement = DB::transaction(function () use ($data, $request) {
            $requirement = DocumentRequirement::create($data + ['created_by' => $request->user()->id]);
            Audit::record('created', 'requirements', $requirement, 'Document requirement created: '.$requirement->name.'.');

            return $requirement;
        });

        return redirect()->route('requirements.checklist', $requirement->client_id)
            ->with('success', 'Document requirement created.');
    }

    public function edit(DocumentRequirement $requirement)
    {
        Gate::authorize('update', $requirement);
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $templates = DocumentRequirementTemplate::orderBy('name')->get();
        $periods = $this->periodOptions();

        return view('requirements.form', [
            'requirement' => $requirement,
            'clients' => $clients,
            'templates' => $templates,
            'periods' => $periods,
            'types' => $this->types(),
            'preselectedClient' => null,
        ]);
    }

    public function update(Request $request, DocumentRequirement $requirement)
    {
        Gate::authorize('update', $requirement);
        $data = $this->validated($request, false);

        DB::transaction(function () use ($data, $requirement) {
            $requirement->update($data);
            Audit::record('updated', 'requirements', $requirement, 'Document requirement updated: '.$requirement->name.'.');
        });

        return redirect()->route('requirements.checklist', $requirement->client_id)
            ->with('success', 'Document requirement updated.');
    }

    public function toggle(DocumentRequirement $requirement)
    {
        Gate::authorize('activate', $requirement);
        DB::transaction(function () use ($requirement) {
            $requirement = DocumentRequirement::lockForUpdate()->findOrFail($requirement->id);
            $requirement->update(['is_active' => ! $requirement->is_active]);
            Audit::record('requirement.activated', 'requirements', $requirement, 'Document requirement '.($requirement->is_active ? 'activated' : 'deactivated').': '.$requirement->name.'.');
        });

        return back()->with('success', 'Requirement '.($requirement->is_active ? 'activated' : 'deactivated').'.');
    }

    public function destroy(DocumentRequirement $requirement)
    {
        Gate::authorize('delete', $requirement);
        DB::transaction(function () use ($requirement) {
            $requirement = DocumentRequirement::lockForUpdate()->findOrFail($requirement->id);
            Audit::record('archived', 'requirements', $requirement, 'Document requirement archived: '.$requirement->name.'.');
            $requirement->delete();
        });

        return redirect()->route('requirements.index')->with('success', 'Requirement archived.');
    }

    public function checklist(Client $client)
    {
        Gate::authorize('view', $client);

        $requirements = $client->documentRequirements()
            ->with(['documents', 'accountingPeriod', 'followUps.assignee'])
            ->orderByDesc('is_active')
            ->orderBy('due_date')
            ->get();

        $stats = DocumentCompleteness::forClient($client);
        $documents = Access::query(Document::class)->where('client_id', $client->id)->latest()->limit(500)->get();
        $users = \App\Models\User::where('status', 'Active')->orderBy('name')->get();

        return view('requirements.checklist', compact('client', 'requirements', 'stats', 'documents', 'users'));
    }

    public function link(Request $request, DocumentRequirement $requirement)
    {
        Gate::authorize('link', $requirement);
        $data = $request->validate(['document_id' => ['required', 'integer', 'exists:documents,id']]);
        $document = Document::findOrFail($data['document_id']);

        $this->assertAssociable($requirement, $document);

        DB::transaction(function () use ($requirement, $document, $request) {
            $requirement->documents()->attach($document->id, ['linked_by' => $request->user()->id]);
            Audit::record('requirement.linked', 'requirements', $requirement, 'Document '.$document->document_number.' linked to requirement: '.$requirement->name.'.');
        });

        return back()->with('success', 'Document linked to requirement.');
    }

    public function unlink(DocumentRequirement $requirement, Document $document)
    {
        Gate::authorize('link', $requirement);

        DB::transaction(function () use ($requirement, $document) {
            $requirement->documents()->detach($document->id);
            Audit::record('requirement.unlinked', 'requirements', $requirement, 'Document '.$document->document_number.' unlinked from requirement: '.$requirement->name.'.');
        });

        return back()->with('success', 'Document unlinked from requirement.');
    }

    private function assertAssociable(DocumentRequirement $requirement, Document $document): void
    {
        if ($document->client_id !== $requirement->client_id) {
            throw ValidationException::withMessages(['document_id' => 'The document and requirement must belong to the same client.']);
        }
        if ($document->requirements()->wherePivot('requirement_id', '!=', $requirement->id)->exists()) {
            throw ValidationException::withMessages(['document_id' => 'This document is already linked to another requirement.']);
        }
        if ($requirement->accounting_period_id) {
            $period = AccountingPeriod::find($requirement->accounting_period_id);
            $date = $document->received_date;
            $starts = $period ? \Illuminate\Support\Carbon::parse($period->starts_on) : null;
            $ends = $period ? \Illuminate\Support\Carbon::parse($period->ends_on) : null;
            if (! $period || ! $date || ! $starts || ! $ends || $date->lt($starts) || $date->gt($ends)) {
                $range = $period ? $period->label.': '.$starts->format('M j, Y').' to '.$ends->format('M j, Y') : 'the selected period';
                throw ValidationException::withMessages(['document_id' => 'The document received date must fall within the requirement’s accounting period ('.$range.').']);
            }
        }
    }

    private function validated(Request $request, bool $forCreate): array
    {
        $data = $request->validate($this->rules($forCreate));

        if (isset($data['client_id'])) {
            Access::client((int) $data['client_id']);
        }
        if (! empty($data['accounting_period_id'])) {
            $period = AccountingPeriod::find($data['accounting_period_id']);
            $clientId = isset($data['client_id']) ? (int) $data['client_id'] : null;
            if (! $period || ($clientId !== null && $period->client_id !== $clientId)) {
                throw ValidationException::withMessages(['accounting_period_id' => 'Choose an accounting period belonging to the selected client.']);
            }
        }

        return $data;
    }

    private function periodOptions(): array
    {
        return AccountingPeriod::with('client')->orderBy('client_id')->orderBy('ends_on', 'desc')->get()
            ->mapWithKeys(fn ($period) => [$period->id => $period->client->business_name.' — '.$period->label])
            ->all();
    }
}

