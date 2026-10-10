<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ComplianceFollowUp;
use App\Models\ComplianceRecord;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\DeadlineCalculator;
use App\Services\DocumentCompleteness;
use App\Services\Summary;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceController extends ModuleController
{
    protected string $module = 'compliance';

    public function monitor(Request $request)
    {
        Gate::authorize('viewAny', ComplianceRecord::class);
        $request->validate([
            'q' => 'nullable|string|max:150',
            'agency' => 'nullable|string|max:255',
            'reporting_period' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:60',
            'assigned_to' => 'nullable|integer|min:1',
            'client_id' => 'nullable|integer|min:1',
            'deadline' => 'nullable|in:all,submission_approaching,submission_overdue,filing_approaching,filing_overdue',
        ]);

        $summary = Summary::compliance();
        $query = Access::query(ComplianceRecord::class)->with(['client', 'assignee']);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('requirement', 'like', '%'.$search.'%')
                    ->orWhere('agency', 'like', '%'.$search.'%')
                    ->orWhere('reporting_period', 'like', '%'.$search.'%')
                    ->orWhere('reference_number', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', '%'.$search.'%'));
            });
        }
        if ($agency = $request->query('agency')) {
            $query->where('agency', $agency);
        }
        if ($period = $request->query('reporting_period')) {
            $query->where('reporting_period', $period);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($clientId = $request->integer('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($request->user()->hasPermission('compliance.assign') && ($assigned = $request->integer('assigned_to'))) {
            $query->where('assigned_to', $assigned);
        }

        $records = $query->orderBy('due_date')->orderBy('id')->paginate(15)->withQueryString();

        $deadline = $request->query('deadline');
        if ($deadline && $deadline !== 'all') {
            $filtered = $records->getCollection()->filter(fn ($r) => match ($deadline) {
                'submission_approaching' => DeadlineCalculator::isSubmissionApproaching($r),
                'submission_overdue' => DeadlineCalculator::isSubmissionOverdue($r),
                'filing_approaching' => DeadlineCalculator::isFilingApproaching($r),
                'filing_overdue' => DeadlineCalculator::isFilingOverdue($r),
                default => true,
            })->values();
            $records->setCollection($filtered);
        }

        $agencies = Modules::get('compliance')['fields']['agency'][1];
        $periods = Access::query(ComplianceRecord::class)->whereNotNull('reporting_period')->distinct()->orderBy('reporting_period')->pluck('reporting_period');
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $users = $request->user()->hasPermission('compliance.assign') ? User::where('status', 'Active')->orderBy('name')->get() : collect();

        return view('compliance.monitor', compact('summary', 'records', 'agencies', 'periods', 'clients', 'users'));
    }

    public function checklist(Client $client)
    {
        Gate::authorize('view', $client);
        Gate::authorize('viewAny', ComplianceRecord::class);
        $records = Access::query(ComplianceRecord::class)
            ->where('client_id', $client->id)
            ->with(['assignee', 'followUps.assignee', 'followUps.creator'])
            ->orderBy('due_date')
            ->get();
        $docStats = DocumentCompleteness::forClient($client);
        $users = User::where('status', 'Active')->orderBy('name')->get();

        return view('compliance.checklist', compact('client', 'records', 'docStats', 'users'));
    }

    public function storeFollowUp(Request $request, ComplianceRecord $record)
    {
        Gate::authorize('create', [ComplianceFollowUp::class, $record]);
        $data = $this->followUpData($request, $record);
        ComplianceFollowUp::create($data + [
            'compliance_record_id' => $record->id,
            'created_by' => $request->user()->id,
        ]);
        Audit::record('follow-up.created', 'compliance', $record, 'Follow-up added for compliance requirement: '.$record->requirement.'.');

        return back()->with('success', 'Follow-up recorded.');
    }

    public function updateFollowUp(Request $request, ComplianceFollowUp $followUp)
    {
        Gate::authorize('update', $followUp);
        $followUp->update($this->followUpData($request, $followUp->complianceRecord));
        Audit::record('follow-up.updated', 'compliance', $followUp->complianceRecord, 'Follow-up updated for compliance requirement: '.$followUp->complianceRecord->requirement.'.');

        return back()->with('success', 'Follow-up updated.');
    }

    public function destroyFollowUp(ComplianceFollowUp $followUp)
    {
        Gate::authorize('delete', $followUp);
        $record = $followUp->complianceRecord;
        $followUp->delete();
        Audit::record('follow-up.deleted', 'compliance', $record, 'Follow-up removed for compliance requirement: '.$record->requirement.'.');

        return back()->with('success', 'Follow-up removed.');
    }

    private function followUpData(Request $request, ComplianceRecord $record): array
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:Open,In Progress,Resolved'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'Active')],
            'follow_up_date' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:30000'],
        ]);

        if (! empty($data['assigned_to'])) {
            $assignee = User::findOrFail($data['assigned_to']);
            if (! $assignee->hasAnyRole(['owner', 'office-manager']) && $record->client->assigned_to !== $assignee->id) {
                throw ValidationException::withMessages(['assigned_to' => 'Assign this follow-up to the client’s assigned bookkeeper, Office Manager or Owner.']);
            }
        }

        $data['completed_at'] = ($data['status'] === 'Resolved') ? now() : null;

        return $data;
    }
}
