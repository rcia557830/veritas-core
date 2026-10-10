<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Services\Access;
use App\Services\DocumentCompleteness;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class MissingDocumentsController extends Controller
{
    private function types(): array
    {
        return Modules::get('documents')['fields']['document_type'][1];
    }

    private function friendlyState(DocumentRequirement $requirement): string
    {
        return [
            DocumentCompleteness::STATE_NOT_SUBMITTED => 'missing',
            DocumentCompleteness::STATE_AWAITING => 'awaiting',
            DocumentCompleteness::STATE_INCOMPLETE => 'incomplete',
            DocumentCompleteness::STATE_CLARIFICATION => 'clarification',
            DocumentCompleteness::STATE_VERIFIED => 'verified',
        ][DocumentCompleteness::stateOf($requirement)];
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', DocumentRequirement::class);
        $request->validate([
            'q' => 'nullable|string|max:150',
            'accounting_period_id' => 'nullable|integer|min:1',
            'type' => 'nullable|string|max:255',
            'state' => 'nullable|in:missing,awaiting,incomplete,clarification,verified',
            'due' => 'nullable|in:all,overdue,today,soon',
        ]);

        $query = Access::query(DocumentRequirement::class)
            ->with(['client', 'accountingPeriod', 'documents'])
            ->where('is_active', true);

        if ($search = trim((string) $request->query('q'))) {
            $query->whereHas('client', fn ($c) => $c
                ->where('business_name', 'like', '%'.$search.'%')
                ->orWhere('client_code', 'like', '%'.$search.'%'));
        }
        if ($periodId = $request->integer('accounting_period_id')) {
            $query->where('accounting_period_id', $periodId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $requirements = $query->orderBy('due_date')->orderBy('client_id')->get();

        $summary = ['total' => $requirements->count(), 'missing' => 0, 'awaiting' => 0, 'incomplete' => 0, 'clarification' => 0, 'verified' => 0];
        foreach ($requirements as $requirement) {
            $summary[$this->friendlyState($requirement)]++;
        }

        if ($stateFilter = $request->query('state')) {
            $requirements = $requirements->filter(fn ($r) => $this->friendlyState($r) === $stateFilter)->values();
        }

        $due = $request->query('due');
        if ($due === 'overdue') {
            $requirements = $requirements->filter(fn ($r) => $r->due_date && $r->due_date->lt(today()))->values();
        } elseif ($due === 'today') {
            $requirements = $requirements->filter(fn ($r) => $r->due_date && $r->due_date->isToday())->values();
        } elseif ($due === 'soon') {
            $requirements = $requirements->filter(fn ($r) => $r->due_date && $r->due_date->between(today(), today()->addDays(10)))->values();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 15;
        $records = new LengthAwarePaginator(
            $requirements->forPage($page, $perPage),
            $requirements->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();
        $periods = AccountingPeriod::with('client')
            ->whereIn('client_id', Access::query(Client::class)->select('id'))
            ->orderBy('ends_on', 'desc')->limit(1000)->get();

        return view('requirements.monitoring', compact('records', 'clients', 'periods', 'summary') + ['types' => $this->types()]);
    }
}
