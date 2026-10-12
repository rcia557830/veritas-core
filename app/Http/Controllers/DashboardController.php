<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Access;
use App\Services\Summary;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke()
    {
        if (auth()->user()->hasRole('super-admin')) {
            $cards = [];
            if (Gate::allows('viewAny', User::class)) {
                foreach (['Active' => 'Active employees', 'Pending Invitation' => 'Pending invitations', 'Inactive' => 'Inactive accounts'] as $status => $label) {
                    $cards[] = ['label' => $label, 'value' => User::where('status', $status)->count(), 'url' => route('admin.users.index', ['status' => $status]), 'note' => 'Employee access'];
                }
                $cards[] = ['label' => 'Roles and Permissions', 'value' => Role::count(), 'url' => route('admin.roles.index'), 'note' => 'Review access boundaries'];
            }

            return view('admin.overview', compact('cards'));
        }
        $stats = Summary::dashboard();
        $cards = [];
        $add = function ($model, $label, $value, $route, $query, $note) use (&$cards) {
            if (Gate::allows('viewAny', $model)) {
                $cards[] = compact('label', 'value', 'note') + ['url' => route($route, $query)];
            }
        };
        $add(Client::class, 'Active Clients', $stats['active'], 'clients.index', ['status' => 'Active'], auth()->user()->hasRole('bookkeeper') ? 'Assigned to you' : 'Across the practice');
        $add(Document::class, 'Documents Needing Attention', $stats['pending'] + $stats['review'] + $stats['clarification'], 'documents.index', ['queue' => 'attention'], 'Submitted, under review or needing clarification');
        $add(LedgerEntry::class, 'Pending Journal Reviews', $stats['ledger'], 'ledger.index', ['status' => 'For Review'], Gate::allows('bookkeeping.review') ? 'Ready for your review' : 'Track submitted journals');
        $add(ComplianceRecord::class, 'Upcoming Compliance Deadlines', $stats['upcoming'], 'compliance.monitoring', ['deadline' => 'filing_approaching'], 'Filing deadlines within 10 days');
        $add(ComplianceRecord::class, 'Overdue Requirements', $stats['compliance']['submission_overdue'], 'compliance.monitoring', ['deadline' => 'submission_overdue'], 'Client submissions past their internal deadline');
        $add(Invoice::class, 'Outstanding Billing', Money::format($stats['billing']['outstanding']), 'billing.index', ['queue' => 'outstanding'], 'Unpaid balance on issued invoices');
        $deadlines = Gate::allows('viewAny', ComplianceRecord::class)
            ? Access::query(ComplianceRecord::class)->with('client')->whereNotIn('status', ['Filed', 'Completed'])->orderBy('due_date')->limit(5)->get()
            : collect();
        $role = auth()->user()->role->name;

        return view('dashboard.index', compact('stats', 'cards', 'deadlines', 'role'));
    }
}
