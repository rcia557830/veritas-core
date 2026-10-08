<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Records;
use App\Services\Summary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceController extends ModuleController
{
    protected string $module = 'billing';

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Invoice::class);
        // All authorized invoices, deliberately independent of table query parameters.
        $billingSummary = Summary::billing(Access::query(Invoice::class));
        $records = Records::query('billing', $request)->paginate(Records::pageSize($request))->withQueryString();
        $clients = Access::query(Client::class)->orderBy('business_name')->limit(500)->get();

        return view('records.index', $this->context() + compact('billingSummary', 'records', 'clients'));
    }

    public function transition(Request $request, $record)
    {
        Gate::authorize('view', $record);
        $data = $request->validate(['action' => 'required|in:issue,cancel']);
        DB::transaction(function () use ($record, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($record->id);
            Gate::authorize($data['action'] === 'issue' ? 'issue' : 'cancel', $invoice);
            if ($data['action'] === 'issue') {
                abort_unless($invoice->status === 'Draft' && $invoice->total_cents > 0, 409);
                $invoice->status = 'Open';
            } else {
                if ($invoice->payments()->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Paid invoices cannot be cancelled.']);
                }abort_if($invoice->status === 'Cancelled', 409);
                $invoice->status = 'Cancelled';
            }
            $invoice->save();
            Audit::record($data['action'], 'billing', $invoice, 'Invoice '.$invoice->status.'.');
        });

        return back()->with('success', 'Invoice status updated.');
    }

    public function print($record)
    {
        Gate::authorize('view', $record);

        return view('billing.print', ['record' => $record]);
    }

    public function pdf($record)
    {
        Gate::authorize('view', $record);

        return Pdf::loadView('billing.print', ['record' => $record, 'pdf' => true])->download($record->invoice_number.'.pdf');
    }
}
