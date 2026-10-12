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
        $data += $request->validate(['submission_token' => 'nullable|uuid']);
        DB::transaction(function () use ($record, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($record->id);
            Gate::authorize('recordPayment', $invoice);
            abort_unless($invoice->status === 'Open', 409);
            if (! empty($data['submission_token']) && $invoice->payments()->where('submission_token', $data['submission_token'])->exists()) {
                throw ValidationException::withMessages(['amount' => 'This payment submission has already been recorded.']);
            }
            if (! empty($data['reference_number']) && $invoice->payments()->where('reference_number', $data['reference_number'])->exists()) {
                throw ValidationException::withMessages(['reference_number' => 'This payment reference is already recorded for this invoice.']);
            }
            if (empty($data['submission_token']) && empty($data['reference_number']) && $invoice->payments()->whereDate('payment_date', $data['payment_date'])->where('amount', $data['amount'])->where('payment_method', $data['payment_method'])->exists()) {
                throw ValidationException::withMessages(['amount' => 'An identical payment is already recorded. Use a new payment form or a distinct receipt reference for a separate payment.']);
            }
            if (Money::cents($data['amount']) > $invoice->balance_cents) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the remaining balance.']);
            }
            $invoice->payments()->create($data + ['recorded_by' => auth()->id()]);
            Audit::record('payment', 'billing', $invoice, 'Payment recorded: '.Money::format($data['amount']).'.');
        });

        return back()->with('success', 'Payment recorded successfully.');
    }
}
