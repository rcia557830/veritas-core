<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RoleDashboard
{
    public static function business(): array
    {
        $user = auth()->user();
        abort_unless($user->hasAnyRole(['owner', 'office-manager', 'bookkeeper']), 403);
        $experience = $user->role->slug;
        $cards = $tasks = $shortcuts = [];
        $can = fn ($model) => Gate::allows('viewAny', $model);
        $add = function ($module, $label, $query, $note, $permission = null) use (&$cards, $can) {
            if ($can(Modules::get($module)['model']) && (! $permission || Gate::allows($permission))) {
                $cards[] = ['label' => $label, 'value' => Records::query($module, Request::create('/', 'GET', $query))->count(), 'url' => route($module.'.index', $query), 'note' => $note];
            }
        };
        $onboarding = $can(Client::class) ? OnboardingReadiness::organization() : [];
        $compliance = $can(ComplianceRecord::class) ? Summary::compliance() : [];
        $add('clients', $experience === 'bookkeeper' ? 'Assigned Active Clients' : 'Active Clients', ['status' => 'Active'], $experience === 'bookkeeper' ? 'Assigned to you' : 'Across the practice');
        if ($experience === 'owner') {
            $add('documents', 'Documents Needing Attention', ['queue' => 'attention'], 'Submitted, under review or needing clarification');
            $add('ledger', 'Pending Journal Reviews', ['status' => 'For Review'], 'Practice-wide review workload');
        } elseif ($experience === 'office-manager') {
            $add('documents', 'Documents Awaiting Validation', ['queue' => 'validation'], 'Submitted or under review', 'document.validate');
            if ($onboarding) {
                $cards[] = ['label' => 'Pending Onboarding', 'value' => $onboarding['pending'], 'url' => route('clients.onboarding', ['state' => 'pending']), 'note' => 'Clients with onboarding in progress'];
            }
            $add('ledger', 'Pending Journal Reviews', ['work' => 'reviewable'], 'Prepared by another employee', 'bookkeeping.review');
        } else {
            $add('ledger', 'Draft Journals', ['work' => 'mine', 'status' => 'Draft'], 'Your drafts for assigned clients');
            $add('ledger', 'Journals Needing Correction', ['work' => 'mine', 'status' => 'Needs Correction'], 'Your returned journals');
            $add('documents', 'Documents Needing Attention', ['queue' => 'attention'], 'Assigned clients only');
        }
        if ($compliance) {
            $cards[] = ['label' => 'Upcoming Compliance Deadlines', 'value' => $compliance['filing_approaching'], 'url' => route('compliance.monitoring', ['deadline' => 'filing_approaching']), 'note' => 'Filing deadlines within '.DeadlineCalculator::filingApproachDays().' days'];
            if ($experience !== 'bookkeeper') {
                $cards[] = ['label' => 'Overdue Requirements', 'value' => $compliance['submission_overdue'], 'url' => route('compliance.monitoring', ['deadline' => 'submission_overdue']), 'note' => 'Client submissions past their internal deadline'];
            }
            $tasks[] = ['label' => 'Overdue filings', 'value' => $compliance['filing_overdue'], 'url' => route('compliance.monitoring', ['deadline' => 'filing_overdue'])];
        }
        if ($experience === 'owner' && $can(Invoice::class)) {
            $billing = Summary::billing(Access::query(Invoice::class));
            $cards[] = ['label' => 'Outstanding Billing', 'value' => Money::format($billing['outstanding']), 'url' => route('billing.index', ['queue' => 'outstanding']), 'note' => 'Unpaid balance on issued invoices'];
        }
        if ($experience === 'bookkeeper') {
            $add('ledger', 'Submitted Journals', ['work' => 'mine', 'status' => 'For Review'], 'Awaiting independent review');
        } elseif ($onboarding) {
            $tasks[] = ['label' => 'Pending onboarding', 'value' => $onboarding['pending'], 'url' => route('clients.onboarding', ['state' => 'pending'])];
            $tasks[] = ['label' => 'Ready for activation', 'value' => $onboarding['ready_for_activation'], 'url' => route('clients.onboarding', ['state' => 'ready_for_activation'])];
        }
        if ($can(LedgerEntry::class) && Gate::allows('bookkeeping.post')) {
            $tasks[] = ['label' => 'Reviewed awaiting posting', 'value' => Records::query('ledger', Request::create('/', 'GET', ['work' => 'postable']))->count(), 'url' => route('ledger.index', ['work' => 'postable'])];
        }
        if ($experience === 'bookkeeper') {
            foreach (['ledger' => 'Add journal', 'documents' => 'Add document'] as $module => $label) {
                if ($can(Modules::get($module)['model']) && Gate::allows('create', Modules::get($module)['model']) && ($module !== 'ledger' || Gate::allows('viewAny', Account::class))) {
                    $shortcuts[] = ['label' => $label, 'url' => route($module.'.create')];
                }
            }
        } elseif ($experience === 'owner') {
            foreach (['financialReports' => ['Financial Reports', 'financial-reports.index'], 'generalLedger' => ['Financial Audit Trail', 'financial-audit.index']] as $ability => [$label, $route]) {
                if (Gate::allows($ability, LedgerEntry::class)) {
                    $shortcuts[] = ['label' => $label, 'url' => route($route)];
                }
            }
        }
        $deadlines = $experience === 'owner' && $can(ComplianceRecord::class)
            ? Access::query(ComplianceRecord::class)->with('client')->whereNotIn('status', DeadlineCalculator::TERMINAL)->orderBy('due_date')->orderBy('id')->limit(5)->get() : collect();
        $documents = $experience === 'office-manager' && $can(Document::class) && Gate::allows('document.validate')
            ? Access::query(Document::class)->with('client')->whereIn('status', ['Submitted', 'Under Review'])->oldest()->orderBy('id')->limit(5)->get() : collect();
        $journals = $experience === 'bookkeeper' && $can(LedgerEntry::class)
            ? Access::query(LedgerEntry::class)->with('client')->where('created_by', $user->id)->whereIn('status', ['Draft', 'Needs Correction'])->oldest()->orderBy('id')->limit(5)->get() : collect();

        $actionItems = [];
        if ($can(LedgerEntry::class)) {
            if ($experience === 'bookkeeper') {
                $correctionCount = Records::query('ledger', Request::create('/', 'GET', ['work' => 'mine', 'status' => 'Needs Correction']))->count();
                if ($correctionCount > 0) {
                    $actionItems[] = [
                        'type' => 'journals',
                        'title' => 'Journals Needing Correction',
                        'count' => $correctionCount,
                        'description' => 'Returned entries requiring adjustments before resubmission',
                        'url' => route('ledger.index', ['work' => 'mine', 'status' => 'Needs Correction']),
                        'action_label' => 'Resolve returned journals',
                        'tone' => 'warning',
                        'icon' => 'journal-x',
                    ];
                }
            } elseif (Gate::allows('bookkeeping.review')) {
                $reviewableCount = Records::query('ledger', Request::create('/', 'GET', ['work' => 'reviewable']))->count();
                $actionItems[] = [
                    'type' => 'journals',
                    'title' => 'Journals Awaiting Review',
                    'count' => $reviewableCount,
                    'description' => 'Staff entries awaiting independent review and approval',
                    'url' => route('ledger.index', ['work' => 'reviewable']),
                    'action_label' => 'Review journals',
                    'tone' => $reviewableCount > 0 ? 'warning' : 'secondary',
                    'icon' => 'journal-check',
                ];
            }
        }
        if ($can(DocumentRequirement::class)) {
            $missingDocsCount = Access::query(DocumentRequirement::class)->where('is_active', true)->whereDoesntHave('documents')->count();
            $actionItems[] = [
                'type' => 'missing_documents',
                'title' => 'Missing Statutory Documents',
                'count' => $missingDocsCount,
                'description' => 'Client document requirements with no source attachments',
                'url' => route('requirements.monitoring', ['state' => 'missing']),
                'action_label' => 'Track missing documents',
                'tone' => $missingDocsCount > 0 ? 'danger' : 'secondary',
                'icon' => 'folder-x',
            ];
        }
        if ($can(ComplianceRecord::class)) {
            $upcomingCount = $compliance['filing_approaching'] ?? 0;
            $actionItems[] = [
                'type' => 'deadlines',
                'title' => 'Upcoming Compliance Deadlines',
                'count' => $upcomingCount,
                'description' => 'Statutory filing due dates approaching in the next 15 days',
                'url' => route('compliance.monitoring', ['deadline' => 'filing_approaching']),
                'action_label' => 'View upcoming deadlines',
                'tone' => $upcomingCount > 0 ? 'info' : 'secondary',
                'icon' => 'calendar-event',
            ];
        }
        if ($experience === 'owner' && $can(Invoice::class)) {
            $billingSummary = Summary::billing(Access::query(Invoice::class));
            $unpaidCount = $billingSummary['open'];
            $unpaidBalance = Money::format($billingSummary['outstanding']);
            $actionItems[] = [
                'type' => 'billing',
                'title' => 'Unpaid Invoices',
                'count' => $unpaidCount,
                'amount' => $unpaidBalance,
                'description' => 'Issued client invoices awaiting balance collection',
                'url' => route('billing.index', ['queue' => 'outstanding']),
                'action_label' => 'Manage unpaid invoices',
                'tone' => $unpaidCount > 0 ? 'warning' : 'secondary',
                'icon' => 'receipt',
            ];
        }

        $reviewableJournals = ($can(LedgerEntry::class) && Gate::allows('bookkeeping.review'))
            ? Access::query(LedgerEntry::class)->with(['client', 'creator'])->where('status', 'For Review')->where('created_by', '!=', $user->id)->oldest()->limit(5)->get()
            : collect();
        $unpaidInvoices = ($experience === 'owner' && $can(Invoice::class))
            ? Access::query(Invoice::class)->with(['client', 'items', 'payments'])->whereNotIn('status', ['Draft', 'Cancelled'])->orderBy('due_date')->get()->filter(fn ($inv) => $inv->balance_cents > 0)->take(5)
            : collect();

        $description = match ($experience) {
            'owner' => 'Business oversight: clients, finances and upcoming obligations.',
            'office-manager' => 'Daily operations: validate documents, complete onboarding and review accounting work.',
            'bookkeeper' => 'Your assigned work: prepare journals, resolve corrections and track client documents.',
        };
        $role = $user->role->name;

        return compact('experience', 'description', 'role', 'cards', 'tasks', 'shortcuts', 'deadlines', 'documents', 'journals', 'actionItems', 'reviewableJournals', 'unpaidInvoices');
    }
}
