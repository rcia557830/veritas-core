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
use App\Services\Records;
use App\Services\Summary;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceController extends ModuleController
{
    protected string $module = 'compliance';

    public function index(Request $request)
    {
        return $this->monitor($request);
    }

    public function show($record)
    {
        Gate::authorize('view', $record);
        $record->load(['followUps.assignee', 'followUps.creator']);
        $users = User::where('status', 'Active')->orderBy('name')->get();

        return view('records.show', $this->context() + compact('record', 'users'));
    }

    public function monitor(Request $request)
    {
        Gate::authorize('viewAny', ComplianceRecord::class);
        $request->validate([
            'view' => 'nullable|in:upcoming,overdue,completed',
            'type' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'follow_up' => 'nullable|in:open',
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

        if ($request->query('follow_up') === 'open') {
            $query->whereHas('followUps', fn ($q) => $q->whereIn('status', ['Open', 'In Progress']));
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('requirement', 'like', '%'.$search.'%')
                    ->orWhere('agency', 'like', '%'.$search.'%')
                    ->orWhere('reporting_period', 'like', '%'.$search.'%')
                    ->orWhere('reference_number', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', '%'.$search.'%'));
            });
        }
        if ($agency = $request->query('agency', $request->query('type'))) {
            $query->where('agency', $agency);
        }
        if ($period = $request->query('reporting_period')) {
            $query->where('reporting_period', $period);
        }
        if ($status = $request->query('status')) {
            if ($status === 'Overdue') {
                $query->whereNotIn('status', DeadlineCalculator::TERMINAL)->whereDate('due_date', '<', DeadlineCalculator::today());
            } else {
                $query->where('status', $status);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($request->filled($key)) {
                $query->whereDate('due_date', $operator, $request->query($key));
            }
        }
        if ($request->query('view') === 'completed') {
            $query->whereIn('status', DeadlineCalculator::TERMINAL);
        }
        if ($clientId = $request->integer('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($request->user()->hasPermission('compliance.assign') && ($assigned = $request->integer('assigned_to'))) {
            $query->where('assigned_to', $assigned);
        }

        $perPage = Records::pageSize($request);
        $query->orderBy('due_date')->orderBy('id');
        $deadline = $request->query('deadline');
        if (($deadline && $deadline !== 'all') || in_array($request->query('view'), ['upcoming', 'overdue'])) {
            // Legacy deadlines may be derived using calendar or business days.
            // Apply the same predicates as the dashboard before counting pages,
            // retaining only this page in memory.
            $page = $request->integer('page', 1);
            $matches = 0;
            $items = collect();
            foreach ($query->lazy(500) as $r) {
                $matchesDeadline = match ($deadline) {
                    'submission_approaching' => DeadlineCalculator::isSubmissionApproaching($r),
                    'submission_overdue' => DeadlineCalculator::isSubmissionOverdue($r),
                    'filing_approaching' => DeadlineCalculator::isFilingApproaching($r),
                    'filing_overdue' => DeadlineCalculator::isFilingOverdue($r),
                    default => true,
                };
                $overdue = DeadlineCalculator::isSubmissionOverdue($r) || DeadlineCalculator::isFilingOverdue($r);
                $matchesView = match ($request->query('view')) {
                    'upcoming' => ! $overdue && (DeadlineCalculator::isSubmissionApproaching($r) || DeadlineCalculator::isFilingApproaching($r)),
                    'overdue' => $overdue,
                    default => true,
                };
                if ($matchesDeadline && $matchesView) {
                    if ($matches >= ($page - 1) * $perPage && $items->count() < $perPage) {
                        $items->push($r);
                    }
                    $matches++;
                }
            }
            $records = new LengthAwarePaginator($items, $matches, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        } else {
            $records = $query->paginate($perPage)->withQueryString();
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
