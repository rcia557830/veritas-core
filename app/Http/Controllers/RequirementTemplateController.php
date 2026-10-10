<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\DocumentRequirementTemplate;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RequirementTemplateController extends Controller
{
    private function types(): array
    {
        return Modules::get('documents')['fields']['document_type'][1];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', $this->types())],
            'description' => ['nullable', 'string', 'max:30000'],
            'is_required' => ['nullable', 'boolean'],
            'default_due_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function index()
    {
        Gate::authorize('viewAny', DocumentRequirementTemplate::class);
        $templates = DocumentRequirementTemplate::orderBy('name')->get();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();

        return view('requirements.templates', compact('templates', 'clients') + ['types' => $this->types()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', DocumentRequirementTemplate::class);
        $template = DocumentRequirementTemplate::create($request->validate($this->rules()));
        Audit::record('created', 'requirements', null, 'Document requirement template created: '.$template->name.'.');

        return redirect()->route('requirements.templates.index')->with('success', 'Template created.');
    }

    public function update(Request $request, DocumentRequirementTemplate $template)
    {
        Gate::authorize('update', $template);
        $template->update($request->validate($this->rules()));
        Audit::record('updated', 'requirements', null, 'Document requirement template updated: '.$template->name.'.');

        return redirect()->route('requirements.templates.index')->with('success', 'Template updated.');
    }

    public function destroy(DocumentRequirementTemplate $template)
    {
        Gate::authorize('delete', $template);
        Audit::record('archived', 'requirements', null, 'Document requirement template archived: '.$template->name.'.');
        $template->delete();

        return redirect()->route('requirements.templates.index')->with('success', 'Template archived.');
    }

    public function apply(Request $request, DocumentRequirementTemplate $template)
    {
        Gate::authorize('apply', $template);
        $data = $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id']]);
        $client = Access::client((int) $data['client_id']);

        $requirement = DB::transaction(function () use ($template, $client, $request) {
            $requirement = DocumentRequirement::create([
                'client_id' => $client->id,
                'template_id' => $template->id,
                'name' => $template->name,
                'type' => $template->type,
                'description' => $template->description,
                'is_required' => $template->is_required,
                'is_active' => true,
                'due_date' => $template->default_due_days !== null ? today()->addDays($template->default_due_days) : null,
                'created_by' => $request->user()->id,
            ]);
            Audit::record('requirement.template-applied', 'requirements', $requirement, 'Requirement created from template '.$template->name.' for '.$client->business_name.'.');

            return $requirement;
        });

        return redirect()->route('requirements.checklist', $client->id)
            ->with('success', 'Requirement initialized from template.');
    }
}
