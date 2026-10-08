<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Summary;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $stats = Summary::dashboard();
        $deadlines = Access::query(ComplianceRecord::class)->with('client')->where('status', '!=', 'Filed')->orderBy('due_date')->limit(6)->get();
        $clients = Access::query(Client::class)->latest()->limit(5)->get();
        $documents = Access::query(Document::class)->with('client')->latest()->limit(5)->get();
        $invoices = Access::query(Invoice::class)->with(['client', 'items', 'payments'])->latest()->limit(5)->get();
        $activity = Audit::visible()->with('user')->latest()->limit(8)->get();

        $role = auth()->user()->role->name;
        $userCount = auth()->user()->hasRole('owner') && auth()->user()->hasPermission('user.view') ? User::count() : null;
        $noticeCount = auth()->user()->hasPermission('notice.view') ? Access::query(Notice::class)->where('status', 'Published')->count() : null;

        return view('dashboard.index', compact('stats', 'deadlines', 'clients', 'documents', 'invoices', 'activity', 'role', 'userCount', 'noticeCount'));
    }
}
