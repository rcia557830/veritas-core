<?php

namespace App\Http\Controllers;

use App\Models\DocumentFollowUp;
use App\Models\DocumentRequirement;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FollowUpController extends Controller
{
    private function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:Open,In Progress,Resolved'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')],
            'follow_up_date' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:30000'],
        ];
    }

    public function store(Request $request, DocumentRequirement $requirement)
    {
        Gate::authorize('create', [DocumentFollowUp::class, $requirement]);
        $followUp = DocumentFollowUp::create($request->validate($this->rules()) + [
            'requirement_id' => $requirement->id,
            'created_by' => $request->user()->id,
        ]);
        Audit::record('follow-up.created', 'requirements', $requirement, 'Follow-up added for requirement: '.$requirement->name.'.');

        return back()->with('success', 'Follow-up recorded.');
    }

    public function update(Request $request, DocumentFollowUp $followUp)
    {
        Gate::authorize('update', $followUp);
        $followUp->update($request->validate($this->rules()));
        Audit::record('follow-up.updated', 'requirements', $followUp->requirement, 'Follow-up updated for requirement: '.$followUp->requirement->name.'.');

        return back()->with('success', 'Follow-up updated.');
    }

    public function destroy(DocumentFollowUp $followUp)
    {
        Gate::authorize('delete', $followUp);
        $requirement = $followUp->requirement;
        $followUp->delete();
        Audit::record('follow-up.deleted', 'requirements', $requirement, 'Follow-up removed for requirement: '.$requirement->name.'.');

        return back()->with('success', 'Follow-up removed.');
    }
}
