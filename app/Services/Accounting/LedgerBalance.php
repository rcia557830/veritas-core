<?php

namespace App\Services\Accounting;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** All values are signed integer cents; debit-positive, never classification-normalized. */
final class LedgerBalance
{
    public int $opening = 0;

    public int $debits = 0;

    public int $credits = 0;

    public int $movement = 0;

    public int $closing = 0;

    public function apply(int $debit, int $credit, bool $beforeStart): void
    {
        if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
            throw ValidationException::withMessages(['ledger' => 'A posted journal line contains invalid amounts. Ledger calculation stopped.']);
        }
        $net = Money::subtractCents($debit, $credit);
        $opening = $this->opening;
        $debits = $this->debits;
        $credits = $this->credits;
        $movement = $this->movement;
        if ($beforeStart) {
            $opening = Money::addCents($opening, $net);
        } else {
            $debits = Money::addCents($debits, $debit);
            $credits = Money::addCents($credits, $credit);
            $movement = Money::subtractCents($debits, $credits);
        }
        $closing = Money::addCents($opening, $movement);
        // Commit the accumulator only after every bound check succeeds.
        $this->opening = $opening;
        $this->debits = $debits;
        $this->credits = $credits;
        $this->movement = $movement;
        $this->closing = $closing;
    }
}
