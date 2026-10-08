<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, $record)
    {
        Gate::authorize('recordPayment', $record);
        $data = $request->validate(['payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'payment_method' => 'required|in:Cash,Bank Transfer,Cheque,GCash,Other', 'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($record, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($record->id);
            Gate::authorize('recordPayment', $invoice);
            abort_unless($invoice->status === 'Open', 409);
            if (Money::cents($data['amount']) > $invoice->balance_cents) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the remaining balance.']);
            }
            $invoice->payments()->create($data + ['recorded_by' => auth()->id()]);
            Audit::record('payment', 'billing', $invoice, 'Payment recorded: '.Money::format($data['amount']).'.');
        });

        return back()->with('success', 'Payment recorded successfully.');
    }
}
